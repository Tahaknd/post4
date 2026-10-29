// The parts of an insight definition that every insight page shares: property filter rows, the date range, and what
// turns them into an API body, a shareable query string, instant validation and field errors. Page-specific pieces
// (events, interval, breakdown; steps, window, order) live next to their page (trendsDefinition.js, funnelDefinition.js).
//
// The SERVER is the source of truth for every rule. The checks here mirror its limits (read from the page props, which
// come from the domain constants) only for instant feedback, and they are deliberately never stricter than the server:
// where a rule depends on something the client cannot know exactly (the number of buckets across DST changes, "today"
// for this_month), the estimate is a lower bound, so a definition the server would accept is never blocked here.

import { codePoints } from './trendsFormat.js';

export const PRESET_DAYS_MIN = { last_7_days: 7, last_30_days: 30, last_90_days: 90, this_month: 1, last_month: 28 };

/** The `errors` keys of the API that belong to the date range control. */
export const DATE_ERROR_PATH = /^(date_preset|date_from|date_to)$/;

/** At most this many filters are read from a link, whatever the page allows (a link is not trusted). */
export const MAX_URL_FILTERS = 10;

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

// ---------------------------------------------------------------- filters

let nextFilterId = 1;

export function newFilter(overrides = {}) {
    return { id: nextFilterId++, key: '', operator: 'equals', value: '', ...overrides };
}

/** A filter row nobody has typed into yet: it is a draft, not part of the definition (no body, no validation). */
export function isBlankFilter(filter) {
    return filter.key === '' && filter.value === '';
}

/** The rows that count: every row that is not blank, in the order of the form (and of the request body). */
export function activeFilterRows(filters) {
    return filters.filter((filter) => !isBlankFilter(filter));
}

function needsValueMap(operators) {
    return new Map(operators.map((operator) => [operator.value, operator.needs_value]));
}

/** The API body of filter rows: `value` is null for an operator that takes none ("is set"). */
export function filterBody(filters, operators) {
    const needsValue = needsValueMap(operators);

    return activeFilterRows(filters).map((filter) => ({
        key: filter.key,
        operator: filter.operator,
        value: needsValue.get(filter.operator) === false ? null : filter.value,
    }));
}

/** Instant validation of the active rows: `{ [id]: { key?, value? } }` with only the rows that have a message. */
export function validateFilterRows(filters, operators, limits) {
    const errors = {};
    const needsValue = needsValueMap(operators);

    for (const filter of activeFilterRows(filters)) {
        const row = {};
        if (filter.key === '') {
            row.key = 'Enter a property key.';
        } else if (codePoints(filter.key) > limits.max_filter_key_length) {
            row.key = `The key can be at most ${limits.max_filter_key_length} characters.`;
        }
        if (needsValue.get(filter.operator) !== false && codePoints(filter.value) > limits.max_filter_value_length) {
            row.value = `The value can be at most ${limits.max_filter_value_length} characters.`;
        }
        if (Object.keys(row).length > 0) {
            errors[filter.id] = row;
        }
    }

    return errors;
}

/** Puts the message of a server error about `filters.N.<field>` on the row it belongs to (an operator error shows at the key). */
export function placeFilterError(errors, filter, field, message) {
    errors[filter.id] ??= {};
    errors[filter.id][field === 'operator' ? 'key' : (field ?? 'key')] ??= message;
}

/** One filter as it goes into a query string: `[key, operator, value]`. */
export function filterTuple(filter) {
    return [filter.key, filter.operator, filter.value];
}

/** One filter as a query string value: the JSON of its tuple. */
export function encodeFilter(filter) {
    return JSON.stringify(filterTuple(filter));
}

/** The filter a query string value describes, or null for anything that is not a valid one (never trusted). */
export function decodeFilter(raw, operators, limits) {
    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch {
        return null;
    }

    return decodeFilterTuple(parsed, operators, limits);
}

/** The filter that an already parsed `[key, operator, value]` describes, or null (never trusted, like {@see decodeFilter}). */
export function decodeFilterTuple(parsed, operators, limits) {
    if (!Array.isArray(parsed) || parsed.length !== 3) {
        return null;
    }

    const [key, operator, value] = parsed;
    const known = operators.find((item) => item.value === operator);

    if (typeof key !== 'string' || codePoints(key) < 1 || codePoints(key) > limits.max_filter_key_length || known === undefined) {
        return null;
    }
    if (typeof value !== 'string' || codePoints(value) > limits.max_filter_value_length) {
        return null;
    }

    return newFilter({ key, operator, value });
}

// ---------------------------------------------------------------- date range

/** The range a page starts from: the server's default preset. */
export function defaultRange(defaults) {
    return { mode: 'preset', preset: defaults.date_preset, from: '', to: '' };
}

/** The API fields of a range: a preset, or both ends of a custom range. */
export function rangeBody(range) {
    return range.mode === 'custom' ? { date_from: range.from, date_to: range.to } : { date_preset: range.preset };
}

/** Whole days from `from` to `to` inclusive, or null when either is not a real calendar day. */
export function inclusiveDays(from, to) {
    const a = parseDay(from);
    const b = parseDay(to);

    return a === null || b === null ? null : Math.round((b - a) / 86400000) + 1;
}

export function parseDay(value) {
    if (typeof value !== 'string' || !DATE_PATTERN.test(value)) {
        return null;
    }

    const time = Date.parse(`${value}T00:00:00Z`);

    return Number.isNaN(time) || new Date(time).toISOString().slice(0, 10) !== value ? null : time;
}

/**
 * Instant validation of a range. `error` is the message (or undefined), `days` the number of days it spans: exact for a
 * custom range, a lower bound for a preset, null when it cannot be known (a custom range with an error).
 */
export function validateRange(range, limits) {
    if (range.mode !== 'custom') {
        return { error: undefined, days: PRESET_DAYS_MIN[range.preset] ?? 1 };
    }

    const { from, to } = range;
    if (from === '' || to === '') {
        return { error: 'Pick both a start and an end date.', days: null };
    }

    const days = inclusiveDays(from, to);
    if (days === null) {
        return { error: 'Use real calendar dates.', days: null };
    }
    if (days < 1) {
        return { error: 'The end date must not be before the start date.', days };
    }
    if (days > limits.max_range_days) {
        return { error: `The range can be at most ${limits.max_range_days} days.`, days };
    }

    return { error: undefined, days };
}

/** Writes a range into query parameters: the custom range as `from` + `to`, a preset as `p` unless it is the default. */
export function encodeRange(range, defaults, params) {
    if (range.mode === 'custom') {
        params.set('from', range.from);
        params.set('to', range.to);
    } else if (range.preset !== defaults.date_preset) {
        params.set('p', range.preset);
    }
}

/** The range that query parameters describe, checked against the page's presets; the default when they say nothing usable. */
export function decodeRange(params, presets, defaults) {
    const range = defaultRange(defaults);
    const from = params.get('from');
    const to = params.get('to');
    const preset = params.get('p');

    if (from !== null && to !== null && parseDay(from) !== null && parseDay(to) !== null) {
        return { mode: 'custom', preset: defaults.date_preset, from, to };
    }
    if (preset !== null && presets.some((item) => item.value === preset)) {
        range.preset = preset;
    }

    return range;
}
