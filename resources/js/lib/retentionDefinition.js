// The Retention definition as the UI holds it, and everything that turns it into something else: the JSON body of
// POST /api/projects/{project}/insights/retention, the shareable query string, instant client-side validation and the mapping
// of the API's 422 field errors back onto controls. The pieces every insight page shares (filter rows, the date range) are in
// insightQuery.js; the SERVER is the source of truth for every rule, and the checks here only mirror its limits (the page
// props, from the domain constants) for instant feedback, never stricter than the server.
//
// A definition has two roles, each an event with up to `max_filters_per_event` property filters: the COHORT event ("people
// first do ...", `target` in the API) and the RETURN event ("... and come back to do", `returning`). `same` is a convenience:
// the return event is then the cohort event, filters included, and the form shows no second event.

import {
    DATE_ERROR_PATH,
    MAX_URL_FILTERS,
    activeFilterRows,
    decodeFilterTuple,
    decodeRange,
    defaultRange,
    encodeRange,
    filterBody,
    filterTuple,
    newFilter,
    placeFilterError,
    rangeBody,
    validateFilterRows,
    validateRange,
} from './insightQuery.js';
import { codePoints } from './trendsFormat.js';

export const ROLES = ['target', 'returning'];

/** The role names for the sentences of messages and labels. */
export const ROLE_NAMES = { target: 'cohort event', returning: 'return event' };

const PERIODS_PATTERN = /^\d{1,3}$/;

// ---------------------------------------------------------------- the definition

/** One role: an event name and its filters. Nothing chosen yet. */
export function newCondition() {
    return { event: '', filters: [] };
}

/** The definition a page starts from: nothing chosen, everything else at the server's defaults. */
export function defaultDefinition(props) {
    const { defaults } = props;

    return {
        target: newCondition(),
        returning: newCondition(),
        same: false,
        period: defaults.period,
        periods: String(defaults.periods),
        type: defaults.retention_type,
        timezone: defaults.timezone,
        range: defaultRange(defaults),
    };
}

/** The event of a role as it is sent and counted: the name without the spaces around what was typed. */
export function conditionEvent(condition) {
    return condition.event.trim();
}

/** The role that counts as the return event: the cohort event itself when `same` is on. */
export function returningOf(definition) {
    return definition.same ? definition.target : definition.returning;
}

/** A copy of a role with fresh filter ids (two roles never share a row id: the page tracks "was it left" by id). */
export function copyCondition(condition) {
    return { event: condition.event, filters: condition.filters.map((filter) => newFilter({ key: filter.key, operator: filter.operator, value: filter.value })) };
}

/** The whole number of periods that was typed, or null when it is not one (empty, a decimal, a sign, a letter). */
export function periodsNumber(text) {
    const value = String(text).trim();

    return PERIODS_PATTERN.test(value) ? Number(value) : null;
}

// ---------------------------------------------------------------- the API body

function conditionBody(condition, props) {
    return { event: conditionEvent(condition), filters: filterBody(condition.filters, props.options.operators) };
}

/** The API body of a definition. Event names go out without the spaces around them, everything else as typed. */
export function toRequestBody(definition, props) {
    return {
        target: conditionBody(definition.target, props),
        returning: conditionBody(returningOf(definition), props),
        period: definition.period,
        periods: periodsNumber(definition.periods) ?? definition.periods,
        retention_type: definition.type,
        timezone: definition.timezone,
        ...rangeBody(definition.range),
    };
}

// ---------------------------------------------------------------- instant validation

/**
 * Returns `{ ok, errors }`. `errors` is `{ target: {event?, filters?, general?}, returning: {...}, period, periods, type,
 * timezone, date, general }` with only the entries that have a message. An empty event is an error here (it blocks running);
 * the page shows it only for a field the user has been through, or after a Run that was refused. The return event is not
 * checked while it is the cohort event.
 */
export function validateDefinition(definition, props) {
    const { limits, options } = props;
    const errors = {};

    for (const role of definition.same ? ['target'] : ROLES) {
        const condition = definition[role];
        const entry = {};
        const event = conditionEvent(condition);

        if (event === '') {
            entry.event = `Choose the ${ROLE_NAMES[role]}.`;
        } else if (codePoints(event) > limits.max_event_length) {
            entry.event = `Event names are 1 to ${limits.max_event_length} characters.`;
        }

        const rows = validateFilterRows(condition.filters, options.operators, limits);
        if (Object.keys(rows).length > 0) {
            entry.filters = rows;
        }
        if (activeFilterRows(condition.filters).length > limits.max_filters_per_event) {
            entry.general = `Use at most ${limits.max_filters_per_event} filters per event.`;
        }

        if (Object.keys(entry).length > 0) {
            errors[role] = entry;
        }
    }

    const periods = periodsNumber(definition.periods);
    if (periods === null || periods < limits.min_periods || periods > limits.max_periods) {
        errors.periods = `Enter the number of periods as a whole number from ${limits.min_periods} to ${limits.max_periods}.`;
    }
    if (!options.periods.some((item) => item.value === definition.period)) {
        errors.period = 'Choose day, week or month.';
    }
    if (!options.retention_types.some((item) => item.value === definition.type)) {
        errors.type = 'Choose a retention type.';
    }
    if (!options.timezones.includes(definition.timezone)) {
        errors.timezone = 'Choose a time zone from the list.';
    }

    const { error: dateError } = validateRange(definition.range, limits);
    if (dateError !== undefined) {
        errors.date = dateError;
    }

    return { ok: Object.keys(errors).length === 0, errors };
}

