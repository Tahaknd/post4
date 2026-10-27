// The Trends definition as the UI holds it, and everything that turns it into something else: the JSON body of
// POST /api/projects/{project}/insights/trends, the shareable query string, instant client-side validation and the
// mapping of the API's 422 field errors back onto controls.
//
// The SERVER is the source of truth for every rule. The checks here mirror its limits (read from the page props, which
// come from the domain constants) only for instant feedback, and they are deliberately never stricter than the server:
// where a rule depends on something the client cannot know exactly (the number of buckets across DST changes, "today"
// for this_month), the estimate is a lower bound, so a definition the server would accept is never blocked here.

export const PRESET_DAYS_MIN = { last_7_days: 7, last_30_days: 30, last_90_days: 90, this_month: 1, last_month: 28 };

let nextFilterId = 1;

export function newFilter(overrides = {}) {
    return { id: nextFilterId++, key: '', operator: 'equals', value: '', ...overrides };
}

/** The definition a page starts from: no event chosen yet, everything else at the server's defaults. */
export function defaultDefinition(defaults) {
    return {
        events: [],
        metric: defaults.metric,
        interval: defaults.interval,
        range: { mode: 'preset', preset: defaults.date_preset, from: '', to: '' },
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

    if (definition.range.mode === 'custom') {
        body.date_from = definition.range.from;
        body.date_to = definition.range.to;
    } else {
        body.date_preset = definition.range.preset;
    }

    if (definition.breakdown.mode === 'column') {
        body.breakdown = { column: definition.breakdown.column, top_n: definition.breakdown.topN };
    } else if (definition.breakdown.mode === 'property') {
        body.breakdown = { property: definition.breakdown.property, top_n: definition.breakdown.topN };
    }

    const needsValue = new Map(options.operators.map((operator) => [operator.value, operator.needs_value]));

    body.filters = definition.filters.map((filter) => ({
        key: filter.key,
        operator: filter.operator,
        value: needsValue.get(filter.operator) === false ? null : filter.value,
    }));

    return body;
}

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/** Whole days from `from` to `to` inclusive, or null when either is not a real calendar day. */
export function inclusiveDays(from, to) {
    const a = parseDay(from);
    const b = parseDay(to);

    return a === null || b === null ? null : Math.round((b - a) / 86400000) + 1;
}

function parseDay(value) {
    if (typeof value !== 'string' || !DATE_PATTERN.test(value)) {
        return null;
    }

    const time = Date.parse(`${value}T00:00:00Z`);

    return Number.isNaN(time) || new Date(time).toISOString().slice(0, 10) !== value ? null : time;
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

    let days = null;
    if (definition.range.mode === 'custom') {
        const { from, to } = definition.range;
        if (from === '' || to === '') {
            errors.date = 'Pick both a start and an end date.';
        } else {
            days = inclusiveDays(from, to);
            if (days === null) {
                errors.date = 'Use real calendar dates.';
            } else if (days < 1) {
                errors.date = 'The end date must not be before the start date.';
            } else if (days > limits.max_range_days) {
                errors.date = `The range can be at most ${limits.max_range_days} days.`;
            }
        }
    } else {
        days = PRESET_DAYS_MIN[definition.range.preset] ?? 1;
    }

    const { breakdown } = definition;
    if (breakdown.mode === 'column' && breakdown.column === '') {
        errors.breakdown.column = 'Choose a column to break down by.';
    }
    if (breakdown.mode === 'property' && (breakdown.property === '' || breakdown.property.length > limits.max_breakdown_key_length)) {
        errors.breakdown.property = breakdown.property === '' ? 'Enter a property key.' : `The key can be at most ${limits.max_breakdown_key_length} characters.`;
    }
    if (breakdown.mode !== 'none' && !(Number.isInteger(breakdown.topN) && breakdown.topN >= 1 && breakdown.topN <= limits.max_top_n)) {
        errors.breakdown.top_n = `Show between 1 and ${limits.max_top_n} values.`;
    }

    if (definition.filters.length > limits.max_filters) {
        errors.general = `Use at most ${limits.max_filters} filters.`;
    }
    const needsValue = new Map(props.options.operators.map((operator) => [operator.value, operator.needs_value]));
    for (const filter of definition.filters) {
        const row = {};
        if (filter.key === '') {
            row.key = 'Enter a property key.';
        } else if (filter.key.length > limits.max_filter_key_length) {
            row.key = `The key can be at most ${limits.max_filter_key_length} characters.`;
        }
        if (needsValue.get(filter.operator) !== false && filter.value.length > limits.max_filter_value_length) {
            row.value = `The value can be at most ${limits.max_filter_value_length} characters.`;
        }
        if (Object.keys(row).length > 0) {
            errors.filters[filter.id] = row;
        }
    }

    for (const event of definition.events) {
        if (event.length < 1 || event.length > limits.max_event_length) {
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
        } else if (/^(date_preset|date_from|date_to)$/.test(path)) {
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
            const target = definition.filters[Number(filter[1])];
            if (target === undefined) {
                general.push(message);
            } else {
                errors.filters[target.id] ??= {};
                errors.filters[target.id][filter[2] === 'operator' ? 'key' : (filter[2] ?? 'key')] ??= message;
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

const MAX_URL_FILTERS = 10;

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
    if (definition.range.mode === 'custom') {
        params.set('from', definition.range.from);
        params.set('to', definition.range.to);
    } else if (definition.range.preset !== defaults.date_preset) {
        params.set('p', definition.range.preset);
    }
    if (definition.breakdown.mode === 'column') {
        params.set('b', definition.breakdown.column);
    } else if (definition.breakdown.mode === 'property') {
        params.set('bp', definition.breakdown.property);
    }
    if (definition.breakdown.mode !== 'none' && definition.breakdown.topN !== defaults.top_n) {
        params.set('bn', String(definition.breakdown.topN));
    }
    for (const filter of definition.filters) {
        params.append('f', JSON.stringify([filter.key, filter.operator, filter.value]));
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
        if (event.length >= 1 && event.length <= limits.max_event_length && !events.includes(event) && events.length < limits.max_events) {
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

    const from = params.get('from');
    const to = params.get('to');
    const preset = params.get('p');
    if (from !== null && to !== null && parseDay(from) !== null && parseDay(to) !== null) {
        definition.range = { mode: 'custom', preset: defaults.date_preset, from, to };
    } else if (preset !== null && allowed(options.presets, preset)) {
        definition.range.preset = preset;
    }

    const column = params.get('b');
    const property = params.get('bp');
    if (column !== null && allowed(options.breakdown_columns, column)) {
        definition.breakdown.mode = 'column';
        definition.breakdown.column = column;
    } else if (property !== null && property.length >= 1 && property.length <= limits.max_breakdown_key_length) {
        definition.breakdown.mode = 'property';
        definition.breakdown.property = property;
    }
    const topN = Number(params.get('bn'));
    if (params.has('bn') && Number.isInteger(topN) && topN >= 1 && topN <= limits.max_top_n) {
        definition.breakdown.topN = topN;
    }

    for (const raw of params.getAll('f').slice(0, Math.min(limits.max_filters, MAX_URL_FILTERS))) {
        const filter = parseFilter(raw, props);
        if (filter !== null) {
            definition.filters.push(filter);
        }
    }

    return definition;
}

function parseFilter(raw, props) {
    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch {
        return null;
    }

    if (!Array.isArray(parsed) || parsed.length !== 3) {
        return null;
    }

    const [key, operator, value] = parsed;
    const known = props.options.operators.find((item) => item.value === operator);

    if (typeof key !== 'string' || key.length < 1 || key.length > props.limits.max_filter_key_length || known === undefined) {
        return null;
    }
    if (typeof value !== 'string' || value.length > props.limits.max_filter_value_length) {
        return null;
    }

    return newFilter({ key, operator, value });
}
