// What every call to the JSON API shares: the headers (JSON in and out, the XSRF token of the session), the parsing of
// an error body and of Retry-After, per-request time limits, and the one place that turns a response into
// `{status, retryAfter, json}`. Used by the insight run client and by the event-name composable.

const MESSAGE_MAX = 300;
const MAX_RETRY_AFTER_MS = 30000;

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

/**
 * Request headers. The XSRF token is read from the cookie ONCE per call (a request builds its headers once).
 *
 * @param {{ body?: boolean, token?: string }} options
 */
export function apiHeaders({ body = false, token = readXsrfToken() } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    if (token !== '') {
        headers['X-XSRF-TOKEN'] = token;
    }
    if (body) {
        headers['Content-Type'] = 'application/json';
    }

    return headers;
}

/** Retry-After as milliseconds (delta seconds only), or null. */
export function retryAfterMs(headerValue) {
    if (typeof headerValue !== 'string' || !/^\d{1,5}$/.test(headerValue.trim())) {
        return null;
    }

    return Math.min(Number(headerValue) * 1000, MAX_RETRY_AFTER_MS);
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

/**
 * A signal that aborts when `signal` does or after `ms`, whichever comes first. Uses AbortSignal.any/timeout where the
 * browser has them and a timer where it does not; `done()` clears the timer (call it when the request has finished).
 *
 * @returns {{ signal: AbortSignal, timedOut: () => boolean, done: () => void }}
 */
export function withTimeout(signal, ms, { setTimer = (fn, delay) => setTimeout(fn, delay), clearTimer = (id) => clearTimeout(id) } = {}) {
    const controller = new AbortController();
    let expired = false;

    const abort = () => controller.abort();
    if (signal?.aborted) {
        controller.abort();
    } else {
        signal?.addEventListener('abort', abort, { once: true });
    }

    const timer = setTimer(() => {
        expired = true;
        controller.abort();
    }, Math.max(1, ms));

    return {
        signal: controller.signal,
        timedOut: () => expired,
        done: () => {
            clearTimer(timer);
            signal?.removeEventListener('abort', abort);
        },
    };
}

/**
 * One request. Resolves to `{status, retryAfter, json}`, or to 'aborted' (the caller's signal), 'timeout' (`timeoutMs`
 * passed first) or 'network' (the connection failed). The body is read under the same limits: a response that stops
 * halfway is a timeout too.
 *
 * @param {object} options
 * @param {string} options.method
 * @param {string} options.url
 * @param {unknown} [options.body] a JSON body
 * @param {AbortSignal} [options.signal] abort from the caller (superseded); omit to never abort from outside
 * @param {number} options.timeoutMs
 * @param {typeof fetch} [options.fetch]
 */
export async function requestJson({ method, url, body, signal, timeoutMs, fetch: fetchImpl = globalThis.fetch?.bind(globalThis), setTimer, clearTimer, headers = apiHeaders({ body: body !== undefined }) }) {
    const limit = withTimeout(signal, timeoutMs, { setTimer, clearTimer });

    try {
        let response;
        try {
            response = await fetchImpl(url, {
                method,
                headers,
                credentials: 'same-origin',
                signal: limit.signal,
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
        } catch (error) {
            return failure(error, signal, limit);
        }

        let json = null;
        try {
            json = await response.json();
        } catch (error) {
            if (limit.signal.aborted) {
                return failure(error, signal, limit);
            }
            json = null;
        }

        return { status: response.status, retryAfter: retryAfterMs(response.headers?.get?.('Retry-After') ?? null), json };
    } finally {
        limit.done();
    }
}

function failure(error, signal, limit) {
    if (limit.timedOut()) {
        return 'timeout';
    }

    return signal?.aborted || error?.name === 'AbortError' ? 'aborted' : 'network';
}