/**
 * Maps the `errors` of an API 422 (`{ 'target.filters.0.key': ['...'] }`) onto the shape validateDefinition() returns, using
 * the definition the body was built from (the non-blank filter rows go out in the order of the form). While the return event
 * is the cohort event its messages go to the cohort event, whose rows they describe. Anything that belongs to no single
 * control goes to `general`.
 */
export function mapServerErrors(serverErrors, definition) {
    const errors = {};
    const general = [];
    const first = (value) => (Array.isArray(value) ? String(value[0] ?? '') : String(value ?? ''));

    for (const [path, messages] of Object.entries(serverErrors ?? {})) {
        const message = first(messages);
        if (message === '') {
            continue;
        }

        const role = /^(target|returning)(?:\.(event|filters))?(?:\.(\d+))?(?:\.(key|operator|value))?$/.exec(path);
        const owner = role === null ? undefined : definition.same ? 'target' : role[1];

        if (DATE_ERROR_PATH.test(path)) {
            errors.date ??= message;
        } else if (path === 'period') {
            errors.period ??= message;
        } else if (path === 'periods') {
            errors.periods ??= message;
        } else if (path === 'retention_type') {
            errors.type ??= message;
        } else if (path === 'timezone') {
            errors.timezone ??= message;
        } else if (owner !== undefined && (role[2] === 'event' || role[2] === undefined)) {
            errors[owner] ??= {};
            errors[owner].event ??= message;
        } else if (owner !== undefined && role[2] === 'filters' && role[3] === undefined) {
            errors[owner] ??= {};
            errors[owner].general ??= message;
        } else if (owner !== undefined && role[2] === 'filters') {
            const row = activeFilterRows(definition[owner].filters)[Number(role[3])];
            if (row === undefined) {
                general.push(message);
            } else {
                errors[owner] ??= {};
                errors[owner].filters ??= {};
                placeFilterError(errors[owner].filters, row, role[4], message);
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

const isBlank = (condition) => condition.event === '' && activeFilterRows(condition.filters).length === 0;

const conditionParam = (condition) => JSON.stringify([condition.event, activeFilterRows(condition.filters).map(filterTuple)]);

/**
 * The query string (without `?`) of a definition, defaults omitted. The cohort event is `t` and the return event `r`, each
 * the JSON of `[event, [[key, operator, value], ...]]`; `same=1` instead of `r` while the return event is the cohort event;
 * `pd` the period, `n` the number of periods, `rt` the retention type, `tz` the time zone, and the range as `p` or `from` +
 * `to`. A definition that is still the untouched default gives an empty string.
 *
 * Every field has a key of its own, never a repeated one (see encodeDefinition of the funnel for why).
 */
export function encodeDefinition(definition, props) {
    const { defaults } = props;
    const params = new URLSearchParams();

    if (!isBlank(definition.target)) {
        params.set('t', conditionParam(definition.target));
    }
    if (definition.same) {
        params.set('same', '1');
    } else if (!isBlank(definition.returning)) {
        params.set('r', conditionParam(definition.returning));
    }
    if (definition.period !== defaults.period) {
        params.set('pd', definition.period);
    }
    if (definition.periods !== String(defaults.periods)) {
        params.set('n', definition.periods);
    }
    if (definition.type !== defaults.retention_type) {
        params.set('rt', definition.type);
    }
    if (definition.timezone !== defaults.timezone) {
        params.set('tz', definition.timezone);
    }
    encodeRange(definition.range, defaults, params);

    return params.toString();
}

/**
 * The definition a query string describes, or null when it describes nothing at all. NEVER trusts the URL: every field is
 * checked against the allowed values and limits of the page props, and a field that fails is dropped (the rest is kept), so a
 * hand-edited or hostile link can at worst give the default for that field.
 */
export function decodeDefinition(search, props) {
    const params = new URLSearchParams(typeof search === 'string' ? search : '');
    if ([...params.keys()].length === 0) {
        return null;
    }

    const { limits, options } = props;
    const definition = defaultDefinition(props);

    definition.same = params.get('same') === '1';
    definition.target = decodeCondition(params.get('t'), props) ?? newCondition();
    if (!definition.same) {
        definition.returning = decodeCondition(params.get('r'), props) ?? newCondition();
    }

    const period = params.get('pd');
    if (period !== null && options.periods.some((item) => item.value === period)) {
        definition.period = period;
    }

    const periods = params.get('n');
    if (periods !== null && PERIODS_PATTERN.test(periods) && Number(periods) >= limits.min_periods && Number(periods) <= limits.max_periods) {
        definition.periods = String(Number(periods));
    }

    const type = params.get('rt');
    if (type !== null && options.retention_types.some((item) => item.value === type)) {
        definition.type = type;
    }

    const timezone = params.get('tz');
    if (timezone !== null && options.timezones.includes(timezone)) {
        definition.timezone = timezone;
    }

    definition.range = decodeRange(params, options.presets, props.defaults);

    return definition;
}

function decodeCondition(raw, props) {
    if (raw === null) {
        return null;
    }

    const { limits, options } = props;
    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch {
        return null;
    }

    if (!Array.isArray(parsed) || parsed.length !== 2 || typeof parsed[0] !== 'string' || !Array.isArray(parsed[1]) || codePoints(parsed[0]) > limits.max_event_length) {
        return null;
    }

    const filters = [];
    for (const tuple of parsed[1].slice(0, Math.min(limits.max_filters_per_event, MAX_URL_FILTERS))) {
        const filter = decodeFilterTuple(tuple, options.operators, limits);
        if (filter !== null) {
            filters.push(filter);
        }
    }

    return { event: parsed[0], filters };
}
