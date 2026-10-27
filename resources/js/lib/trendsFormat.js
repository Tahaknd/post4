// Display helpers for a trends RESULT (`{ timezone, buckets, labels, series }`, see TrendsResult::toArray()):
// bucket labels in the result's time zone, series names, totals and the series shown in the chart.
// Pure functions; nothing here touches the DOM or ECharts.

/** At most this many series are drawn and listed in the legend; the table always has all of them. */
export const MAX_CHART_SERIES = 10;

const LABEL_MAX = 40;

/** The language of the interface (all its copy is English): number and date formats do not follow the browser's locale. */
export const DEFAULT_LOCALE = 'en';

// Intl formatters are expensive to build and a result can have thousands of cells: one instance per locale and options.
const formatters = new Map();

function numberFormat(locale, options = {}) {
    const key = `n|${locale}|${JSON.stringify(options)}`;
    if (!formatters.has(key)) {
        formatters.set(key, new Intl.NumberFormat(locale, options));
    }

    return formatters.get(key);
}

export function formatNumber(value, locale = DEFAULT_LOCALE) {
    return numberFormat(locale).format(value);
}

/** Length in code points, like PHP's mb_strlen (not UTF-16 units: an emoji is one). */
export function codePoints(text) {
    return [...String(text)].length;
}

export function truncate(text, max = LABEL_MAX) {
    const chars = [...String(text)];

    return chars.length > max ? `${chars.slice(0, max - 1).join('')}…` : String(text);
}

/** `Intl.DateTimeFormat` options per interval; `long` is for table headers and tooltips, `short` for the axis. */
function dateFormatOptions(interval, style, spansYears) {
    const year = spansYears || interval === 'month' ? { year: 'numeric' } : {};

    if (interval === 'hour') {
        return { month: 'short', day: 'numeric', ...year, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
    }
    if (interval === 'month') {
        return { month: 'short', ...year };
    }
    if (style === 'long') {
        return { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
    }

    return { month: 'short', day: 'numeric', ...year };
}

function formatterFor(timeZone, options, locale = DEFAULT_LOCALE) {
    const key = `d|${locale}|${timeZone}|${JSON.stringify(options)}`;

    if (!formatters.has(key)) {
        try {
            formatters.set(key, new Intl.DateTimeFormat(locale, { ...options, timeZone }));
        } catch {
            // An unknown zone name cannot reach here (the API only returns IANA identifiers); stay readable if it does.
            formatters.set(key, new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }));
        }
    }

    return formatters.get(key);
}

/**
 * One label per bucket (UTC epoch seconds), formatted in `timeZone`. Hourly labels follow the zone's real clock, so a
 * DST change shows the repeated or skipped hour as it happened; when two buckets would get the same text (the repeated
 * hour of a fall-back) every label of that text gets the UTC offset appended, so labels are always unique (an axis
 * built from them can never merge two buckets).
 *
 * @param {number[]} buckets
 * @param {'hour'|'day'|'week'|'month'} interval
 * @param {string} timeZone
 * @param {'short'|'long'} style
 * @returns {string[]}
 */
export function bucketLabels(buckets, interval, timeZone, style = 'short', locale = DEFAULT_LOCALE) {
    const years = new Set();
    const yearFormatter = formatterFor(timeZone, { year: 'numeric' }, locale);
    for (const bucket of buckets) {
        years.add(yearFormatter.format(new Date(bucket * 1000)));
    }

    const options = dateFormatOptions(interval, style, years.size > 1);
    const formatter = formatterFor(timeZone, options, locale);
    const labels = buckets.map((bucket) => formatter.format(new Date(bucket * 1000)));

    const seen = new Map();
    for (const label of labels) {
        seen.set(label, (seen.get(label) ?? 0) + 1);
    }
    if ([...seen.values()].every((count) => count === 1)) {
        return labels;
    }

    const offset = formatterFor(timeZone, { timeZoneName: 'shortOffset' }, locale);
    const offsets = buckets.map((bucket) => offset.formatToParts(new Date(bucket * 1000)).find((part) => part.type === 'timeZoneName')?.value ?? '');

    return labels.map((label, index) => (seen.get(label) > 1 ? `${label} (${offsets[index]})` : label));
}

/**
 * The sentinel series never show the server's sentinel text. Their words are chosen so that they cannot be mistaken for a
 * REAL value: a property that has the value "Other" or "None" is shown as written, the grouped rest is "Other (rest)"
 * and events without the property are "No value".
 */
export const OTHER_NAME = 'Other (rest)';
export const NONE_NAME = 'No value';

export function breakdownText(series) {
    if (series.is_other) {
        return OTHER_NAME;
    }
    if (series.is_none) {
        return NONE_NAME;
    }

    return series.breakdown ?? '';
}

/**
 * Display names of the series, in order. With one event the breakdown value alone names a breakdown series; with
 * several events the event is part of the name, so two series never look alike.
 */
export function seriesNames(series) {
    const events = new Set(series.map((entry) => entry.event));

    return series.map((entry) => {
        if (entry.breakdown === null) {
            return entry.event;
        }

        return events.size > 1 ? `${entry.event} · ${breakdownText(entry)}` : breakdownText(entry);
    });
}

export function total(data) {
    return data.reduce((sum, value) => sum + value, 0);
}

/**
 * Splits the series into the ones drawn (the `max` with the largest totals, kept in their original relative order)
 * and the number hidden. Indexes refer to the input arrays.
 */
export function selectChartSeries(series, max = MAX_CHART_SERIES) {
    if (series.length <= max) {
        return { shown: series.map((_, index) => index), hidden: 0 };
    }

    const ranked = series
        .map((entry, index) => ({ index, total: total(entry.data) }))
        .sort((a, b) => b.total - a.total || a.index - b.index)
        .slice(0, max)
        .map((entry) => entry.index)
        .sort((a, b) => a - b);

    return { shown: ranked, hidden: series.length - max };
}

export function isEmptyResult(result) {
    return result.series.every((entry) => entry.data.every((value) => value === 0));
}

const METRIC_NOUN = { total_events: 'events', unique_users: 'unique users' };

export function metricNoun(metric) {
    return METRIC_NOUN[metric] ?? 'events';
}

/**
 * A plain-language summary for the chart's accessible name: kind, metric, interval, number of series, the period and
 * the grand total. (For unique users the "total" is a sum over buckets and series, not a distinct count: it is
 * worded as a sum.)
 */
export function describeResult(result, { chartType, metric, interval }) {
    const names = result.series.length;
    const first = result.buckets.length > 0 ? bucketLabels([result.buckets[0]], 'day', result.timezone, 'long')[0] : '';
    const last = result.buckets.length > 0 ? bucketLabels([result.buckets[result.buckets.length - 1]], 'day', result.timezone, 'long')[0] : '';
    const sum = result.series.reduce((acc, entry) => acc + total(entry.data), 0);
    const period = first === '' ? '' : first === last ? ` on ${first}` : ` from ${first} to ${last}`;

    return `${chartType === 'bar' ? 'Bar' : 'Line'} chart of ${metricNoun(metric)} per ${interval}, ${names} series${period}. Sum of all values: ${formatNumber(sum)}.`;
}

/** Axis tick text for big numbers: 1200 becomes 1.2K. */
export function abbreviate(value, locale = DEFAULT_LOCALE) {
    return numberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}
