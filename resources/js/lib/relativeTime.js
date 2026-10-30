// "Updated 5 minutes ago": the age of a result as a short sentence, with the exact moment available for a tooltip.
// Pure functions of two instants, so a page can re-render them on a shared timer and tests can pin them.

const MINUTE = 60;
const HOUR = 3600;
const DAY = 86400;

const plural = (count, unit) => `${count} ${unit}${count === 1 ? '' : 's'}`;

/**
 * How long ago `then` was, relative to `now` (both Date, epoch milliseconds or ISO strings). Under a minute is "just now" (the
 * label is refreshed every half minute, so seconds would always be stale); a time in the future (a skewed clock) is also "just now".
 */
export function formatRelative(then, now = Date.now()) {
    const seconds = Math.floor((toMillis(now) - toMillis(then)) / 1000);

    if (!Number.isFinite(seconds) || seconds < MINUTE) {
        return 'just now';
    }
    if (seconds < HOUR) {
        return `${plural(Math.floor(seconds / MINUTE), 'minute')} ago`;
    }
    if (seconds < DAY) {
        return `${plural(Math.floor(seconds / HOUR), 'hour')} ago`;
    }

    return `${plural(Math.floor(seconds / DAY), 'day')} ago`;
}

/** The sentence of a tile or a list: "Updated 5 minutes ago" ("Updated just now" under a minute). */
export function updatedText(then, now = Date.now()) {
    return `Updated ${formatRelative(then, now)}`;
}

export function toMillis(value) {
    if (value instanceof Date) {
        return value.getTime();
    }

    return typeof value === 'number' ? value : Date.parse(value);
}

/** The exact moment for a title attribute: "Oct 30, 2026, 14:05" in the viewer's zone. */
export function formatAbsolute(value, timeZone = undefined) {
    const time = toMillis(value);
    if (!Number.isFinite(time)) {
        return '';
    }

    try {
        return new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short', hourCycle: 'h23', ...(timeZone ? { timeZone } : {}) }).format(time);
    } catch {
        return new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short', hourCycle: 'h23', timeZone: 'UTC' }).format(time);
    }
}

/** The ISO string for a `<time datetime>` attribute, or '' when the value is not a moment. */
export function isoOf(value) {
    const time = toMillis(value);

    return Number.isFinite(time) ? new Date(time).toISOString() : '';
}
