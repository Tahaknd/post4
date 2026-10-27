import { onBeforeUnmount, ref } from 'vue';

import { readXsrfToken, retryAfterMs } from '@/lib/insightRunClient';

const DEBOUNCE_MS = 250;
const MIN_GAP_MS = 300;
const CACHE_MS = 30000;
const RETRY_AFTER_DEFAULT_MS = 2000;

/**
 * The event names of the picker: `GET {eventsUrl}[?q=text]`, `{events: [{name, count}], truncated}`.
 *
 * `search(q)` is debounced; a newer search aborts the older request and only the latest may update the state; an
 * answer is reused for 30 s (the server also caches it, but a request is also a throttle hit); at most one request per
 * 300 ms; a 429 (busy or throttled) waits for Retry-After and tries once more, then shows the server's sentence.
 * `status` is `idle`, `loading`, `ready` or `error` (`errorMessage` is a fixed sentence: the server's own, or ours for
 * a dropped connection). The previous list stays while a new one loads.
 *
 * @param {string} eventsUrl built by the server
 */
export function useEventNames(eventsUrl) {
    const events = ref([]);
    const truncated = ref(false);
    const status = ref('idle');
    const errorMessage = ref('');
    /** The text of the answer that `events` belongs to. */
    const answeredFor = ref(null);

    let seq = 0;
    let controller = null;
    let debounceTimer = null;
    let retryTimer = null;
    let lastRequestAt = 0;
    const cache = new Map();

    function stop() {
        clearTimeout(debounceTimer);
        clearTimeout(retryTimer);
        controller?.abort();
        controller = null;
    }

    onBeforeUnmount(() => {
        seq++;
        stop();
    });

    function apply(query, answer) {
        events.value = answer.events;
        truncated.value = answer.truncated;
        answeredFor.value = query;
        errorMessage.value = '';
        status.value = 'ready';
    }

    async function load(query, mine, retried = false) {
        const cached = cache.get(query);
        if (cached !== undefined && Date.now() - cached.at < CACHE_MS) {
            apply(query, cached.answer);

            return;
        }

        // At most one request per MIN_GAP_MS, however fast someone types.
        const wait = lastRequestAt + MIN_GAP_MS - Date.now();
        if (wait > 0) {
            await new Promise((resolve) => (retryTimer = setTimeout(resolve, wait)));
            if (mine !== seq) {
                return;
            }
        }

        lastRequestAt = Date.now();
        status.value = 'loading';
        controller = new AbortController();
        const { signal } = controller;

        let response;
        try {
            response = await fetch(query === '' ? eventsUrl : `${eventsUrl}?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(readXsrfToken() === '' ? {} : { 'X-XSRF-TOKEN': readXsrfToken() }) },
                credentials: 'same-origin',
                signal,
            });
        } catch (error) {
            if (mine === seq && error?.name !== 'AbortError') {
                fail('The event list could not be loaded. You can still type an event name and press Enter.');
            }

            return;
        }

        let body = null;
        try {
            body = await response.json();
        } catch {
            body = null;
        }
        if (mine !== seq) {
            return;
        }

        if (response.ok && Array.isArray(body?.events)) {
            const answer = { events: body.events.filter((item) => typeof item?.name === 'string' && Number.isInteger(item.count)), truncated: body.truncated === true };
            cache.set(query, { at: Date.now(), answer });
            apply(query, answer);

            return;
        }

        if (response.status === 429 && !retried) {
            const delay = retryAfterMs(response.headers.get('Retry-After')) ?? RETRY_AFTER_DEFAULT_MS;
            await new Promise((resolve) => (retryTimer = setTimeout(resolve, delay)));
            if (mine === seq) {
                await load(query, mine, true);
            }

            return;
        }

        const message = typeof body?.error?.message === 'string' && body.error.message !== '' ? body.error.message.slice(0, 300) : 'The event list could not be loaded. You can still type an event name and press Enter.';
        fail(message);
    }

    function fail(message) {
        errorMessage.value = message;
        status.value = 'error';
    }

    /** Ask for the names containing `query` (after the debounce); `immediate` skips the debounce (opening the list). */
    function search(query, { immediate = false } = {}) {
        const text = query.trim();
        seq++;
        stop();
        const mine = seq;

        if (cache.has(text) && Date.now() - cache.get(text).at < CACHE_MS) {
            apply(text, cache.get(text).answer);

            return;
        }

        status.value = 'loading';
        debounceTimer = setTimeout(() => load(text, mine), immediate ? 0 : DEBOUNCE_MS);
    }

    return { events, truncated, status, errorMessage, answeredFor, search };
}
