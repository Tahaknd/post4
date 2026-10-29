import assert from 'node:assert/strict';
import test from 'node:test';

import { newFilter } from '../../resources/js/lib/insightQuery.js';
import {
    conditionEvent,
    copyCondition,
    decodeDefinition,
    defaultDefinition,
    encodeDefinition,
    mapServerErrors,
    newCondition,
    periodsNumber,
    returningOf,
    toRequestBody,
    validateDefinition,
} from '../../resources/js/lib/retentionDefinition.js';

const props = {
    options: {
        presets: ['last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'last_month'].map((value) => ({ value, label: value })),
        operators: [
            { value: 'equals', label: 'equals', needs_value: true },
            { value: 'not_equals', label: 'does not equal', needs_value: true },
            { value: 'contains', label: 'contains', needs_value: true },
            { value: 'is_set', label: 'is set', needs_value: false },
        ],
        periods: [{ value: 'day', label: 'Day' }, { value: 'week', label: 'Week' }, { value: 'month', label: 'Month' }],
        retention_types: [{ value: 'first_time', label: 'First time' }, { value: 'recurring', label: 'Recurring' }],
        timezones: ['Asia/Kolkata', 'Europe/Istanbul', 'UTC'],
    },
    defaults: { date_preset: 'last_30_days', period: 'day', periods: 8, retention_type: 'first_time', timezone: 'Europe/Istanbul' },
    limits: {
        min_periods: 1, max_periods: 30, max_event_length: 200, max_filters_per_event: 5, max_filter_key_length: 200, max_filter_value_length: 1000,
        max_range_days: 366, max_search_length: 100, event_window_days: 30,
    },
};

const filled = (target, returning, extra = {}) => ({ ...defaultDefinition(props), target: { event: target, filters: [] }, returning: { event: returning, filters: [] }, ...extra });
const query = (qs) => new URLSearchParams(qs);

// ---------------------------------------------------------------- the definition

test('starts with nothing chosen and the server defaults, in the zone of the project', () => {
    const definition = defaultDefinition(props);

    assert.deepEqual([definition.target.event, definition.target.filters, definition.returning.event, definition.returning.filters], ['', [], '', []]);
    assert.equal(definition.same, false);
    assert.deepEqual(
        [definition.period, definition.periods, definition.type, definition.timezone, definition.range],
        ['day', '8', 'first_time', 'Europe/Istanbul', { mode: 'preset', preset: 'last_30_days', from: '', to: '' }],
    );
});

test('the return event is the cohort event while `same` is on, and its own role otherwise', () => {
    const definition = filled('signup', 'visit');

    assert.equal(returningOf(definition), definition.returning);
    assert.equal(returningOf({ ...definition, same: true }), definition.target);
});

test('copies a role with new filter ids so that two roles never share a row', () => {
    const original = { event: 'signup', filters: [newFilter({ key: 'plan', value: 'pro' })] };
    const copy = copyCondition(original);

    assert.deepEqual(copy.filters.map(({ key, operator, value }) => [key, operator, value]), [['plan', 'equals', 'pro']]);
    assert.notEqual(copy.filters[0].id, original.filters[0].id);
    assert.equal(copyCondition(newCondition()).event, '');
});

test('reads the number of periods only as a whole number', () => {
    assert.equal(periodsNumber('8'), 8);
    assert.equal(periodsNumber(' 12 '), 12);
    assert.equal(periodsNumber('007'), 7);
    for (const bad of ['', ' ', '1.5', '-1', '+3', '1e1', 'abc', '1 2', '1000']) {
        assert.equal(periodsNumber(bad), null, JSON.stringify(bad));
    }
});

test('the event of a role is its name without the spaces around it', () => {
    assert.equal(conditionEvent({ event: '  signup ', filters: [] }), 'signup');
});

// ---------------------------------------------------------------- the API body

test('builds the body: trimmed events, filters without the blank rows, a number of periods, the preset', () => {
    const definition = filled('  signup ', 'visit', {
        period: 'week',
        periods: '5',
        type: 'recurring',
        timezone: 'Asia/Kolkata',
    });
    definition.target.filters = [newFilter(), newFilter({ key: 'plan', operator: 'contains', value: 'p' }), newFilter({ key: 'seen', operator: 'is_set', value: 'junk' })];

    assert.deepEqual(toRequestBody(definition, props), {
        target: { event: 'signup', filters: [{ key: 'plan', operator: 'contains', value: 'p' }, { key: 'seen', operator: 'is_set', value: null }] },
        returning: { event: 'visit', filters: [] },
        period: 'week',
        periods: 5,
        retention_type: 'recurring',
        timezone: 'Asia/Kolkata',
        date_preset: 'last_30_days',
    });
});

test('a custom range goes out as two days and `same` sends the cohort event, filters included, as the return event', () => {
    const definition = filled('signup', 'ignored', { same: true, range: { mode: 'custom', preset: 'last_30_days', from: '2026-03-01', to: '2026-03-31' } });
    definition.target.filters = [newFilter({ key: 'plan', value: 'pro' })];

    const body = toRequestBody(definition, props);

    assert.deepEqual(body.returning, body.target);
    assert.deepEqual(body.returning, { event: 'signup', filters: [{ key: 'plan', operator: 'equals', value: 'pro' }] });
    assert.deepEqual([body.date_from, body.date_to, body.date_preset], ['2026-03-01', '2026-03-31', undefined]);
});

test('a number of periods that is not a number goes out as typed (validation blocks it before it is sent)', () => {
    assert.equal(toRequestBody(filled('a', 'b', { periods: '1.5' }), props).periods, '1.5');
});

// ---------------------------------------------------------------- validation

test('accepts a complete definition and an incomplete one is not ok', () => {
    assert.deepEqual(validateDefinition(filled('signup', 'visit'), props), { ok: true, errors: {} });

    const { ok, errors } = validateDefinition(defaultDefinition(props), props);
    assert.equal(ok, false);
    assert.equal(errors.target.event, 'Choose the cohort event.');
    assert.equal(errors.returning.event, 'Choose the return event.');
});

test('does not ask for a return event while it is the cohort event', () => {
    const { ok, errors } = validateDefinition({ ...filled('signup', ''), same: true }, props);

    assert.equal(ok, true);
    assert.equal(errors.returning, undefined);
    assert.equal(validateDefinition({ ...filled('', ''), same: true }, props).errors.returning, undefined);
});

test('checks the length of an event name in characters, not bytes', () => {
    assert.equal(validateDefinition(filled('é'.repeat(200), 'b'), props).ok, true);
    assert.equal(validateDefinition(filled('😀'.repeat(200), 'b'), props).ok, true);
    assert.equal(validateDefinition(filled('x'.repeat(201), 'b'), props).errors.target.event, 'Event names are 1 to 200 characters.');
    assert.equal(validateDefinition(filled('   ', 'b'), props).errors.target.event, 'Choose the cohort event.');
});

test('checks the filters of each role: a key is needed, the limits apply, blank rows are drafts', () => {
    const definition = filled('a', 'b');
    const blank = newFilter();
    const noKey = newFilter({ value: 'x' });
    const longKey = newFilter({ key: 'k'.repeat(201) });
    definition.target.filters = [blank, noKey];
    definition.returning.filters = [longKey];

    const { errors } = validateDefinition(definition, props);

    assert.deepEqual(errors.target.filters, { [noKey.id]: { key: 'Enter a property key.' } });
    assert.deepEqual(errors.returning.filters, { [longKey.id]: { key: 'The key can be at most 200 characters.' } });
    assert.equal(errors.target.filters[blank.id], undefined);
});

test('allows at most five filters per event and counts only rows that are not blank', () => {
    const rows = (n) => Array.from({ length: n }, (_, i) => newFilter({ key: `k${i}` }));
    const five = filled('a', 'b');
    five.target.filters = [...rows(5), newFilter()];
    const six = filled('a', 'b');
    six.returning.filters = rows(6);

    assert.equal(validateDefinition(five, props).ok, true);
    assert.equal(validateDefinition(six, props).errors.returning.general, 'Use at most 5 filters per event.');
    assert.equal(validateDefinition(six, props).errors.target, undefined);
});

test('checks the number of periods from 1 to 30', () => {
    const message = 'Enter the number of periods as a whole number from 1 to 30.';

    for (const ok of ['1', '8', '30', ' 30 ']) {
        assert.equal(validateDefinition(filled('a', 'b', { periods: ok }), props).ok, true, ok);
    }
    for (const bad of ['', '0', '31', '1.5', 'x', '-2']) {
        assert.equal(validateDefinition(filled('a', 'b', { periods: bad }), props).errors.periods, message, JSON.stringify(bad));
    }
});

test('checks the period, the type and the zone against the lists of the page, and the date range', () => {
    const { errors } = validateDefinition(filled('a', 'b', { period: 'hour', type: 'sometimes', timezone: 'Mars/Base', range: { mode: 'custom', preset: 'last_30_days', from: '2026-03-08', to: '2026-03-01' } }), props);

    assert.equal(errors.period, 'Choose day, week or month.');
    assert.equal(errors.type, 'Choose a retention type.');
    assert.equal(errors.timezone, 'Choose a time zone from the list.');
    assert.equal(errors.date, 'The end date must not be before the start date.');
    assert.equal(validateDefinition(filled('a', 'b', { range: { mode: 'custom', preset: 'last_30_days', from: '2025-01-01', to: '2026-03-01' } }), props).errors.date, 'The range can be at most 366 days.');
});

// ---------------------------------------------------------------- server errors

test('puts the messages of a 422 on the control they belong to', () => {
    const definition = filled('a', 'b');
    const first = newFilter({ key: 'one' });
    const second = newFilter({ key: 'two' });
    definition.target.filters = [newFilter(), first];
    definition.returning.filters = [second];

    const errors = mapServerErrors({
        'target.event': ['The cohort event needs a name.'],
        'returning.filters.0.value': ['A filter value must be text.'],
        'target.filters.0.operator': ['A filter operator must be equals.'],
        'target.filters': ['Provide a list of at most 5 filters per event.'],
        period: ['The period must be day, week, month.'],
        periods: ['The number of periods must be a whole number from 1 to 30.'],
        retention_type: ['The retention type must be first_time or recurring.'],
        timezone: ['Unknown time zone.'],
        date_from: ['The range is too long.'],
        definition: ['This combination is not allowed.'],
        body: ['The request contains fields that are not supported.'],
    }, definition);

    assert.equal(errors.target.event, 'The cohort event needs a name.');
    assert.deepEqual(errors.returning.filters, { [second.id]: { value: 'A filter value must be text.' } });
    // blank rows do not go out, so row 0 of the body is the first row that is not blank
    assert.deepEqual(errors.target.filters, { [first.id]: { key: 'A filter operator must be equals.' } });
    assert.equal(errors.target.general, 'Provide a list of at most 5 filters per event.');
    assert.equal(errors.period, 'The period must be day, week, month.');
    assert.equal(errors.periods, 'The number of periods must be a whole number from 1 to 30.');
    assert.equal(errors.type, 'The retention type must be first_time or recurring.');
    assert.equal(errors.timezone, 'Unknown time zone.');
    assert.equal(errors.date, 'The range is too long.');
    assert.equal(errors.general, 'This combination is not allowed. The request contains fields that are not supported.');
});

test('a message about a role that is the same event as the other goes to the cohort event, and a row that is gone to the general message', () => {
    const definition = { ...filled('a', 'b'), same: true };
    const row = newFilter({ key: 'plan' });
    definition.target.filters = [row];

    const errors = mapServerErrors({
        'returning.event': ['The return event needs a name.'],
        'returning.filters.0.key': ['A filter key must be text.'],
        'target.filters.4.key': ['A filter key must be text of its own.'],
        'target.mystery': ['Something else.'],
    }, definition);

    assert.equal(errors.target.event, 'The return event needs a name.');
    assert.deepEqual(errors.target.filters, { [row.id]: { key: 'A filter key must be text.' } });
    assert.equal(errors.returning, undefined);
    assert.equal(errors.general, 'A filter key must be text of its own. Something else.');
});

test('ignores empty messages and tolerates no errors at all', () => {
    assert.deepEqual(mapServerErrors(null, filled('a', 'b')), {});
    assert.deepEqual(mapServerErrors({ periods: [], period: [''], timezone: [null] }, filled('a', 'b')), {});
    assert.deepEqual(mapServerErrors({ periods: 'plain text' }, filled('a', 'b')), { periods: 'plain text' });
});

// ---------------------------------------------------------------- query string

test('writes an untouched definition as an empty query string and omits every default', () => {
    assert.equal(encodeDefinition(defaultDefinition(props), props), '');
    assert.equal(encodeDefinition(filled('', ''), props), '');
});

test('writes the roles, the options and the range, each under a key of its own', () => {
    const definition = filled('signup', 'visit', { period: 'week', periods: '5', type: 'recurring', timezone: 'UTC', range: { mode: 'custom', preset: 'last_30_days', from: '2026-03-01', to: '2026-03-31' } });
    definition.target.filters = [newFilter({ key: 'plan', operator: 'equals', value: 'pro' }), newFilter()];

    const params = query(encodeDefinition(definition, props));

    assert.equal(params.get('t'), JSON.stringify(['signup', [['plan', 'equals', 'pro']]]));
    assert.equal(params.get('r'), JSON.stringify(['visit', []]));
    assert.deepEqual([params.get('pd'), params.get('n'), params.get('rt'), params.get('tz'), params.get('from'), params.get('to'), params.get('p')], ['week', '5', 'recurring', 'UTC', '2026-03-01', '2026-03-31', null]);
    const keys = [...params.keys()];
    assert.equal(new Set(keys).size, keys.length, 'no key is repeated');
    assert.equal(params.get('same'), null);
});

test('`same` replaces the return event in the link, and a preset other than the default is written', () => {
    const params = query(encodeDefinition(filled('signup', 'visit', { same: true, range: { mode: 'preset', preset: 'last_7_days', from: '', to: '' } }), props));

    assert.equal(params.get('same'), '1');
    assert.equal(params.get('r'), null);
    assert.equal(params.get('p'), 'last_7_days');
});

test('a link gives back the definition it was made from', () => {
    const definition = filled('signup', 'visit', { period: 'month', periods: '3', type: 'recurring', timezone: 'Asia/Kolkata', range: { mode: 'preset', preset: 'last_90_days', from: '', to: '' } });
    definition.target.filters = [newFilter({ key: 'plan', operator: 'not_equals', value: 'free' })];
    definition.returning.filters = [newFilter({ key: 'page', operator: 'is_set', value: '' })];

    const back = decodeDefinition(`?${encodeDefinition(definition, props)}`, props);
    const plain = (condition) => ({ event: condition.event, filters: condition.filters.map(({ key, operator, value }) => [key, operator, value]) });

    assert.deepEqual(plain(back.target), plain(definition.target));
    assert.deepEqual(plain(back.returning), plain(definition.returning));
    assert.deepEqual([back.period, back.periods, back.type, back.timezone, back.same, back.range], ['month', '3', 'recurring', 'Asia/Kolkata', false, definition.range]);
    assert.equal(encodeDefinition(back, props), encodeDefinition(definition, props));
});

test('reads `same` from a link and ignores a return event that comes with it', () => {
    const back = decodeDefinition(`?t=${encodeURIComponent(JSON.stringify(['signup', []]))}&same=1&r=${encodeURIComponent(JSON.stringify(['visit', []]))}`, props);

    assert.equal(back.same, true);
    assert.equal(back.returning.event, '');
    assert.equal(returningOf(back).event, 'signup');
    assert.equal(decodeDefinition('?same=yes', props).same, false);
});

test('a link that says nothing gives nothing, and one that says nothing usable gives the defaults', () => {
    assert.equal(decodeDefinition('', props), null);
    assert.equal(decodeDefinition('?', props), null);
    assert.equal(decodeDefinition(undefined, props), null);

    const back = decodeDefinition('?t=garbage&r=%5B1%5D&pd=hour&n=abc&rt=sometimes&tz=Mars%2FBase&p=forever&from=2026-02-30&to=x&junk=1', props);
    const blank = defaultDefinition(props);

    assert.deepEqual([back.target.event, back.returning.event, back.same], ['', '', false]);
    assert.deepEqual([back.period, back.periods, back.type, back.timezone, back.range], [blank.period, blank.periods, blank.type, blank.timezone, blank.range]);
});

test('drops the fields of a link that are wrong one by one and keeps the rest', () => {
    const t = encodeURIComponent(JSON.stringify(['signup', []]));
    const back = decodeDefinition(`?t=${t}&r=%5B1%5D&pd=week&n=31&rt=recurring&tz=Nowhere&p=last_7_days`, props);

    assert.equal(back.target.event, 'signup');
    assert.equal(back.returning.event, '');
    assert.equal(back.period, 'week');
    assert.equal(back.periods, '8');
    assert.equal(back.type, 'recurring');
    assert.equal(back.timezone, 'Europe/Istanbul');
    assert.equal(back.range.preset, 'last_7_days');
});

test('checks the numbers of periods in a link and normalises them', () => {
    assert.equal(decodeDefinition('?n=007', props).periods, '7');
    assert.equal(decodeDefinition('?n=1', props).periods, '1');
    assert.equal(decodeDefinition('?n=30', props).periods, '30');
    for (const bad of ['0', '31', '1.5', '-1', '+2', ' 3', '1e1', '']) {
        assert.equal(decodeDefinition(`?n=${encodeURIComponent(bad)}&pd=day`, props).periods, '8', JSON.stringify(bad));
    }
});

test('cuts the filters of a link at the limits and drops the ones that are wrong', () => {
    const tuples = Array.from({ length: 9 }, (_, i) => ['k' + i, 'equals', 'v']);
    const read = (list) => decodeDefinition(`?t=${encodeURIComponent(JSON.stringify(['signup', list]))}`, props).target.filters.map((filter) => filter.key);

    assert.deepEqual(read(tuples), ['k0', 'k1', 'k2', 'k3', 'k4'], 'only the first five tuples are read');
    // the wrong ones are dropped, and they count against the five that are read
    assert.deepEqual(read([['', 'equals', 'v'], ['k', 'bogus', 'v'], ['k', 'equals', 7], 'nope', ['ok', 'equals', 'v'], ['late', 'equals', 'v']]), ['ok']);
    assert.equal(decodeDefinition(`?t=${encodeURIComponent(JSON.stringify(['x'.repeat(201), []]))}`, props).target.event, '');
    assert.equal(decodeDefinition(`?t=${encodeURIComponent(JSON.stringify(['a', 'not a list']))}`, props).target.event, '');
    assert.equal(decodeDefinition(`?t=${encodeURIComponent(JSON.stringify(['a', [], 'extra']))}`, props).target.event, '');
});

test('a hostile event name in a link stays plain data', () => {
    const name = '<img src=x onerror=alert(1)>';
    const back = decodeDefinition(`?t=${encodeURIComponent(JSON.stringify([name, []]))}`, props);

    assert.equal(back.target.event, name);
    assert.deepEqual(toRequestBody({ ...back, returning: { event: 'b', filters: [] } }, props).target.event, name);
});
