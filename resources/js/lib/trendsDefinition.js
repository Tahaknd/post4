// The Trends definition as the UI holds it, and everything that turns it into something else: the JSON body of
// POST /api/projects/{project}/insights/trends, the shareable query string, instant client-side validation and the
// mapping of the API's 422 field errors back onto controls.
//
// The SERVER is the source of truth for every rule; the shared rules and the reasons the checks are never stricter than the
// server are in insightQuery.js. What is specific to trends (events, interval, metric, breakdown, the row cap) is here.

import {
    DATE_ERROR_PATH,
    MAX_URL_FILTERS,
    activeFilterRows,
    decodeFilter,
    decodeRange,
    defaultRange,
    encodeFilter,
    encodeRange,
    filterBody,
    placeFilterError,
    rangeBody,
    validateFilterRows,
    validateRange,
} from './insightQuery.js';
import { codePoints } from './trendsFormat.js';

/** The filters that count: every row that is not blank, in the order of the form (and of the request body). */
export function activeFilters(definition) {
    return activeFilterRows(definition.filters);
}

/** The definition a page starts from: no event chosen yet, everything else at the server's defaults. */
export function defaultDefinition(defaults) {
    return {
        events: [],
        metric: defaults.metric,
        interval: defaults.interval,
        range: defaultRange(defaults),
        breakdown: { mode: 'none', column: '', property: '', topN: defaults.top_n },
        filters: [],
    };
}

/** The API body of a definition. Strings go out exactly as typed (the API takes them untouched). */
export function toRequestBody(definition, options) {
    const body = {
        events: [...definition.events],
        metric: definition.metric,
        interval: definition.interval,
    };

    Object.assign(body, rangeBody(definition.range));

    if (definition.breakdown.mode === 'column') {
        body.breakdown = { column: definition.breakdown.column, top_n: definition.breakdown.topN };
    } else if (definition.breakdown.mode === 'property') {
        body.breakdown = { property: definition.breakdown.property, top_n: definition.breakdown.topN };
    }

    body.filters = filterBody(definition.filters, options.operators);

    return body;
}

/** A lower bound of the number of buckets of a window (never above what the server computes). */
export function minBuckets(days, interval) {
    switch (interval) {
        case 'hour':
            return Math.max(1, days * 24 - 2);
        case 'week':
            return Math.max(1, Math.floor(days / 7));
        case 'month':
            return Math.max(1, Math.floor(days / 31));
        default:
            return Math.max(1, days);
    }
}

/**
 * Instant validation. Returns `{ ok, errors }`; `errors` is `{ events, date, breakdown: {column, property, top_n},
 * filters: {[id]: {key, value}}, general }` with only the entries that have a message. `ok` is false for any error;
 * an empty event list is NOT an error (it is the idle state), see `hasEvents`.
 */
export function validateDefinition(definition, props) {
    const { limits } = props;
    const errors = { breakdown: {}, filters: {} };

    if (definition.events.length > limits.max_events) {
        errors.events = `Pick at most ${limits.max_events} events.`;
    }

    const { error: dateError, days } = validateRange(definition.range, limits);
    if (dateError !== undefined) {
        errors.date = dateError;
    }

    const { breakdown } = definition;
    if (breakdown.mode === 'column' && breakdown.column === '') {
        errors.breakdown.column = 'Choose a column to break down by.';
    }
    if (breakdown.mode === 'property' && (breakdown.property === '' || codePoints(breakdown.property) > limits.max_breakdown_key_length)) {
        errors.breakdown.property = breakdown.property === '' ? 'Enter a property key.' : `The key can be at most ${limits.max_breakdown_key_length} characters.`;
    }
    if (breakdown.mode !== 'none' && !(Number.isInteger(breakdown.topN) && breakdown.topN >= 1 && breakdown.topN <= limits.max_top_n)) {
        errors.breakdown.top_n = `Show between 1 and ${limits.max_top_n} values.`;
    }

    if (definition.filters.length > limits.max_filters) {
        errors.general = `Use at most ${limits.max_filters} filters.`;
    }
    errors.filters = validateFilterRows(definition.filters, props.options.operators, limits);

    for (const event of definition.events) {
        if (codePoints(event) < 1 || codePoints(event) > limits.max_event_length) {
            errors.events = `Event names must be 1 to ${limits.max_event_length} characters.`;
        }
    }

    // Row cap: an advisory lower bound (see the file comment); the API answers with the exact rule.
    if (errors.date === undefined && days !== null && definition.events.length > 0) {
        const rows = minBuckets(days, definition.interval) * definition.events.length * (breakdown.mode === 'none' ? 1 : (Number.isInteger(breakdown.topN) ? breakdown.topN : 0) + 1);
        if (rows > limits.max_rows) {
            errors.general = `This would return up to ${rows} rows (limit ${limits.max_rows}). Use a shorter range, a larger interval, fewer events or a smaller breakdown.`;
        }
    }

    const ok = errors.events === undefined && errors.date === undefined && errors.general === undefined
        && Object.keys(errors.breakdown).length === 0 && Object.keys(errors.filters).length === 0;

    return { ok, errors };
}

