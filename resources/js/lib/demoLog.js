// Pure helpers of the posthog-js test bench (Projects/Demo.vue): what to show in the activity log and how. Nothing
// here touches the DOM or the SDK, so tests/js/demoLog.test.mjs can run them under plain node.

export const MAX_LOG_ENTRIES = 100;

const DETAIL_LIMIT = 2000;

/** The project key without its secret part: `phc_` and the last four characters. */
export function maskKey(key) {
    if (typeof key !== 'string' || key.length < 12) {
        return '';
    }

    return `${key.slice(0, 4)}…${key.slice(-4)}`;
}

/**
 * The host the SDK talks to. The origin the page was opened from wins over the configured APP_URL: the page is served by
 * the same app that takes the events, so a LAN address, an IP or a port-forward just works. The configured value is the
 * fallback for environments without a usable origin (opaque origins read "null").
 */
export function effectiveApiHost(origin, configured) {
    const usable = typeof origin === 'string' && /^https?:\/\/[^/]+$/.test(origin);

    return (usable ? origin : String(configured ?? '')).replace(/\/+$/, '');
}

/** JSON for the details view: the project key replaced by its masked form, long output cut. */
export function describe(value, key) {
    let text;

    try {
        text = JSON.stringify(value, null, 2) ?? 'undefined';
    } catch {
        text = '[unserializable]';
    }

    if (typeof key === 'string' && key !== '') {
        text = text.split(key).join(maskKey(key) || '[key]');
    }

    return text.length > DETAIL_LIMIT ? `${text.slice(0, DETAIL_LIMIT)}\n… (${text.length - DETAIL_LIMIT} more characters)` : text;
}

/** The log entry of an event the SDK is about to send (`posthog.on('eventCaptured')`). */
export function capturedEntry(captured, key, now) {
    const name = typeof captured?.event === 'string' ? captured.event : '(no name)';
    const distinctId = captured?.properties?.distinct_id;

    return {
        at: now,
        kind: 'captured',
        text: `${name}${typeof distinctId === 'string' ? ` as ${distinctId}` : ''}`,
        detail: describe(captured, key),
    };
}

/** Which tone a response status has: 2xx ok, 0 and 4xx/5xx error, the rest neutral. */
export function statusTone(status) {
    if (status >= 200 && status < 300) {
        return 'ok';
    }

    return status === 0 || status >= 400 ? 'error' : 'info';
}

/** The path and query without the host, so the log stays short and does not repeat the origin. */
export function pathOf(url, base) {
    try {
        const parsed = new URL(url, base);

        return `${parsed.pathname}${parsed.search}`;
    } catch {
        return String(url);
    }
}

/** Whether a resource timing entry is a request to one of the SDK endpoints of this server (not an asset). */
export function isSdkRequest(url, apiHost, base) {
    try {
        const parsed = new URL(url, base);

        return `${parsed.protocol}//${parsed.host}` === new URL(apiHost, base).origin && /^\/(e|i|batch|flags|decide|array)(\/|$)/.test(parsed.pathname);
    } catch {
        return false;
    }
}

/** The key replaced by its masked form wherever it appears in a line of the log (the config URL contains it). */
function withoutKey(text, key) {
    return typeof key === 'string' && key !== '' ? text.split(key).join(maskKey(key) || '[key]') : text;
}

/** The log entry of a finished SDK request (a PerformanceResourceTiming entry, `responseStatus` is 0 when unknown). */
export function requestEntry(entry, apiHost, now, key) {
    const status = Number(entry.responseStatus) || 0;
    const kind = entry.initiatorType === 'beacon' ? 'beacon' : 'request';

    return {
        at: now,
        kind,
        tone: statusTone(status),
        text: withoutKey(`${pathOf(entry.name, apiHost)} ${status === 0 ? 'status unknown' : `HTTP ${status}`}`, key),
        detail: describe({ url: entry.name, initiatorType: entry.initiatorType, responseStatus: entry.responseStatus, transferSize: entry.transferSize, durationMs: Math.round(entry.duration) }, key),
    };
}

/** Appends to the log and keeps the newest MAX_LOG_ENTRIES. Never mutates the list it was given. */
export function pushEntry(list, entry, max = MAX_LOG_ENTRIES) {
    const next = [...list, { ...entry, id: (list.at(-1)?.id ?? 0) + 1 }];

    return next.length > max ? next.slice(next.length - max) : next;
}
