// From a saved insight (the detail of the API: `type`, `query` = the stored canonical definition, `date` = how its range is
// resolved) back to the page that edits it. There is NO new URL scheme: the canonical definition is turned into the
// definition object of its page and written with that page's own `encodeDefinition`, so the link carries the very query-string
// keys the page already reads (`e`, `m`, `s1`.., `t`, `r`, `p`, `from`, `to` ...), and the page reads it with its own
// `decodeDefinition`, which checks every field against the server's allowed values. The only addition is `saved=<uuid>`:
// "this page edits that saved insight" (Update saved insight).
//
// Every default is written out (the `EXPLICIT` props below never equal a value), so a saved insight keeps its meaning even if a
// server default changes later. Nothing here trusts the stored definition: a shape it does not understand gives null.

import { newStep, encodeDefinition as encodeFunnel } from './funnelDefinition.js';
import { newFilter } from './insightQuery.js';
import { encodeDefinition as encodeRetention } from './retentionDefinition.js';
import { encodeDefinition as encodeTrends } from './trendsDefinition.js';

export const SAVED_PARAM = 'saved';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/** Page props whose defaults match nothing: every field of a definition is written to the query string. */
const EXPLICIT = {
    defaults: { date_preset: null, metric: null, interval: null, top_n: null, window: { value: null, unit: null }, order: null, period: null, periods: null, retention_type: null, timezone: null },
    limits: { min_steps: 0 },
};

const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/** The local calendar day (`YYYY-MM-DD`) of an instant in a zone. */
export function localDay(instant, timeZone) {
    const time = typeof instant === 'number' ? instant : Date.parse(instant);
    if (!Number.isFinite(time)) {
        return null;
    }

    let parts;
    try {
        parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(time);
    } catch {
        parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(time);
    }
    const part = (type) => parts.find((item) => item.type === type)?.value;

    return `${part('year')}-${part('month')}-${part('day')}`;
}

/**
 * The range control of a page for a saved insight: a preset stays a preset; absolute days are the local days the stored
 * instants cover (`to` is exclusive: the last day is the one before it) in the zone the insight is resolved in.
 */
export function rangeOf(canonicalRange, date) {
    if (typeof date?.preset === 'string' && date.preset !== '') {
        return { mode: 'preset', preset: date.preset, from: '', to: '' };
    }
    if (!isObject(canonicalRange)) {
        return null;
    }

    const zone = typeof date?.timezone === 'string' && date.timezone !== '' ? date.timezone : 'UTC';
    const from = localDay(canonicalRange.from, zone);
    const to = localDay(Date.parse(canonicalRange.to) - 1, zone);

    return from === null || to === null ? null : { mode: 'custom', preset: '', from, to };
}

function filtersOf(list) {
    if (!Array.isArray(list)) {
        return null;
    }

    const rows = [];
    for (const filter of list) {
        if (!isObject(filter) || typeof filter.key !== 'string' || typeof filter.operator !== 'string') {
            return null;
        }
        rows.push(newFilter({ key: filter.key, operator: filter.operator, value: typeof filter.value === 'string' ? filter.value : '' }));
    }

    return rows;
}

function trendsDefinition(query, range) {
    const filters = filtersOf(query.filters);
    if (!Array.isArray(query.events) || !query.events.every((event) => typeof event === 'string') || filters === null || typeof query.metric !== 'string' || typeof query.interval !== 'string') {
        return null;
    }

    const breakdown = { mode: 'none', column: '', property: '', topN: 10 };
    if (isObject(query.breakdown)) {
        breakdown.topN = Number.isInteger(query.breakdown.top_n) ? query.breakdown.top_n : 10;
        if (typeof query.breakdown.column === 'string') {
            Object.assign(breakdown, { mode: 'column', column: query.breakdown.column });
        } else if (typeof query.breakdown.property === 'string') {
            Object.assign(breakdown, { mode: 'property', property: query.breakdown.property });
        }
    }

    return { events: [...query.events], metric: query.metric, interval: query.interval, range, breakdown, filters };
}