export function hasEvents(definition) {
    return definition.events.length > 0;
}

/**
 * Maps the `errors` of an API 422 (`{ 'filters.0.key': ['...'] }`) onto the same shape validateDefinition() returns,
 * using the body that was sent (filters are sent in the order of `definition.filters`). Anything that belongs to no
 * single control goes to `general`.
 */
export function mapServerErrors(serverErrors, definition) {
    const errors = { breakdown: {}, filters: {} };
    const general = [];
    const first = (value) => (Array.isArray(value) ? String(value[0] ?? '') : String(value ?? ''));

    for (const [path, messages] of Object.entries(serverErrors ?? {})) {
        const message = first(messages);
        if (message === '') {
            continue;
        }

        const filter = /^filters\.(\d+)(?:\.(key|operator|value))?$/.exec(path);
        const breakdown = /^breakdown(?:\.(column|property|top_n))?$/.exec(path);

        if (/^events(\.\d+)?$/.test(path)) {
            errors.events ??= message;
        } else if (DATE_ERROR_PATH.test(path)) {
            errors.date ??= message;
        } else if (path === 'metric' || path === 'interval') {
            errors[path] = message;
        } else if (breakdown) {
            errors.breakdown[breakdown[1] ?? 'column'] ??= message;
            if (breakdown[1] === undefined) {
                // "needs exactly one of column and property": show it at the control that is in use.
                delete errors.breakdown.column;
                errors.breakdown[definition.breakdown.mode === 'property' ? 'property' : 'column'] = message;
            }
        } else if (filter) {
            const target = activeFilters(definition)[Number(filter[1])];
            if (target === undefined) {
                general.push(message);
            } else {
                placeFilterError(errors.filters, target, filter[2], message);
            }
        } else {
            general.push(message);
        }
    }

    if (general.length > 0) {
        errors.general = [...new Set(general)].join(' ');
    }

    return errors;
}

// ---------------------------------------------------------------- shareable query string

/**
 * The query string (without `?`) of a definition, defaults omitted. Events are repeated `e`, filters repeated `f`
 * (a JSON array `[key, operator, value]`), the custom range `from` + `to`.
 */
export function encodeDefinition(definition, defaults) {
    const params = new URLSearchParams();

    for (const event of definition.events) {
        params.append('e', event);
    }
    if (definition.metric !== defaults.metric) {
        params.set('m', definition.metric);
    }
    if (definition.interval !== defaults.interval) {
        params.set('i', definition.interval);
    }
    encodeRange(definition.range, defaults, params);
    if (definition.breakdown.mode === 'column') {
        params.set('b', definition.breakdown.column);
    } else if (definition.breakdown.mode === 'property') {
        params.set('bp', definition.breakdown.property);
    }
    if (definition.breakdown.mode !== 'none' && Number.isInteger(definition.breakdown.topN) && definition.breakdown.topN !== defaults.top_n) {
        params.set('bn', String(definition.breakdown.topN));
    }
    for (const filter of activeFilters(definition)) {
        params.append('f', encodeFilter(filter));
    }

    return params.toString();
}

/**
 * The definition a query string describes, or null when it describes nothing usable. NEVER trusts the URL: every
 * field is checked against the allowed values and limits of the page props, and a field that fails is dropped (the
 * rest of the definition is kept), so a hand-edited or hostile link can at worst give the default for that field.
 */
export function decodeDefinition(search, props) {
    const params = new URLSearchParams(typeof search === 'string' ? search : '');
    if ([...params.keys()].length === 0) {
        return null;
    }

    const { limits, options, defaults } = props;
    const definition = defaultDefinition(defaults);
    const allowed = (list, value) => list.some((item) => item.value === value);

    const events = [];
    for (const event of params.getAll('e')) {
        if (codePoints(event) >= 1 && codePoints(event) <= limits.max_event_length && !events.includes(event) && events.length < limits.max_events) {
            events.push(event);
        }
    }
    definition.events = events;

    const metric = params.get('m');
    if (metric !== null && allowed(options.metrics, metric)) {
        definition.metric = metric;
    }
    const interval = params.get('i');
    if (interval !== null && allowed(options.intervals, interval)) {
        definition.interval = interval;
    }

    definition.range = decodeRange(params, options.presets, defaults);

    const column = params.get('b');
    const property = params.get('bp');
    if (column !== null && allowed(options.breakdown_columns, column)) {
        definition.breakdown.mode = 'column';
        definition.breakdown.column = column;
    } else if (property !== null && codePoints(property) >= 1 && codePoints(property) <= limits.max_breakdown_key_length) {
        definition.breakdown.mode = 'property';
        definition.breakdown.property = property;
    }
    const topN = Number(params.get('bn'));
    if (params.has('bn') && Number.isInteger(topN) && topN >= 1 && topN <= limits.max_top_n) {
        definition.breakdown.topN = topN;
    }

    for (const raw of params.getAll('f').slice(0, Math.min(limits.max_filters, MAX_URL_FILTERS))) {
        const filter = decodeFilter(raw, options.operators, limits);
        if (filter !== null) {
            definition.filters.push(filter);
        }
    }

    return definition;
}
