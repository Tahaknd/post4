// The Funnel definition as the UI holds it, and everything that turns it into something else: the step list operations,
// the JSON body of POST /api/projects/{project}/insights/funnel, the shareable query string, instant client-side validation
// and the mapping of the API's 422 field errors back onto controls. The pieces every insight page shares (filter rows, the
// date range) are in insightQuery.js; the SERVER is the source of truth for every rule, and the checks here only mirror its
// limits (the page props, from the domain constants) for instant feedback, never stricter than the server.

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
    placeFilterError,
    rangeBody,
    validateFilterRows,
    validateRange,
} from './insightQuery.js';
import { codePoints } from './trendsFormat.js';

// ---------------------------------------------------------------- steps

let nextStepId = 1;

/** A step nobody has filled in yet: no event, no filters. `id` is stable while the step is moved around. */
export function newStep(overrides = {}) {
    return { id: nextStepId++, event: '', filters: [], ...overrides };
}

/** The definition a page starts from: the minimum number of blank steps, everything else at the server's defaults. */
export function defaultDefinition(props) {
    const { defaults, limits } = props;

    return {
        steps: Array.from({ length: limits.min_steps }, () => newStep()),
        window: { value: String(defaults.window.value), unit: defaults.window.unit },
        order: defaults.order,
        range: defaultRange(defaults),
    };
}

/** The event of a step as it is sent and counted: the name without the spaces around what was typed. */
export function stepEvent(step) {
    return step.event.trim();
}

export function canAddStep(steps, limits) {
    return steps.length < limits.max_steps;
}

export function canRemoveStep(steps, limits) {
    return steps.length > limits.min_steps;
}

/** The steps with a new blank one at the end (the same list when the limit is reached). */
export function addStep(steps, limits) {
    return canAddStep(steps, limits) ? [...steps, newStep()] : steps;
}

/** The steps without `id` (the same list when the minimum would be undercut or `id` is not there). */
export function removeStep(steps, id, limits) {
    return canRemoveStep(steps, limits) && steps.some((step) => step.id === id) ? steps.filter((step) => step.id !== id) : steps;
}

/** Whether the step can move `delta` places (-1 up, 1 down) inside the list. */
export function canMoveStep(steps, id, delta) {
    const index = steps.findIndex((step) => step.id === id);

    return index !== -1 && index + delta >= 0 && index + delta < steps.length;
}

/** The steps with `id` moved `delta` places (-1 up, 1 down): a new list, or the same one when it cannot move. */
export function moveStep(steps, id, delta) {
    if (!canMoveStep(steps, id, delta)) {
        return steps;
    }

    const index = steps.findIndex((step) => step.id === id);
    const next = [...steps];
    [next[index], next[index + delta]] = [next[index + delta], next[index]];

    return next;
}

/** The steps with the fields of one step changed. */
export function patchStep(steps, id, changes) {
    return steps.map((step) => (step.id === id ? { ...step, ...changes } : step));
}

// ---------------------------------------------------------------- the conversion window

const WINDOW_VALUE = /^\d{1,6}$/;

/** The window in seconds, or null when the value is not a whole number or the unit is unknown. */
export function windowSeconds(window, options) {
    const unit = options.window_units.find((item) => item.value === window.unit);

    return unit === undefined || !WINDOW_VALUE.test(String(window.value).trim()) ? null : Number(String(window.value).trim()) * unit.seconds;
}

