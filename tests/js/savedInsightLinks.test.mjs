import assert from 'node:assert/strict';
import test from 'node:test';

import { decodeDefinition as decodeFunnel, toRequestBody as funnelBody } from '../../resources/js/lib/funnelDefinition.js';
import { decodeDefinition as decodeRetention, toRequestBody as retentionBody } from '../../resources/js/lib/retentionDefinition.js';
import { canonicalToDefinition, canonicalToQuery, localDay, openUrl, rangeOf, savedIdOf, withSaved, withoutSaved } from '../../resources/js/lib/savedInsightLinks.js';
import { decodeDefinition as decodeTrends, toRequestBody as trendsBody } from '../../resources/js/lib/trendsDefinition.js';

const ID = '0197a3b2-1c4d-7e5f-8a6b-7c8d9e0f1a2b';
const OPERATORS = [{ value: 'equals', needs_value: true }, { value: 'not_equals', needs_value: true }, { value: 'contains', needs_value: true }, { value: 'is_set', needs_value: false }];
const PRESETS = ['last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'last_month'].map((value) => ({ value }));
const FILTER_LIMITS = { max_filter_key_length: 200, max_filter_value_length: 1000, max_event_length: 200, max_range_days: 366 };

const TRENDS_PROPS = {
    options: { presets: PRESETS, operators: OPERATORS, metrics: [{ value: 'total_events' }, { value: 'unique_users' }], intervals: ['hour', 'day', 'week', 'month'].map((value) => ({ value })), breakdown_columns: [{ value: 'browser' }] },
    defaults: { date_preset: 'last_30_days', interval: 'day', metric: 'total_events', top_n: 10 },
    limits: { ...FILTER_LIMITS, max_events: 10, max_filters: 10, max_breakdown_key_length: 200, max_top_n: 25 },
};
const FUNNEL_PROPS = {
    options: { presets: PRESETS, operators: OPERATORS, window_units: ['minute', 'hour', 'day', 'week'].map((value) => ({ value })), orders: [{ value: 'ordered' }, { value: 'strict_order' }] },
    defaults: { date_preset: 'last_30_days', window: { value: 2, unit: 'week' }, order: 'ordered' },
    limits: { ...FILTER_LIMITS, min_steps: 2, max_steps: 8, max_filters_per_step: 5, max_filters: 20 },
};
const RETENTION_PROPS = {
    options: { presets: PRESETS, operators: OPERATORS, periods: ['day', 'week', 'month'].map((value) => ({ value })), retention_types: [{ value: 'first_time' }, { value: 'recurring' }], timezones: ['UTC', 'Europe/Istanbul'] },
    defaults: { date_preset: 'last_30_days', period: 'day', periods: 8, retention_type: 'first_time', timezone: 'Europe/Istanbul' },
    limits: { ...FILTER_LIMITS, min_periods: 1, max_periods: 30, max_filters_per_event: 5 },
};

// Stored definitions as the server writes them (canonical arrays) for a range of local days in Europe/Istanbul (UTC+3).
const RANGE = { from: '2026-10-01T21:00:00.000Z', to: '2026-10-08T21:00:00.000Z' }; // local days 2026-10-02 .. 2026-10-08
const filter = { key: 'plan', operator: 'equals', value: 'pro' };
const TRENDS = { v: 1, events: ['signup', 'login'], metric: 'unique_users', range: RANGE, interval: 'week', timezone: 'Europe/Istanbul', breakdown: { column: 'browser', property: null, top_n: 5 }, filters: [filter, { key: 'beta', operator: 'is_set', value: null }] };
const FUNNEL = { v: 1, steps: [{ event: 'a', filters: [filter] }, { event: 'b', filters: [] }, { event: 'c', filters: [] }], window: { value: 3, unit: 'day' }, range: RANGE, order: 'strict_order' };
const RETENTION = { v: 1, target: { event: 'signup', filters: [filter] }, returning: { event: 'login', filters: [] }, period: 'week', periods: 6, type: 'recurring', timezone: 'UTC', range: RANGE };
const ABSOLUTE = { preset: null, timezone: 'Europe/Istanbul' };
const RELATIVE = { preset: 'last_7_days', timezone: 'Europe/Istanbul' };

const saved = (type, query, date) => ({ id: ID, type, query, date });
const search = (type, query, date) => `?${canonicalToQuery(type, saved(type, query, date))}`;

test('the local day of an instant, and the last day of an exclusive end', () => {
    assert.equal(localDay('2026-10-01T21:00:00.000Z', 'Europe/Istanbul'), '2026-10-02');
    assert.equal(localDay('2026-10-01T21:00:00.000Z', 'UTC'), '2026-10-01');
    assert.equal(localDay('2026-10-01T21:00:00.000Z', 'Not/AZone'), '2026-10-01');
    assert.equal(localDay('garbage', 'UTC'), null);
    assert.deepEqual(rangeOf(RANGE, ABSOLUTE), { mode: 'custom', preset: '', from: '2026-10-02', to: '2026-10-08' });
    assert.deepEqual(rangeOf(RANGE, RELATIVE), { mode: 'preset', preset: 'last_7_days', from: '', to: '' });
    assert.equal(rangeOf('nope', ABSOLUTE), null);
});

test('a trends definition survives canonical -> link -> the page decode -> the page body, preset and absolute', () => {
    for (const date of [RELATIVE, ABSOLUTE]) {
        const definition = decodeTrends(search('trends', TRENDS, date), TRENDS_PROPS);
        const body = trendsBody(definition, TRENDS_PROPS.options);

        assert.deepEqual(body.events, ['signup', 'login']);
        assert.equal(body.metric, 'unique_users');
        assert.equal(body.interval, 'week');
        assert.deepEqual(body.breakdown, { column: 'browser', top_n: 5 });
        assert.deepEqual(body.filters, [filter, { key: 'beta', operator: 'is_set', value: null }]);
        if (date === RELATIVE) {
            assert.equal(body.date_preset, 'last_7_days');
            assert.equal('date_from' in body, false);
        } else {
            assert.deepEqual([body.date_from, body.date_to], ['2026-10-02', '2026-10-08']);
            assert.equal('date_preset' in body, false);
        }
    }
});

test('a funnel definition round-trips, steps and filters in order, default values written out', () => {
    const query = canonicalToQuery('funnel', saved('funnel', FUNNEL, ABSOLUTE));
    assert.match(query, /(^|&)o=strict_order(&|$)/);
    const body = funnelBody(decodeFunnel(`?${query}`, FUNNEL_PROPS), FUNNEL_PROPS);

    assert.deepEqual(body.steps, [{ event: 'a', filters: [filter] }, { event: 'b', filters: [] }, { event: 'c', filters: [] }]);
    assert.deepEqual(body.window, { value: 3, unit: 'day' });
    assert.equal(body.order, 'strict_order');
    assert.deepEqual([body.date_from, body.date_to], ['2026-10-02', '2026-10-08']);

    // a window and an order that equal today's defaults are still in the link: the link keeps its meaning if a default changes
    const defaults = { ...FUNNEL, window: { value: 2, unit: 'week' }, order: 'ordered' };
    const link = canonicalToQuery('funnel', saved('funnel', defaults, RELATIVE));
    assert.match(link, /wv=2/);
    assert.match(link, /wu=week/);
    assert.match(link, /o=ordered/);
    assert.match(link, /p=last_7_days/);
});

test('a retention definition round-trips; an identical return event becomes "same event"', () => {
    const body = retentionBody(decodeRetention(search('retention', RETENTION, RELATIVE), RETENTION_PROPS), RETENTION_PROPS);

    assert.deepEqual(body.target, { event: 'signup', filters: [filter] });
    assert.deepEqual(body.returning, { event: 'login', filters: [] });
    assert.deepEqual([body.period, body.periods, body.retention_type, body.timezone, body.date_preset], ['week', 6, 'recurring', 'UTC', 'last_7_days']);

    const same = { ...RETENTION, returning: { event: 'signup', filters: [filter] } };
    assert.equal(canonicalToDefinition('retention', same, RELATIVE).same, true);
    const again = retentionBody(decodeRetention(search('retention', same, RELATIVE), RETENTION_PROPS), RETENTION_PROPS);
    assert.deepEqual(again.returning, again.target);
});

test('a definition that cannot be read gives no link, only the saved key', () => {
    assert.equal(canonicalToDefinition('trends', { v: 1, events: 'nope' }, ABSOLUTE), null);
    assert.equal(canonicalToDefinition('trends', null, ABSOLUTE), null);
    assert.equal(canonicalToDefinition('funnel', { steps: [{ event: 1, filters: [] }], window: {}, order: 'x', range: RANGE }, ABSOLUTE), null);
    assert.equal(canonicalToDefinition('paths', TRENDS, ABSOLUTE), null);
    assert.equal(canonicalToQuery('trends', saved('trends', { v: 9 }, ABSOLUTE)), null);
    assert.equal(openUrl('/projects/1/insights/trends', saved('trends', { v: 9 }, ABSOLUTE)), `/projects/1/insights/trends?saved=${ID}`);
    assert.equal(openUrl('/projects/1/insights/trends', { id: ID, type: 'trends' }), `/projects/1/insights/trends?saved=${ID}`);
});

test('the open link is the page path, the definition in the page keys and saved=<uuid>', () => {
    const url = openUrl('/projects/1/insights/funnel', saved('funnel', FUNNEL, RELATIVE));

    assert.match(url, /^\/projects\/1\/insights\/funnel\?s1=/);
    assert.ok(url.endsWith(`&saved=${ID}`));
    const query = new URL(url, 'http://x').search;
    assert.equal(savedIdOf(query), ID);
    assert.equal(withoutSaved(query).includes('saved'), false);
    assert.ok(decodeFunnel(`?${withoutSaved(query)}`, FUNNEL_PROPS) !== null);
});

test('saved keys: only a uuid counts, a new id replaces an old one, null removes it', () => {
    assert.equal(savedIdOf('?saved=nope'), null);
    assert.equal(savedIdOf('?e=a'), null);
    assert.equal(savedIdOf(''), null);
    assert.equal(withSaved('e=a&saved=old', ID), `e=a&saved=${ID}`);
    assert.equal(withSaved('e=a&saved=old', null), 'e=a');
    assert.equal(withSaved('e=a', 'not-a-uuid'), 'e=a');
    assert.equal(withSaved('', ID), `saved=${ID}`);
    assert.equal(withoutSaved(`?saved=${ID}`), '');
});
