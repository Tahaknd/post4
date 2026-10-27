// The client of the asynchronous insight API (POST a definition, poll the run), without any framework: the Vue
// composable `useInsightRun` is a thin wrapper, which is also what makes the timing rules testable with a fake clock
// (tests/js/insightRunClient.test.mjs).
//
// It implements the contract of Task 3.6 exactly:
//   - changing the definition is debounced (DEBOUNCE_MS); every request has an increasing sequence number and only the
//     LATEST one may ever change the state; a superseded run that is queued or running is cancelled with DELETE
//     (fire and forget, errors ignored) so its capacity is freed at once;
//   - POST: 200 {status: done} is the result straight from the server's cache, 202 {run_id, poll_url} is a queued run;
//     at most one POST per MIN_POST_GAP_MS (the budget is 60 per minute);
//   - poll the `poll_url` every POLL_FAST_MS, backing off to POLL_SLOW_MS after FAST_WINDOW_MS; stop at done, failed,
//     cancelled, or a 404 (the run expired or is not ours: offer to run again); give up after TIMEOUT_MS (and cancel);
//   - 429 and 503 honour Retry-After (bounded automatic retries, then the error is shown);
//   - the XSRF token comes from the XSRF-TOKEN cookie (decoded) as X-XSRF-TOKEN, same-origin credentials, JSON in and out;
//   - server error bodies `{message, error: {kind, message, retryable, retry_after_seconds}}` are shown VERBATIM (the
//     messages are fixed sentences chosen by the server); nothing else from a response body is ever displayed.

export const DEBOUNCE_MS = 400;
export const MIN_POST_GAP_MS = 1000;
export const POLL_FAST_MS = 1000;
export const POLL_SLOW_MS = 2500;
export const FAST_WINDOW_MS = 10000;
export const TIMEOUT_MS = 60000;
export const MAX_BUSY_RETRIES = 3;
export const MAX_NETWORK_FAILURES = 3;
const DEFAULT_RETRY_AFTER_MS = 3000;
const MAX_RETRY_AFTER_MS = 30000;
const MESSAGE_MAX = 300;
const RUN_ID_PATTERN = /^[0-9a-f]{32}$/;

const CLIENT_ERRORS = {
    network: { kind: 'network', message: 'The server could not be reached. Check your connection and try again.', retryable: true, retry_after_seconds: null },
    client_timeout: { kind: 'client_timeout', message: 'This is taking longer than expected. Try again, or use a shorter date range.', retryable: true, retry_after_seconds: null },
    unexpected: { kind: 'unexpected', message: 'Something unexpected happened. Try again.', retryable: true, retry_after_seconds: null },
};

export const IDLE_STATE = Object.freeze({ phase: 'idle', notice: null, result: null, error: null, fieldErrors: null });

/** The XSRF token Laravel put in the cookie, decoded, or '' when there is none. */
export function readXsrfToken(cookie = typeof document === 'undefined' ? '' : document.cookie) {
    for (const part of cookie.split(';')) {
        const [name, ...rest] = part.trim().split('=');
        if (name === 'XSRF-TOKEN') {
            try {
                return decodeURIComponent(rest.join('='));
            } catch {
                return '';
            }
        }
    }

    return '';
}

/** Retry-After as milliseconds (delta seconds only), or null. */
export function retryAfterMs(headerValue) {
    if (typeof headerValue !== 'string' || !/^\d{1,5}$/.test(headerValue.trim())) {
        return null;
    }

    return Math.min(Number(headerValue) * 1000, MAX_RETRY_AFTER_MS);
}

export class InsightRunClient {
    #trendsUrl;
    #runPrefix;
    #fetch;
    #now;
    #setTimer;
    #clearTimer;
    #getCookie;
    #onChange;

    #seq = 0;
    #body = null;
    #debounceTimer = null;
    #waiter = null;
    #controller = null;
    #runUrl = null;
    #lastPostAt = -Infinity;
    #state = IDLE_STATE;
    #disposed = false;

    /**
     * @param {object} options
     * @param {string} options.trendsUrl the POST URL (server-built); the run URLs must live next to it
     * @param {(state: object) => void} options.onChange called with every new state object
     */
    constructor({ trendsUrl, onChange, fetch: fetchImpl = globalThis.fetch?.bind(globalThis), now = () => Date.now(), setTimer = (fn, ms) => setTimeout(fn, ms), clearTimer = (id) => clearTimeout(id), getCookie = () => readXsrfToken() }) {
        this.#trendsUrl = trendsUrl;
        this.#runPrefix = trendsUrl.replace(/\/trends$/, '/runs/');
        this.#fetch = fetchImpl;
        this.#now = now;
        this.#setTimer = setTimer;
        this.#clearTimer = clearTimer;
        this.#getCookie = getCookie;
        this.#onChange = onChange;
    }

