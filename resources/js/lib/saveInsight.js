// What "Save insight" sends and how its answers come back onto the dialog's fields.
//
// The body of a saved insight is `{ name, description?, type, query }` where `query` is EXACTLY the body the page sends to
// `POST insights/{type}` (the page's own body builder): a preset in the form stays `date_preset` (the saved insight keeps the
// range relative and the server re-resolves it on every read and run), an absolute range stays `date_from` + `date_to`. This
// file never builds or rewrites a query, it only wraps one.

import { codePoints } from './trendsFormat.js';

export const NAME_MAX = 120;
export const DESCRIPTION_MAX = 500;

/** Whether a query keeps its range relative (a `date_preset`) rather than as absolute days. */
export const isRelative = (query) => typeof query?.date_preset === 'string' && query.date_preset !== '';

/** The body of `POST saved-insights`. The description is left out when it is blank. */
export function buildSaveBody({ type, name, description, query }) {
    const body = { name: String(name ?? '').trim(), type, query };
    const note = String(description ?? '').trim();
    if (note !== '') {
        body.description = note;
    }

    return body;
}

/** The body of `PATCH saved-insights/{id}` that replaces the definition only (how a saved insight is updated or repaired). */
export const buildUpdateBody = (query) => ({ query });

/** Instant messages for the two labels (`{ name?, description? }`); the server is the source of truth. */
export function validateLabels({ name, description }, limits = { name: NAME_MAX, description: DESCRIPTION_MAX }) {
    const errors = {};
    const trimmed = String(name ?? '').trim();

    if (trimmed === '') {
        errors.name = 'Enter a name.';
    } else if (codePoints(trimmed) > limits.name) {
        errors.name = `The name can be at most ${limits.name} characters.`;
    }
    if (codePoints(String(description ?? '').trim()) > limits.description) {
        errors.description = `The description can be at most ${limits.description} characters.`;
    }

    return errors;
}

/**
 * Puts the `errors` of a 422 (`{ path: message }`) on the fields: `name` and `description` on theirs, everything else (the
 * definition under `query.*`, a limit, the type, the body) as one general sentence.
 */
export function mapSaveErrors(errors) {
    const fields = {};
    const general = [];

    for (const [path, message] of Object.entries(errors ?? {})) {
        if (path === 'name' || path === 'description') {
            fields[path] ??= message;
        } else if (path.startsWith('query')) {
            general.push(message);
        } else {
            general.push(message);
        }
    }
    if (general.length > 0) {
        fields.general = [...new Set(general)].join(' ');
    }

    return fields;
}

/**
 * The plan of one save: what to create and in what order. `target` is `{ mode: 'none' }`, `{ mode: 'existing', id }` or
 * `{ mode: 'new', name }`. A step that is already done (the insight exists after a failed dashboard step) is skipped.
 */
export function savePlan({ target, insightId = null, dashboardId = null }) {
    const steps = [];
    if (insightId === null) {
        steps.push('insight');
    }
    if (target.mode === 'new' && dashboardId === null) {
        steps.push('dashboard');
    }
    if (target.mode !== 'none') {
        steps.push('tile');
    }

    return steps;
}