function funnelDefinition(query, range) {
    if (!Array.isArray(query.steps) || !isObject(query.window) || typeof query.order !== 'string') {
        return null;
    }

    const steps = [];
    for (const step of query.steps) {
        const filters = isObject(step) && typeof step.event === 'string' ? filtersOf(step.filters) : null;
        if (filters === null) {
            return null;
        }
        steps.push(newStep({ event: step.event, filters }));
    }

    return { steps, window: { value: String(query.window.value), unit: String(query.window.unit) }, order: query.order, range };
}

function conditionOf(condition) {
    const filters = isObject(condition) && typeof condition.event === 'string' ? filtersOf(condition.filters) : null;

    return filters === null ? null : { event: condition.event, filters };
}

const sameCondition = (a, b) => a.event === b.event && JSON.stringify(a.filters.map(({ key, operator, value }) => [key, operator, value])) === JSON.stringify(b.filters.map(({ key, operator, value }) => [key, operator, value]));

function retentionDefinition(query, range) {
    const target = conditionOf(query.target);
    const returning = conditionOf(query.returning);
    if (target === null || returning === null || typeof query.period !== 'string' || typeof query.type !== 'string' || typeof query.timezone !== 'string' || !Number.isInteger(query.periods)) {
        return null;
    }

    const same = sameCondition(target, returning);

    return { target, returning: same ? { event: '', filters: [] } : returning, same, period: query.period, periods: String(query.periods), type: query.type, timezone: query.timezone, range };
}

const DEFINITIONS = { trends: trendsDefinition, funnel: funnelDefinition, retention: retentionDefinition };

/** The definition object of the page of `type` for a stored canonical definition, or null when it cannot be read. */
export function canonicalToDefinition(type, canonical, date) {
    const build = DEFINITIONS[type];
    const range = isObject(canonical) ? rangeOf(canonical.range, date) : null;

    return build === undefined || !isObject(canonical) || range === null ? null : build(canonical, range);
}

/** The query string (without `?`, without `saved`) that makes the page of `type` show a saved insight, or null. */
export function canonicalToQuery(type, saved) {
    const definition = canonicalToDefinition(type, saved?.query, saved?.date);
    if (definition === null) {
        return null;
    }

    switch (type) {
        case 'trends':
            return encodeTrends(definition, EXPLICIT.defaults);
        case 'funnel':
            return encodeFunnel(definition, EXPLICIT);
        default:
            return encodeRetention(definition, EXPLICIT);
    }
}

/** `saved=<uuid>` added to a query string (replacing one that is there); the id must be a uuid, else the query is returned as it is. */
export function withSaved(query, id) {
    const params = new URLSearchParams(query);
    params.delete(SAVED_PARAM);
    if (typeof id === 'string' && UUID.test(id)) {
        params.set(SAVED_PARAM, id);
    }

    return params.toString();
}

/** The uuid of the `saved` key of a query string, or null (anything that is not a uuid is ignored). */
export function savedIdOf(search) {
    const id = new URLSearchParams(typeof search === 'string' ? search : '').get(SAVED_PARAM);

    return id !== null && UUID.test(id) ? id : null;
}

/** The query string of a page without its `saved` key (what the page's own decodeDefinition reads). */
export function withoutSaved(search) {
    const params = new URLSearchParams(typeof search === 'string' ? search : '');
    params.delete(SAVED_PARAM);

    return params.toString();
}

/**
 * The address of the page that edits a saved insight: the page's path, the definition in its own keys and `saved=<id>`.
 * Without the stored definition (a list row only has the summary) or for one that cannot be read it is the path with
 * `saved=<id>` alone: the page then loads the insight and fills its form itself.
 */
export function openUrl(pagePath, saved) {
    const query = canonicalToQuery(saved?.type, saved) ?? '';
    const full = withSaved(query, saved?.id);

    return full === '' ? pagePath : `${pagePath}?${full}`;
}