/** A duration in the largest unit that divides it exactly: 1209600 is "2 weeks", 5400 is "90 minutes". */
export function formatDuration(seconds) {
    for (const [unit, length] of [['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]]) {
        if (seconds % length === 0) {
            const count = seconds / length;

            return `${count} ${unit}${count === 1 ? '' : 's'}`;
        }
    }

    return `${seconds} seconds`;
}

// ---------------------------------------------------------------- the API body

/** The API body of a definition. Event names go out without the spaces around them, everything else as typed. */
export function toRequestBody(definition, props) {
    const { options } = props;

    return {
        steps: definition.steps.map((step) => ({ event: stepEvent(step), filters: filterBody(step.filters, options.operators) })),
        window: { value: Number(String(definition.window.value).trim()), unit: definition.window.unit },
        order: definition.order,
        ...rangeBody(definition.range),
    };
}

// ---------------------------------------------------------------- instant validation

/**
 * Returns `{ ok, errors }`. `errors` is `{ steps: {[id]: {event?, filters: {[rowId]: {key?, value?}}}}, window, date, general }`
 * with only the entries that have a message. A step without an event is an error here (it blocks running); the page shows
 * it only for steps the user has been through, or after a Run that was refused.
 */
export function validateDefinition(definition, props) {
    const { limits, options } = props;
    const errors = { steps: {} };

    if (definition.steps.length < limits.min_steps || definition.steps.length > limits.max_steps) {
        errors.general = `A funnel needs ${limits.min_steps} to ${limits.max_steps} steps.`;
    }

    let filters = 0;
    definition.steps.forEach((step, index) => {
        const entry = {};
        const event = stepEvent(step);

        if (event === '') {
            entry.event = `Choose an event for step ${index + 1}.`;
        } else if (codePoints(event) > limits.max_event_length) {
            entry.event = `Event names are 1 to ${limits.max_event_length} characters.`;
        }

        const active = activeFilterRows(step.filters);
        filters += active.length;
        const rows = validateFilterRows(step.filters, options.operators, limits);
        if (Object.keys(rows).length > 0) {
            entry.filters = rows;
        }
        if (active.length > limits.max_filters_per_step) {
            entry.general = `Use at most ${limits.max_filters_per_step} filters per step.`;
        }

        if (Object.keys(entry).length > 0) {
            errors.steps[step.id] = entry;
        }
    });
    if (filters > limits.max_filters && errors.general === undefined) {
        errors.general = `Use at most ${limits.max_filters} filters over all steps.`;
    }

    const seconds = windowSeconds(definition.window, options);
    const windowValue = Number(String(definition.window.value).trim());
    if (seconds === null || windowValue < 1 || windowValue > limits.max_window_value) {
        errors.window = `Enter the window as a whole number from 1 to ${limits.max_window_value}.`;
    } else if (seconds < limits.min_window_seconds || seconds > limits.max_window_seconds) {
        errors.window = `The window must be between ${formatDuration(limits.min_window_seconds)} and ${formatDuration(limits.max_window_seconds)}.`;
    }

    const { error: dateError } = validateRange(definition.range, limits);
    if (dateError !== undefined) {
        errors.date = dateError;
    }

    if (!options.orders.some((order) => order.value === definition.order)) {
        errors.general ??= 'Choose how strictly the steps must follow each other.';
    }

    const ok = Object.keys(errors.steps).length === 0 && errors.window === undefined && errors.date === undefined && errors.general === undefined;

    return { ok, errors: { ...errors, steps: errors.steps } };
}

/**
 * Maps the `errors` of an API 422 (`{ 'steps.1.filters.0.key': ['...'] }`) onto the shape validateDefinition() returns, using
 * the definition the body was built from (steps and the non-blank filter rows go out in the order of the form). Anything that
 * belongs to no single control goes to `general`.
 */
export function mapServerErrors(serverErrors, definition) {
    const errors = { steps: {} };
    const general = [];
    const first = (value) => (Array.isArray(value) ? String(value[0] ?? '') : String(value ?? ''));

    for (const [path, messages] of Object.entries(serverErrors ?? {})) {
        const message = first(messages);
        if (message === '') {
            continue;
        }

        const step = /^steps\.(\d+)(?:\.(event|filters))?(?:\.(\d+))?(?:\.(key|operator|value))?$/.exec(path);
        const target = step === null ? undefined : definition.steps[Number(step[1])];

        if (DATE_ERROR_PATH.test(path)) {
            errors.date ??= message;
        } else if (/^window(\.(value|unit))?$/.test(path)) {
            errors.window ??= message;
        } else if (path === 'order') {
            errors.order = message;
        } else if (target !== undefined && step[2] === 'event') {
            errors.steps[target.id] ??= {};
            errors.steps[target.id].event ??= message;
        } else if (target !== undefined && step[2] === 'filters' && step[3] !== undefined) {
            const row = activeFilterRows(target.filters)[Number(step[3])];
            if (row === undefined) {
                general.push(message);
            } else {
                errors.steps[target.id] ??= {};
                errors.steps[target.id].filters ??= {};
                placeFilterError(errors.steps[target.id].filters, row, step[4], message);
            }
        } else if (target !== undefined && step[2] === undefined) {
            errors.steps[target.id] ??= {};
            errors.steps[target.id].event ??= message;
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
 * The query string (without `?`) of a definition, defaults omitted. Step number n is `s<n>`: the JSON of
 * `[event, [[key, operator, value], ...]]` (blank steps too, so positions survive); the window `wv` + `wu`, the order `o`,
 * the range `p` or `from` + `to`. A definition that is still the untouched default gives an empty string.
 *
 * Every step has a key of its own, never a repeated one: the server hands the page its address in a normalised form
 * (Request::fullUrl() sorts the keys and keeps only the LAST of a repeated key), and the browser's address bar is rewritten
 * to it when the page opens, so a repeated `s` would lose all its steps but one on the first reload.
 */
export function encodeDefinition(definition, props) {
    const { defaults, limits } = props;
    const params = new URLSearchParams();

    const untouched = definition.steps.length === limits.min_steps && definition.steps.every((step) => step.event === '' && activeFilterRows(step.filters).length === 0);
    if (!untouched) {
        definition.steps.forEach((step, index) => {
            params.set(`s${index + 1}`, JSON.stringify([step.event, activeFilterRows(step.filters).map(filterTuple)]));
        });
    }

    const value = String(definition.window.value);
    if (value !== String(defaults.window.value) || definition.window.unit !== defaults.window.unit) {
        params.set('wv', value);
        params.set('wu', definition.window.unit);
    }
    if (definition.order !== defaults.order) {
        params.set('o', definition.order);
    }
    encodeRange(definition.range, defaults, params);

    return params.toString();
}

/**
 * The definition a query string describes, or null when it describes nothing usable. NEVER trusts the URL: every field is
 * checked against the allowed values and limits of the page props, and a field that fails is dropped (the rest is kept), so
 * a hand-edited or hostile link can at worst give the default for that field. A list of steps shorter than the minimum is
 * filled up with blank steps, a longer one is cut at the maximum.
 */
export function decodeDefinition(search, props) {
    const params = new URLSearchParams(typeof search === 'string' ? search : '');
    if ([...params.keys()].length === 0) {
        return null;
    }

    const { limits, options, defaults } = props;
    const definition = defaultDefinition(props);

    const steps = [];
    let filters = 0;
    for (let n = 1; n <= limits.max_steps; n++) {
        const raw = params.get(`s${n}`);
        const step = raw === null ? null : decodeStep(raw, props, limits.max_filters - filters);
        if (step !== null) {
            filters += step.filters.length;
            steps.push(step);
        }
    }
    while (steps.length < limits.min_steps) {
        steps.push(newStep());
    }
    definition.steps = steps;

    const value = params.get('wv');
    const unit = params.get('wu');
    if (value !== null && WINDOW_VALUE.test(value) && unit !== null && options.window_units.some((item) => item.value === unit)) {
        definition.window = { value: String(Number(value)), unit };
    }

    const order = params.get('o');
    if (order !== null && options.orders.some((item) => item.value === order)) {
        definition.order = order;
    }

    definition.range = decodeRange(params, options.presets, defaults);

    return definition;
}

function decodeStep(raw, props, filtersLeft) {
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
    const room = Math.max(0, Math.min(limits.max_filters_per_step, MAX_URL_FILTERS, filtersLeft));
    for (const tuple of parsed[1].slice(0, room)) {
        const filter = decodeFilterTuple(tuple, options.operators, limits);
        if (filter !== null) {
            filters.push(filter);
        }
    }

    return newStep({ event: parsed[0], filters });
}
