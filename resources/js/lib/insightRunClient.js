// The client of the asynchronous insight API (POST a definition, poll the run), without any framework: the Vue
// composable `useInsightRun` is a thin wrapper, which is also what makes the timing rules testable with a fake clock
// (tests/js/insightRunClient.test.mjs).
//
// It implements the contract of Task 3.6:
//   - changing the definition is debounced (DEBOUNCE_MS); every request has an increasing sequence number and only the
//     LATEST one may ever change the state; a superseded run that is queued or running is cancelled with DELETE
//     (fire and forget, errors ignored) so its capacity is freed at once;
//   - POST: 200 {status: done} is the result straight from the server's cache, 202 {run_id, poll_url} is a queued run;
//     at most one POST per MIN_POST_GAP_MS (the budget is 60 per minute, this leaves headroom);
//   - the POST is NEVER aborted by the client (an aborted POST may have created a run whose id the client would never
//     learn, which then holds capacity): its answer is always read, and when the request was superseded meanwhile the run
//     it created is cancelled through its `poll_url`. Only polls are aborted;
//   - poll the `poll_url`: the first poll after the Retry-After of the 202 (default 1 s), then every POLL_FAST_MS for
//     the first FAST_POLLS polls and every POLL_SLOW_MS after that (a poll's own Retry-After is a floor); stop at done,
//     failed, cancelled, or a 404 (the run expired or is not ours: offer to run again);
//   - a page that runs on a button instead of on every change uses run() (no debounce) and cancel() (DELETE of the run, or of
//     the run a POST still in flight creates; the state becomes `cancelled`); while it polls, `stage` says whether the run
//     is `queued` or already `running`;
//   - every request has its own time limit (min(REQUEST_TIMEOUT_MS, what is left of the TIMEOUT_MS budget)), and the whole
//     run gives up after TIMEOUT_MS (and cancels);
//   - 429 and 503 honour Retry-After (bounded automatic retries, then the error is shown);
//   - the XSRF token comes from the XSRF-TOKEN cookie (decoded) as X-XSRF-TOKEN, same-origin credentials, JSON in and out;
//   - server error bodies `{message, error: {kind, message, retryable, retry_after_seconds}}` are shown VERBATIM (the
//     messages are fixed sentences chosen by the server); nothing else from a response body is ever displayed.

import { apiHeaders, errorOf, readXsrfToken, requestJson, retryAfterMs } from './api.js';

export { errorOf, readXsrfToken, retryAfterMs };

export const DEBOUNCE_MS = 400;
export const MIN_POST_GAP_MS = 1500;
export const POLL_FAST_MS = 1000;
export const POLL_SLOW_MS = 2500;
export const FAST_POLLS = 4;
export const TIMEOUT_MS = 60000;
export const REQUEST_TIMEOUT_MS = 15000;
export const MAX_BUSY_RETRIES = 3;
export const MAX_POLL_FAILURES = 3;
const DEFAULT_RETRY_AFTER_MS = 3000;
const MAX_RETRY_AFTER_MS = 30000;
const RUN_ID_PATTERN = /^[0-9a-f]{32}$/;

const CLIENT_ERRORS = {
    network: { kind: 'network', message: 'The server could not be reached. Check your connection and try again.', retryable: true, retry_after_seconds: null },
    client_timeout: { kind: 'client_timeout', message: 'This is taking longer than expected. Try again, or use a shorter date range.', retryable: true, retry_after_seconds: null },
    unexpected: { kind: 'unexpected', message: 'Something unexpected happened. Try again.', retryable: true, retry_after_seconds: null },
};

// `computedAt` and `source` (ISO time the result was computed, `dashboard_cache` | `cache`) are set only by a saved insight run that
// the server answered at once with a stored result (POST saved-insights/{id}/run, 200); they are null for every other result.
export const IDLE_STATE = Object.freeze({ phase: 'idle', stage: null, notice: null, result: null, error: null, fieldErrors: null, computedAt: null, source: null });

