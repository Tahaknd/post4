// The client of the saved insight and dashboard JSON API (App\Http\Controllers\SavedInsightController, DashboardController,
// DashboardTileController). Every call answers one shape, never throws:
//   { ok: true, status, data, meta }                                  `data` is the `data` of the body (null for a 204)
//   { ok: false, status, kind, message, errors, retryAfter }           `errors` is { path: first message } for a 422
// `kind` is network, timeout, validation, not_found, unauthenticated, page_expired, busy (429 and 503), server or unexpected.
// URLs of collections come from the server; an item URL is the collection plus a uuid that is checked here first.

import { apiHeaders, errorOf, requestJson } from './api.js';

export const REQUEST_TIMEOUT_MS = 15000;
export const LIST_PAGE_SIZE = 100;
export const LIST_MAX_PAGES = 10;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

export const isUuid = (value) => typeof value === 'string' && UUID.test(value);

const MESSAGES = {
    network: 'The server could not be reached. Check your connection and try again.',
    timeout: 'This is taking longer than expected. Try again.',
    unexpected: 'Something unexpected happened. Try again.',
    not_found: 'This no longer exists. It may have been deleted.',
    unauthenticated: 'You are signed out. Reload the page to sign in again.',
    page_expired: 'The page expired. Reload and try again.',
    busy: 'The server is busy. Try again in a moment.',
    server: 'Something went wrong on our side. Try again shortly.',
};

function failure(status, kind, message, extra = {}) {
    return { ok: false, status, kind, message: message || MESSAGES[kind], errors: {}, retryAfter: null, ...extra };
}

/** `{ 'query.events.0': ['m'] }` becomes `{ 'query.events.0': 'm' }`: the first sentence of each field. */
export function firstMessages(errors) {
    const out = {};
    for (const [path, messages] of Object.entries(errors ?? {})) {
        const message = Array.isArray(messages) ? messages[0] : messages;
        if (typeof message === 'string' && message !== '') {
            out[path] = message;
        }
    }

    return out;
}

/** One call of the API. */
export async function call({ method, url, body, fetch: fetchImpl, timeoutMs = REQUEST_TIMEOUT_MS }) {
    const answer = await requestJson({ method, url, body, timeoutMs, fetch: fetchImpl, headers: apiHeaders({ body: body !== undefined }) });

    if (answer === 'network' || answer === 'aborted') {
        return failure(0, 'network');
    }
    if (answer === 'timeout') {
        return failure(0, 'timeout');
    }

    const { status, json, retryAfter } = answer;

    if (status >= 200 && status < 300) {
        return { ok: true, status, data: json?.data ?? null, meta: json?.meta ?? null };
    }

    const error = errorOf(json);
    if (status === 422) {
        return failure(status, 'validation', error?.message, { errors: firstMessages(json?.errors) });
    }
    if (status === 404) {
        return failure(status, 'not_found');
    }
    if (status === 401) {
        return failure(status, 'unauthenticated');
    }
    if (status === 419) {
        return failure(status, 'page_expired');
    }
    if (status === 429 || status === 503) {
        return failure(status, 'busy', error?.message, { retryAfter: retryAfter === null ? (error?.retry_after_seconds ?? null) : Math.round(retryAfter / 1000) });
    }

    return failure(status, status >= 500 ? 'server' : 'unexpected', error?.message);
}

function itemUrl(base, id, ...rest) {
    return isUuid(id) ? [base, id, ...rest].join('/') : null;
}

async function item(method, base, id, body, options, ...rest) {
    const url = itemUrl(base, id, ...rest);

    return url === null ? failure(404, 'not_found') : call({ method, url, body, ...options });
}

/** Every row of a paged list (page size 100, at most LIST_MAX_PAGES pages): `{ ok, data: rows[], total }`. */
export async function listAll(url, options = {}) {
    const rows = [];
    let total = 0;

    for (let page = 1; page <= LIST_MAX_PAGES; page++) {
        const answer = await call({ method: 'GET', url: `${url}?page=${page}&per_page=${LIST_PAGE_SIZE}`, ...options });
        if (!answer.ok) {
            return answer;
        }

        rows.push(...(Array.isArray(answer.data) ? answer.data : []));
        total = answer.meta?.total ?? rows.length;
        if (page >= (answer.meta?.last_page ?? 1)) {
            break;
        }
    }

    return { ok: true, status: 200, data: rows, meta: { total } };
}

export const createSavedInsight = (base, payload, options = {}) => call({ method: 'POST', url: base, body: payload, ...options });
export const getSavedInsight = (base, id, options = {}) => item('GET', base, id, undefined, options);
export const updateSavedInsight = (base, id, changes, options = {}) => item('PATCH', base, id, changes, options);
export const deleteSavedInsight = (base, id, options = {}) => item('DELETE', base, id, undefined, options);
export const savedInsightRunUrl = (base, id) => itemUrl(base, id, 'run');

export const createDashboard = (base, payload, options = {}) => call({ method: 'POST', url: base, body: payload, ...options });
export const getDashboard = (base, id, options = {}) => item('GET', base, id, undefined, options);
export const updateDashboard = (base, id, changes, options = {}) => item('PATCH', base, id, changes, options);
export const deleteDashboard = (base, id, options = {}) => item('DELETE', base, id, undefined, options);
export const addTile = (base, dashboardId, savedInsightId, options = {}) => item('POST', base, dashboardId, { saved_insight_id: savedInsightId }, options, 'tiles');
export const removeTile = (base, dashboardId, tileId, options = {}) => (isUuid(tileId) ? item('DELETE', base, dashboardId, undefined, options, 'tiles', tileId) : failure(404, 'not_found'));
export const reorderTiles = (base, dashboardId, tileIds, options = {}) => item('PUT', base, dashboardId, { tiles: tileIds }, options, 'tiles', 'order');
