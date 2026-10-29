import assert from 'node:assert/strict';
import test from 'node:test';

import {
    decodeDefinition,
    defaultDefinition,
    encodeDefinition,
    activeFilters,
    mapServerErrors,
    minBuckets,
    toRequestBody,
    validateDefinition,
} from '../../resources/js/lib/trendsDefinition.js';
import { inclusiveDays, isBlankFilter, newFilter } from '../../resources/js/lib/insightQuery.js';

const props = {
    options: {
        presets: ['last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'last_month'].map((value) => ({ value, label: value })),
        intervals: ['hour', 'day', 'week', 'month'].map((value) => ({ value, label: value })),
        metrics: ['total_events', 'unique_users'].map((value) => ({ value, label: value })),
        breakdown_columns: ['event', 'distinct_id', 'browser', 'country_code', 'current_url'].map((value) => ({ value, label: value })),
        operators: [
            { value: 'equals', label: 'equals', needs_value: true },
            { value: 'not_equals', label: 'does not equal', needs_value: true },
            { value: 'contains', label: 'contains', needs_value: true },
            { value: 'is_set', label: 'is set', needs_value: false },
        ],
    },
    defaults: { date_preset: 'last_30_days', interval: 'day', metric: 'total_events', top_n: 10 },
    limits: {
        max_events: 10, max_event_length: 200, max_filters: 10, max_filter_key_length: 200, max_filter_value_length: 1000,
        max_breakdown_key_length: 200, max_top_n: 25, max_range_days: 366, max_rows: 10000, max_search_length: 100, event_window_days: 30,
    },
};

const base = () => ({ ...defaultDefinition(props.defaults), events: ['signup'] });

test('the default definition has no events and the server defaults', () => {
    const d = defaultDefinition(props.defaults);

    assert.deepEqual(d.events, []);
    assert.equal(d.metric, 'total_events');
    assert.equal(d.interval, 'day');
    assert.deepEqual(d.range, { mode: 'preset', preset: 'last_30_days', from: '', to: '' });
    assert.equal(d.breakdown.mode, 'none');
    assert.equal(d.breakdown.topN, 10);
});

test('builds the API body: preset or custom dates, optional breakdown, filters with null for is_set', () => {
    const d = base();
    assert.deepEqual(toRequestBody(d, props.options), { events: ['signup'], metric: 'total_events', interval: 'day', date_preset: 'last_30_days', filters: [] });

    d.range = { mode: 'custom', preset: 'last_30_days', from: '2026-03-01', to: '2026-03-07' };
    d.breakdown = { mode: 'column', column: 'browser', property: 'ignored', topN: 5 };
    d.filters = [newFilter({ key: ' plan ', operator: 'equals', value: '' }), newFilter({ key: 'x', operator: 'is_set', value: 'stale' })];

    assert.deepEqual(toRequestBody(d, props.options), {
        events: ['signup'], metric: 'total_events', interval: 'day', date_from: '2026-03-01', date_to: '2026-03-07',
        breakdown: { column: 'browser', top_n: 5 },
        filters: [{ key: ' plan ', operator: 'equals', value: '' }, { key: 'x', operator: 'is_set', value: null }],
    });

    d.breakdown = { mode: 'property', column: 'ignored', property: '$browser', topN: 3 };
    assert.deepEqual(toRequestBody(d, props.options).breakdown, { property: '$browser', top_n: 3 });
});

test('does not mutate the definition and copies the events', () => {
    const d = base();
    const body = toRequestBody(d, props.options);
    body.events.push('x');

    assert.deepEqual(d.events, ['signup']);
});

test('validates limits instantly and never blocks an empty event list', () => {
    assert.equal(validateDefinition(defaultDefinition(props.defaults), props).ok, true);

    const d = base();
    d.events = Array.from({ length: 11 }, (_, i) => `e${i}`);
    assert.match(validateDefinition(d, props).errors.events, /at most 10/);

    const long = base();
    long.events = ['x'.repeat(201)];
    assert.match(validateDefinition(long, props).errors.events, /1 to 200/);

    const filters = base();
    filters.filters = [newFilter({ key: '', value: 'x' }), newFilter({ key: 'k', value: 'v'.repeat(1001) }), newFilter({ key: 'k2', operator: 'is_set', value: 'v'.repeat(1001) })];
    const v = validateDefinition(filters, props);
    assert.equal(v.ok, false);
    assert.equal(Object.keys(v.errors.filters).length, 2, 'is_set takes no value, its text is ignored');
    assert.match(v.errors.filters[filters.filters[0].id].key, /property key/);
    assert.match(v.errors.filters[filters.filters[1].id].value, /1000/);
});

test('validates custom dates, breakdown fields and top N', () => {
    const d = base();
    d.range = { mode: 'custom', preset: 'last_30_days', from: '', to: '2026-03-01' };
    assert.match(validateDefinition(d, props).errors.date, /both/);

    d.range = { mode: 'custom', preset: 'last_30_days', from: '2026-02-30', to: '2026-03-01' };
    assert.match(validateDefinition(d, props).errors.date, /real calendar/);

    d.range = { mode: 'custom', preset: 'last_30_days', from: '2026-03-05', to: '2026-03-01' };
    assert.match(validateDefinition(d, props).errors.date, /not be before/);

    d.range = { mode: 'custom', preset: 'last_30_days', from: '2025-01-01', to: '2026-03-01' };
    assert.match(validateDefinition(d, props).errors.date, /at most 366/);

    d.range = { mode: 'custom', preset: 'last_30_days', from: '2026-01-01', to: '2026-12-31' };
    assert.equal(validateDefinition(d, props).errors.date, undefined);

    const b = base();
    b.breakdown = { mode: 'column', column: '', property: '', topN: 10 };
    assert.match(validateDefinition(b, props).errors.breakdown.column, /Choose/);
    b.breakdown = { mode: 'property', column: '', property: '', topN: 26 };
    const e = validateDefinition(b, props).errors.breakdown;
    assert.match(e.property, /property key/);
    assert.match(e.top_n, /between 1 and 25/);
    b.breakdown = { mode: 'property', column: '', property: 'plan', topN: 25 };
    assert.equal(validateDefinition(b, props).ok, true);
    b.breakdown.topN = Number.NaN;
    assert.equal(validateDefinition(b, props).ok, false);
});

test('the row-cap check is a lower bound: it never blocks what the server accepts', () => {
    // hour over 90 days is 2158+ buckets x 5 events = 10790 > 10000: certainly too many
    const big = base();
    big.range.preset = 'last_90_days';
    big.interval = 'hour';
    big.events = ['a', 'b', 'c', 'd', 'e'];
    assert.match(validateDefinition(big, props).errors.general, /rows/);

    // hour over 30 days x 10 events = 7180 rows: fine
    const ok = base();
    ok.interval = 'hour';
    ok.events = Array.from({ length: 10 }, (_, i) => `e${i}`);
    assert.equal(validateDefinition(ok, props).ok, true);

    // breakdown multiplies by top N + 1: 30 days x 10 events x 26 = 7800 fine, x 2 events more is too many
    const bd = base();
    bd.events = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
    bd.breakdown = { mode: 'column', column: 'browser', property: '', topN: 25 };
    bd.range.preset = 'last_90_days';
    assert.match(validateDefinition(bd, props).errors.general, /rows/);

    assert.equal(minBuckets(30, 'day'), 30);
    assert.equal(minBuckets(30, 'week'), 4);
    assert.equal(minBuckets(30, 'month'), 0 + 1);
    assert.equal(minBuckets(1, 'hour'), 22);
    assert.equal(inclusiveDays('2026-03-01', '2026-03-01'), 1);
    assert.equal(inclusiveDays('2026-03-01', '2026-03-31'), 31);
    assert.equal(inclusiveDays('2026-02-30', '2026-03-31'), null);
});

test('maps the API 422 errors onto controls and the rest to the general message', () => {
    const d = base();
    d.filters = [newFilter({ key: 'a' }), newFilter({ key: 'b' })];
    d.breakdown = { mode: 'property', column: '', property: 'plan', topN: 5 };

    const errors = mapServerErrors({
        'events.0': ['Each event name must be 1 to 200 characters of text, and names must be distinct.'],
        date_from: ['Dates must look like 2026-03-01.'],
        'filters.1.key': ['A filter key must be 1 to 200 characters of text.'],
        'filters.1.value': ['This filter operator needs a value.'],
        'filters.9.key': ['out of range'],
        breakdown: ['A breakdown needs exactly one of column and property.'],
        'breakdown.top_n': ['The breakdown top_n must be a whole number from 1 to 25.'],
        definition: ['This combination is not allowed.'],
        body: ['The request contains fields that are not supported.'],
    }, d);

    assert.match(errors.events, /Each event name/);
    assert.match(errors.date, /Dates must/);
    assert.match(errors.filters[d.filters[1].id].key, /A filter key/);
    assert.match(errors.filters[d.filters[1].id].value, /needs a value/);
    assert.match(errors.breakdown.property, /exactly one/);
    assert.match(errors.breakdown.top_n, /whole number/);
    assert.match(errors.general, /This combination is not allowed\./);
    assert.match(errors.general, /not supported/);
    assert.match(errors.general, /out of range/);
});

test('round-trips a definition through the query string; defaults are omitted', () => {
    const d = base();
    assert.equal(encodeDefinition(d, props.defaults), 'e=signup');

    d.events = ['signup', 'a&b=c', 'üñï 日本'];
    d.metric = 'unique_users';
    d.interval = 'week';
    d.range.preset = 'last_7_days';
    d.breakdown = { mode: 'column', column: 'browser', property: '', topN: 5 };
    d.filters = [newFilter({ key: 'plan', operator: 'equals', value: 'a "q" & b' }), newFilter({ key: 'seen', operator: 'is_set', value: '' })];

    const back = decodeDefinition(encodeDefinition(d, props.defaults), props);

    assert.deepEqual(back.events, d.events);
    assert.equal(back.metric, 'unique_users');
    assert.equal(back.interval, 'week');
    assert.equal(back.range.preset, 'last_7_days');
    assert.deepEqual(back.breakdown, { mode: 'column', column: 'browser', property: '', topN: 5 });
    assert.deepEqual(back.filters.map(({ key, operator, value }) => ({ key, operator, value })), [
        { key: 'plan', operator: 'equals', value: 'a "q" & b' },
        { key: 'seen', operator: 'is_set', value: '' },
    ]);

    d.range = { mode: 'custom', preset: 'last_30_days', from: '2026-03-01', to: '2026-03-07' };
    d.breakdown = { mode: 'property', column: '', property: '$browser', topN: 10 };
    const custom = decodeDefinition(encodeDefinition(d, props.defaults), props);
    assert.deepEqual(custom.range, { mode: 'custom', preset: 'last_30_days', from: '2026-03-01', to: '2026-03-07' });
    assert.deepEqual(custom.breakdown, { mode: 'property', column: '', property: '$browser', topN: 10 });
});

test('never trusts the URL: every bad field is dropped and the rest is kept', () => {
    const hostile = [
        'e=signup&e=signup&e=' + 'x'.repeat(201) + '&e=&m=drop_table&i=year&p=forever&b=password&bn=99&f=not-json&f=' + encodeURIComponent('["k","evil","v"]') + '&f=' + encodeURIComponent('["","equals","v"]') + '&f=' + encodeURIComponent('[1,"equals","v"]') + '&f=' + encodeURIComponent('["k","equals",5]'),
    ][0];
    const d = decodeDefinition(hostile, props);

    assert.deepEqual(d.events, ['signup']);
    assert.equal(d.metric, 'total_events');
    assert.equal(d.interval, 'day');
    assert.equal(d.range.preset, 'last_30_days');
    assert.equal(d.breakdown.mode, 'none');
    assert.equal(d.breakdown.topN, 10);
    assert.deepEqual(d.filters, []);

    const many = decodeDefinition(Array.from({ length: 30 }, (_, i) => `e=e${i}`).join('&') + '&' + Array.from({ length: 30 }, (_, i) => 'f=' + encodeURIComponent(JSON.stringify([`k${i}`, 'equals', 'v']))).join('&'), props);
    assert.equal(many.events.length, 10);
    assert.equal(many.filters.length, 10);

    assert.equal(decodeDefinition('', props), null);
    assert.equal(decodeDefinition('?', props), null);
    assert.deepEqual(decodeDefinition('?e=a', props).events, ['a']);
    assert.equal(decodeDefinition('from=2026-02-30&to=2026-03-01&e=a', props).range.mode, 'preset');
    assert.equal(decodeDefinition('from=2026-03-01&e=a', props).range.mode, 'preset');
    assert.equal(decodeDefinition('bp=' + 'k'.repeat(201) + '&e=a', props).breakdown.mode, 'none');
    assert.equal(decodeDefinition('bn=2.5&b=browser&e=a', props).breakdown.topN, 10);
});

test('a blank filter row is a draft: not in the body, not validated, not in the URL, and server errors count only real rows', () => {
    const d = base();
    const blank = newFilter();
    const typed = newFilter({ key: 'plan', value: 'pro' });
    d.filters = [blank, typed, newFilter({ key: '', value: 'orphan value' })];

    assert.equal(isBlankFilter(blank), true);
    assert.equal(isBlankFilter(typed), false);
    assert.deepEqual(activeFilters(d).map((f) => f.key), ['plan', '']);
    assert.deepEqual(toRequestBody(d, props.options).filters.map((f) => f.key), ['plan', '']);

    const v = validateDefinition(d, props);
    assert.equal(blank.id in v.errors.filters, false, 'the blank row has no error');
    assert.equal(d.filters[2].id in v.errors.filters, true, 'a row with only a value needs its key');

    assert.equal(encodeDefinition(d, props.defaults).split('&f=').length - 1 + (encodeDefinition(d, props.defaults).startsWith('f=') ? 1 : 0), 2);

    // the 422 of the body maps index 1 (the second REAL row) to the row that sent it
    const errors = mapServerErrors({ 'filters.1.key': ['A filter key must be 1 to 200 characters of text.'] }, d);
    assert.equal(d.filters[2].id in errors.filters, true);
    assert.equal(blank.id in errors.filters, false);
});

test('lengths count code points like the server (mb_strlen): an emoji is one character', () => {
    const d = base();
    d.events = ['😀'.repeat(200)];
    assert.equal(validateDefinition(d, props).errors.events, undefined);
    d.events = ['😀'.repeat(201)];
    assert.match(validateDefinition(d, props).errors.events, /1 to 200/);

    d.events = ['a'];
    d.filters = [newFilter({ key: '😀'.repeat(200), value: '😀'.repeat(1000) })];
    assert.equal(validateDefinition(d, props).ok, true);
    d.filters = [newFilter({ key: '😀'.repeat(201), value: 'x' })];
    assert.equal(validateDefinition(d, props).ok, false);
    assert.equal(decodeDefinition('e=' + encodeURIComponent('😀'.repeat(200)), props).events.length, 1);
    assert.equal(decodeDefinition('e=' + encodeURIComponent('😀'.repeat(201)), props).events.length, 0);
});

test('does not put a NaN top N into the URL', () => {
    const d = base();
    d.breakdown = { mode: 'column', column: 'browser', property: '', topN: Number.NaN };

    assert.equal(encodeDefinition(d, props.defaults), 'e=signup&b=browser');
});