export class InsightRunClient {
    #postUrl;
    #runsUrl;
    #origin;
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
    #pollController = null;
    #runUrl = null;
    #lastPostAt = -Infinity;
    #state = IDLE_STATE;
    #disposed = false;

    /**
     * @param {object} options
     * @param {string} options.postUrl the POST URL (server-built)
     * @param {string} options.runsUrl the base URL of the runs (server-built, ends with `/runs/`): a poll URL the server
     *     hands out must be this base plus 32 hex characters
     * @param {(state: object) => void} options.onChange called with every new state object
     */
    constructor({ postUrl, runsUrl, onChange, origin = globalThis.location?.origin ?? 'http://localhost', fetch: fetchImpl = globalThis.fetch?.bind(globalThis), now = () => Date.now(), setTimer = (fn, ms) => setTimeout(fn, ms), clearTimer = (id) => clearTimeout(id), getCookie = () => readXsrfToken() }) {
        this.#postUrl = postUrl;
        this.#runsUrl = runsUrl;
        this.#origin = origin;
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

    /** A new definition, run right now (the page's Run button): supersedes whatever runs, no debounce. */
    run(body) {
        if (this.#disposed) {
            return;
        }

        this.#body = body;
        this.retry();
    }

    /**
     * The user stops what runs: its run is cancelled on the server (a POST that is still in flight cancels the run it creates
     * when it is answered) and the phase becomes `cancelled`. Nothing runs, nothing to cancel: no change.
     */
    cancel() {
        if (this.#disposed || this.#state.phase !== 'loading') {
            return;
        }

        this.#supersede();
        this.#set({ phase: 'cancelled', notice: null, error: null, fieldErrors: null });
    }

    /**
     * The definition is incomplete (a field is still being filled in): nothing is sent and whatever runs is cancelled, but
     * the last result STAYS (phase `held`) instead of the chart vanishing under an unfinished edit.
     */
    hold() {
        this.#body = null;
        this.#supersede();
        this.#set({ phase: 'held', notice: null, error: null, fieldErrors: null });
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

        const next = { ...this.#state, ...patch };
        // The stage belongs to a run in progress only, and the stored-result stamp to a result that was answered at once.
        const stamped = next.phase === 'done' ? next : { ...next, computedAt: null, source: null };
        this.#state = next.phase === 'loading' ? stamped : { ...stamped, stage: null };
        this.#onChange(this.#state);
    }

    /** Invalidates every pending step of the previous request and cancels its run when it has one. */
    #supersede() {
        this.#seq++;
        this.#clearTimer(this.#debounceTimer);
        this.#debounceTimer = null;
        this.#pollController?.abort();
        this.#pollController = null;
        this.#wake();

        if (this.#runUrl !== null) {
            this.#cancelRun(this.#runUrl);
            this.#runUrl = null;
        }
    }

    #timers() {
        return { setTimer: this.#setTimer, clearTimer: this.#clearTimer };
    }

    /** Fire and forget; the answer, success or failure, is of no interest. */
    #cancelRun(url) {
        try {
            Promise.resolve(this.#fetch(url, { method: 'DELETE', headers: apiHeaders({ token: this.#getCookie() }), credentials: 'same-origin', keepalive: true })).catch(() => {});
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

    /** The time limit of the next request: its own limit, never more than what is left of the run's budget. */
    #limit(deadline) {
        return Math.max(1, Math.min(REQUEST_TIMEOUT_MS, deadline - this.#now()));
    }

    async #start(body) {
        const seq = ++this.#seq;
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
            // No abort signal from outside: the answer of a POST is always read (see the file comment).
            const answer = await requestJson({ method: 'POST', url: this.#postUrl, body, timeoutMs: this.#limit(deadline), fetch: this.#fetch, headers: apiHeaders({ body: true, token: this.#getCookie() }), ...this.#timers() });

            if (!current()) {
                // A run this request created after it was superseded would hold capacity for nothing: cancel it.
                const orphan = typeof answer === 'object' && answer.status === 202 ? this.#runPath(answer.json?.poll_url) : null;
                if (orphan !== null) {
                    this.#cancelRun(orphan);
                }

                return;
            }
            if (answer === 'network') {
                return this.#fail(CLIENT_ERRORS.network);
            }
            if (answer === 'timeout' || answer === 'aborted') {
                return this.#fail(CLIENT_ERRORS.client_timeout);
            }

            const { status, json } = answer;

            if (status === 200 && json?.status === 'done' && json.result) {
                return this.#done(json.result, { computedAt: typeof json.computed_at === 'string' ? json.computed_at : null, source: typeof json.source === 'string' ? json.source : null });
            }
            if (status === 202) {
                const path = this.#runPath(json?.poll_url);
                if (path !== null) {
                    this.#runUrl = path;

                    return this.#poll(seq, path, deadline, answer.retryAfter);
                }
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

    async #poll(seq, path, deadline, firstDelay) {
        const current = () => seq === this.#seq;
        const controller = new AbortController();
        this.#pollController = controller;
        let polls = 0;
        let failures = 0;
        let floor = firstDelay ?? POLL_FAST_MS;

        for (;;) {
            const base = polls < FAST_POLLS ? POLL_FAST_MS : POLL_SLOW_MS;
            await this.#sleep(polls === 0 ? floor : Math.max(base, floor));
            floor = 0;
            if (!current()) {
                return;
            }

            if (this.#now() >= deadline) {
                this.#runUrl = null;
                this.#cancelRun(path);

                return this.#fail(CLIENT_ERRORS.client_timeout);
            }

            polls++;
            const answer = await requestJson({ method: 'GET', url: path, signal: controller.signal, timeoutMs: this.#limit(deadline), fetch: this.#fetch, headers: apiHeaders({ token: this.#getCookie() }), ...this.#timers() });
            if (!current() || answer === 'aborted') {
                return;
            }
            if (answer === 'network' || answer === 'timeout') {
                if (++failures >= MAX_POLL_FAILURES) {
                    this.#runUrl = null;
                    this.#cancelRun(path);

                    return this.#fail(answer === 'timeout' ? CLIENT_ERRORS.client_timeout : CLIENT_ERRORS.network);
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
                floor = answer.retryAfter ?? DEFAULT_RETRY_AFTER_MS;
                continue;
            }
            if (status !== 200 || typeof json?.status !== 'string') {
                this.#runUrl = null;

                return this.#fail(errorOf(json) ?? CLIENT_ERRORS.unexpected);
            }

            switch (json.status) {
                case 'queued':
                case 'running':
                    if (this.#state.stage !== json.status) {
                        this.#set({ stage: json.status });
                    }
                    floor = answer.retryAfter ?? 0;
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

    /**
     * The same-origin PATH of a poll URL the server handed out, or null: it must be this origin and the runs base of this
     * project plus 32 hex characters, nothing else (no query, no fragment, no credentials).
     */
    #runPath(url) {
        if (typeof url !== 'string') {
            return null;
        }

        let parsed;
        try {
            parsed = new URL(url, this.#origin);
        } catch {
            return null;
        }

        if (parsed.origin !== this.#origin || parsed.search !== '' || parsed.hash !== '' || !parsed.pathname.startsWith(this.#runsUrl)) {
            return null;
        }

        return RUN_ID_PATTERN.test(parsed.pathname.slice(this.#runsUrl.length)) ? parsed.pathname : null;
    }

    #done(result, stamp = { computedAt: null, source: null }) {
        this.#runUrl = null;
        this.#set({ phase: 'done', notice: null, result, error: null, fieldErrors: null, ...stamp });
    }

    #fail(error) {
        this.#runUrl = null;
        this.#set({ phase: 'failed', notice: null, result: null, error, fieldErrors: null });
    }
}
