import { onBeforeUnmount, ref } from 'vue';

import { apiHeaders, requestJson } from '@/lib/api';

const DEBOUNCE_MS = 250;
const MIN_GAP_MS = 300;
const CACHE_MS = 30000;
const CACHE_ENTRIES = 50;
const REQUEST_TIMEOUT_MS = 8000;
const RETRY_AFTER_DEFAULT_MS = 2000;
const FAILED = 'The event list could not be loaded. You can still type an event name and press Enter.';
const TIMED_OUT = 'The event list is taking too long. You can still type an event name and press Enter.';

/**
 * The event names of the picker: `GET {eventsUrl}[?q=text]`, `{events: [{name, count}], truncated}`.
 *
 * `search(q)` is debounced; a newer search aborts the older request and only the latest may update the state; an answer
 * is reused for 30 s (50 entries at most, least recently used out; the server also caches it, but a request is also a
 * throttle hit); at most one request per 300 ms; every request has a time limit (8 s); a 429 (busy or throttled) waits for
 * Retry-After and tries once more, then shows the server's sentence. The text sent is cut to `maxSearchLength`
 * characters (code points): the API refuses more, and a longer name typed by hand is still found by its beginning.
 * `status` is `idle`, `loading`, `ready` or `error` (`errorMessage` is a fixed sentence: the server's own, or ours). The
 * previous list stays while a new one loads.
 *
 * @param {string} eventsUrl built by the server
 * @param {number} maxSearchLength the API's limit for `q`
 */
export function useEventNames(eventsUrl, maxSearchLength = 100) {
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

    function cached(query) {
        const entry = cache.get(query);
        if (entry === undefined || Date.now() - entry.at >= CACHE_MS) {
            cache.delete(query);

            return undefined;
        }

        // most recently used goes last
        cache.delete(query);
        cache.set(query, entry);

        return entry.answer;
    }

    function remember(query, answer) {
        cache.delete(query);
        cache.set(query, { at: Date.now(), answer });
        while (cache.size > CACHE_ENTRIES) {
            cache.delete(cache.keys().next().value);
        }
    }

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

    function fail(message) {
        errorMessage.value = message;
        status.value = 'error';
    }

    const pause = (ms) => new Promise((resolve) => (retryTimer = setTimeout(resolve, ms)));

    async function load(query, mine, retried = false) {
        const hit = cached(query);
        if (hit !== undefined) {
            apply(query, hit);

            return;
        }

        // At most one request per MIN_GAP_MS, however fast someone types.
        const wait = lastRequestAt + MIN_GAP_MS - Date.now();
        if (wait > 0) {
            await pause(wait);
            if (mine !== seq) {
                return;
            }
        }

        lastRequestAt = Date.now();
        status.value = 'loading';
        controller = new AbortController();

        const answer = await requestJson({
            method: 'GET',
            url: query === '' ? eventsUrl : `${eventsUrl}?q=${encodeURIComponent(query)}`,
            signal: controller.signal,
            timeoutMs: REQUEST_TIMEOUT_MS,
            headers: apiHeaders(),
        });

        if (mine !== seq || answer === 'aborted') {
            return;
        }
        if (answer === 'timeout') {
            return fail(TIMED_OUT);
        }
        if (answer === 'network') {
            return fail(FAILED);
        }

        const { status: code, json: body } = answer;

        if (code === 200 && Array.isArray(body?.events)) {
            const result = { events: body.events.filter((item) => typeof item?.name === 'string' && Number.isInteger(item.count)), truncated: body.truncated === true };
            remember(query, result);
            apply(query, result);

            return;
        }

        if (code === 429 && !retried) {
            await pause(answer.retryAfter ?? RETRY_AFTER_DEFAULT_MS);
            if (mine === seq) {
                await load(query, mine, true);
            }

            return;
        }

        fail(typeof body?.error?.message === 'string' && body.error.message !== '' ? body.error.message.slice(0, 300) : FAILED);
    }

    /** Ask for the names containing `query` (after the debounce); `immediate` skips the debounce (opening the list). */
    function search(query, { immediate = false } = {}) {
        const text = [...query.trim()].slice(0, maxSearchLength).join('');
        seq++;
        stop();
        const mine = seq;

        const hit = cached(text);
        if (hit !== undefined) {
            apply(text, hit);

            return;
        }

        status.value = 'loading';
        debounceTimer = setTimeout(() => load(text, mine), immediate ? 0 : DEBOUNCE_MS);
    }

    return { events, truncated, status, errorMessage, answeredFor, search };
}