    get state() {
        return this.#state;
    }

    /** A new definition: supersede whatever runs now and start the new one after the debounce. */
    schedule(body) {
        if (this.#disposed) {
            return;
        }

        this.#body = body;
        this.#supersede();
        this.#set({ phase: 'loading', notice: null, error: null, fieldErrors: null });
        this.#debounceTimer = this.#setTimer(() => {
            this.#debounceTimer = null;
            this.#start(body);
        }, DEBOUNCE_MS);
    }

    /** Run the current definition again right now (no debounce; the POST gap still applies). */
    retry() {
        if (this.#disposed || this.#body === null) {
            return;
        }

        this.#supersede();
        this.#set({ phase: 'loading', notice: null, error: null, fieldErrors: null });
        this.#start(this.#body);
    }

    /** Back to idle (nothing to run): cancels everything in flight and forgets the result. */
    reset() {
        this.#body = null;
        this.#supersede();
        this.#set(IDLE_STATE);
    }

    /** Component teardown: stop timers and polling, cancel the run, never touch the state again. */
    dispose() {
        this.#supersede();
        this.#disposed = true;
    }

    // ------------------------------------------------------------------ internals

    #set(patch) {
        if (this.#disposed) {
            return;
        }

        this.#state = { ...this.#state, ...patch };
        this.#onChange(this.#state);
    }

    /** Invalidates every pending step of the previous request and cancels its run when it has one. */
    #supersede() {
        this.#seq++;
        this.#clearTimer(this.#debounceTimer);
        this.#debounceTimer = null;
        this.#controller?.abort();
        this.#controller = null;
        this.#wake();

        if (this.#runUrl !== null) {
            this.#cancelRun(this.#runUrl);
            this.#runUrl = null;
        }
    }

    #headers(withBody) {
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        const token = this.#getCookie();
        if (token !== '') {
            headers['X-XSRF-TOKEN'] = token;
        }
        if (withBody) {
            headers['Content-Type'] = 'application/json';
        }

        return headers;
    }

    /** Fire and forget; the answer, success or failure, is of no interest. */
    #cancelRun(url) {
        try {
            Promise.resolve(this.#fetch(url, { method: 'DELETE', headers: this.#headers(false), credentials: 'same-origin', keepalive: true })).catch(() => {});
        } catch {
            // nothing to do: the run expires on the server
        }
    }

    /** Waits `ms`, or less when #wake() is called (a supersede). */
    #sleep(ms) {
        return new Promise((resolve) => {
            const timer = this.#setTimer(() => {
                this.#waiter = null;
                resolve();
            }, ms);
            this.#waiter = () => {
                this.#clearTimer(timer);
                this.#waiter = null;
                resolve();
            };
        });
    }

    #wake() {
        this.#waiter?.();
    }

    /** @returns {Promise<{status: number, retryAfter: number|null, json: any}|'aborted'|'network'>} */
    async #send(method, url, body, signal) {
        let response;
        try {
            response = await this.#fetch(url, {
                method,
                headers: this.#headers(body !== undefined),
                credentials: 'same-origin',
                signal,
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
        } catch (error) {
            return error?.name === 'AbortError' || signal?.aborted ? 'aborted' : 'network';
        }

        let json = null;
        try {
            json = await response.json();
        } catch {
            json = null;
        }

        return { status: response.status, retryAfter: retryAfterMs(response.headers?.get?.('Retry-After') ?? null), json };
    }

    async #start(body) {
        const seq = ++this.#seq;
        const controller = new AbortController();
        this.#controller = controller;
        const deadline = this.#now() + TIMEOUT_MS;
        const current = () => seq === this.#seq;

        // At most one POST per MIN_POST_GAP_MS, however fast the definition changes.
        const gap = this.#lastPostAt + MIN_POST_GAP_MS - this.#now();
        if (gap > 0) {
            await this.#sleep(gap);
            if (!current()) {
                return;
            }
        }

        let busyRetries = 0;
        for (;;) {
            this.#lastPostAt = this.#now();
            const answer = await this.#send('POST', this.#trendsUrl, body, controller.signal);
            if (!current() || answer === 'aborted') {
                // A run this request created after it was superseded would hold capacity for nothing: cancel it.
                if (answer !== 'aborted' && answer !== 'network' && answer.status === 202 && typeof answer.json?.poll_url === 'string' && this.#validRunUrl(answer.json.poll_url)) {
                    this.#cancelRun(answer.json.poll_url);
                }

                return;
            }
            if (answer === 'network') {
                return this.#fail(CLIENT_ERRORS.network);
            }

            const { status, json } = answer;

            if (status === 200 && json?.status === 'done' && json.result) {
                return this.#done(json.result);
            }
            if (status === 202 && typeof json?.poll_url === 'string' && this.#validRunUrl(json.poll_url)) {
                this.#runUrl = json.poll_url;

                return this.#poll(seq, json.poll_url, deadline, controller);
            }
            if (status === 422) {
                return this.#set({ phase: 'invalid', notice: null, result: null, error: errorOf(json) ?? CLIENT_ERRORS.unexpected, fieldErrors: json?.errors ?? {} });
            }
            if ((status === 429 || status === 503) && busyRetries < MAX_BUSY_RETRIES) {
                const bodySeconds = errorOf(json)?.retry_after_seconds ?? null;
                const hinted = answer.retryAfter ?? (bodySeconds === null ? null : Math.min(bodySeconds * 1000, MAX_RETRY_AFTER_MS));
                const wait = Math.max(MIN_POST_GAP_MS, hinted ?? DEFAULT_RETRY_AFTER_MS);
                if (this.#now() + wait < deadline) {
                    busyRetries++;
                    this.#set({ notice: 'The server is busy. Retrying…' });
                    await this.#sleep(wait);
                    if (!current()) {
                        return;
                    }
                    continue;
                }
            }

            return this.#fail(errorOf(json) ?? CLIENT_ERRORS.unexpected);
        }
    }

    async #poll(seq, url, deadline, controller) {
        const current = () => seq === this.#seq;
        const startedAt = this.#now();
        let failures = 0;
        let extraWait = 0;

        for (;;) {
            const elapsed = this.#now() - startedAt;
            await this.#sleep(Math.max(elapsed < FAST_WINDOW_MS ? POLL_FAST_MS : POLL_SLOW_MS, extraWait));
            extraWait = 0;
            if (!current()) {
                return;
            }

            if (this.#now() >= deadline) {
                this.#runUrl = null;
                this.#cancelRun(url);

                return this.#fail(CLIENT_ERRORS.client_timeout);
            }

            const answer = await this.#send('GET', url, undefined, controller.signal);
            if (!current() || answer === 'aborted') {
                return;
            }
            if (answer === 'network') {
                if (++failures >= MAX_NETWORK_FAILURES) {
                    return this.#fail(CLIENT_ERRORS.network);
                }
                continue;
            }
            failures = 0;

            const { status, json } = answer;

            if (status === 404) {
                this.#runUrl = null;

                return this.#set({ phase: 'expired', notice: null, result: null, error: null, fieldErrors: null });
            }
            if (status === 429 || status === 503) {
                extraWait = answer.retryAfter ?? DEFAULT_RETRY_AFTER_MS;
                continue;
            }
            if (status !== 200 || typeof json?.status !== 'string') {
                this.#runUrl = null;

                return this.#fail(errorOf(json) ?? CLIENT_ERRORS.unexpected);
            }

            switch (json.status) {
                case 'queued':
                case 'running':
                    continue;
                case 'done':
                    this.#runUrl = null;

                    return json.result ? this.#done(json.result) : this.#fail(CLIENT_ERRORS.unexpected);
                case 'failed':
                    this.#runUrl = null;

                    return this.#fail(errorOf(json) ?? CLIENT_ERRORS.unexpected);
                case 'cancelled':
                    // Not by us (a superseded run never gets here): the run is gone, offer to run it again.
                    this.#runUrl = null;

                    return this.#set({ phase: 'expired', notice: null, result: null, error: null, fieldErrors: null });
                default:
                    this.#runUrl = null;

                    return this.#fail(CLIENT_ERRORS.unexpected);
            }
        }
    }

    /** A poll URL the server handed out must be the run URL of this project: same prefix, 32 hex characters. */
    #validRunUrl(url) {
        return url.startsWith(this.#runPrefix) && RUN_ID_PATTERN.test(url.slice(this.#runPrefix.length));
    }

    #done(result) {
        this.#runUrl = null;
        this.#set({ phase: 'done', notice: null, result, error: null, fieldErrors: null });
    }

    #fail(error) {
        this.#runUrl = null;
        this.#set({ phase: 'failed', notice: null, result: null, error, fieldErrors: null });
    }
}

/** The `error` object of an API error body, reduced to what may be shown; null when the body has none. */
export function errorOf(json) {
    const error = json?.error;
    if (error === null || typeof error !== 'object' || typeof error.message !== 'string' || error.message === '') {
        return null;
    }

    return {
        kind: typeof error.kind === 'string' ? error.kind : 'unexpected',
        message: error.message.slice(0, MESSAGE_MAX),
        retryable: error.retryable === true,
        retry_after_seconds: Number.isInteger(error.retry_after_seconds) ? error.retry_after_seconds : null,
    };
}
