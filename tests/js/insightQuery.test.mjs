import assert from 'node:assert/strict';
import test from 'node:test';

import {
    activeFilterRows,
    decodeFilter,
    decodeRange,
    defaultRange,
    encodeFilter,
    encodeRange,
    filterBody,
    inclusiveDays,
    newFilter,
    parseDay,
    placeFilterError,
    rangeBody,
    validateFilterRows,
    validateRange,
} from '../../resources/js/lib/insightQuery.js';

const operators = [
    { value: 'equals', label: 'equals', needs_value: true },
    { value: 'is_set', label: 'is set', needs_value: false },
];
const presets = ['last_7_days', 'last_30_days'].map((value) => ({ value, label: value }));
const defaults = { date_preset: 'last_30_days' };
const limits = { max_range_days: 366, max_filter_key_length: 10, max_filter_value_length: 5 };

test('a blank filter row is a draft: it is neither in the body nor validated', () => {
    const rows = [newFilter(), newFilter({ key: 'plan', value: 'pro' })];

    assert.equal(activeFilterRows(rows).length, 1);
    assert.deepEqual(filterBody(rows, operators), [{ key: 'plan', operator: 'equals', value: 'pro' }]);
    assert.deepEqual(validateFilterRows([newFilter()], operators, limits), {});
});

test('"is set" goes out with a null value however the row reads', () => {
    assert.deepEqual(filterBody([newFilter({ key: 'plan', operator: 'is_set', value: 'junk' })], operators), [{ key: 'plan', operator: 'is_set', value: null }]);
});

test('validates filter keys and values by code points, and skips the value of an operator without one', () => {
    const long = newFilter({ key: 'k'.repeat(11), value: 'v'.repeat(6) });
    const emoji = newFilter({ key: 'plan', value: '😀'.repeat(5) });
    const missing = newFilter({ key: '', value: 'x' });
    const isSet = newFilter({ key: 'plan', operator: 'is_set', value: 'v'.repeat(50) });
    const errors = validateFilterRows([long, emoji, missing, isSet], operators, limits);

    assert.deepEqual(Object.keys(errors[long.id]), ['key', 'value']);
    assert.equal(errors[emoji.id], undefined, 'five emoji are five characters');
    assert.equal(errors[missing.id].key, 'Enter a property key.');
    assert.equal(errors[isSet.id], undefined);
});

test('places a server filter error on the row, an operator error at the key, and keeps the first message', () => {
    const row = newFilter({ key: 'a' });
    const errors = {};
    placeFilterError(errors, row, 'operator', 'bad operator');
    placeFilterError(errors, row, 'key', 'second');
    placeFilterError(errors, row, undefined, 'third');
    placeFilterError(errors, row, 'value', 'bad value');

    assert.deepEqual(errors[row.id], { key: 'bad operator', value: 'bad value' });
});

test('round-trips a filter through its query string value and never trusts one that is malformed', () => {
    const filter = newFilter({ key: 'plan', operator: 'equals', value: 'pro' });
    const back = decodeFilter(encodeFilter(filter), operators, limits);

    assert.deepEqual({ ...back, id: 0 }, { ...filter, id: 0 });

    for (const bad of ['', 'nope', '{}', '[]', '["a","equals"]', '["","equals","x"]', '["a","likes","x"]', '["a","equals",1]', `["${'k'.repeat(11)}","equals","x"]`, '["a","equals","vvvvvv"]', '["a","equals","x","y"]']) {
        assert.equal(decodeFilter(bad, operators, limits), null, bad);
    }
});

test('the range body is a preset or both ends of a custom range', () => {
    assert.deepEqual(rangeBody(defaultRange(defaults)), { date_preset: 'last_30_days' });
    assert.deepEqual(rangeBody({ mode: 'custom', preset: 'last_30_days', from: '2026-01-01', to: '2026-01-31' }), { date_from: '2026-01-01', date_to: '2026-01-31' });
});

test('validates a custom range and treats a preset as a lower bound of days', () => {
    const custom = (from, to) => validateRange({ mode: 'custom', preset: 'last_30_days', from, to }, limits);

    assert.deepEqual(validateRange(defaultRange(defaults), limits), { error: undefined, days: 30 });
    assert.deepEqual(validateRange({ mode: 'preset', preset: 'this_month', from: '', to: '' }, limits), { error: undefined, days: 1 });
    assert.equal(custom('', '2026-01-01').error, 'Pick both a start and an end date.');
    assert.equal(custom('2026-02-30', '2026-03-01').error, 'Use real calendar dates.');
    assert.equal(custom('2026-03-02', '2026-03-01').error, 'The end date must not be before the start date.');
    assert.equal(custom('2025-01-01', '2026-03-01').error, 'The range can be at most 366 days.');
    assert.deepEqual(custom('2026-03-01', '2026-03-07'), { error: undefined, days: 7 });
});

test('counts inclusive calendar days and refuses dates that do not exist', () => {
    assert.equal(inclusiveDays('2026-03-01', '2026-03-01'), 1);
    assert.equal(inclusiveDays('2026-02-27', '2026-03-02'), 4);
    assert.equal(inclusiveDays('2026-02-29', '2026-03-02'), null);
    assert.equal(parseDay('2026-1-1'), null);
    assert.equal(parseDay(20260101), null);
});

test('encodes a range without the default preset and decodes it back; a bad range falls back to the default', () => {
    const encode = (range) => {
        const params = new URLSearchParams();
        encodeRange(range, defaults, params);

        return params;
    };

    assert.equal(encode(defaultRange(defaults)).toString(), '');
    assert.equal(encode({ mode: 'preset', preset: 'last_7_days', from: '', to: '' }).toString(), 'p=last_7_days');
    assert.deepEqual(decodeRange(encode({ mode: 'preset', preset: 'last_7_days', from: '', to: '' }), presets, defaults), { mode: 'preset', preset: 'last_7_days', from: '', to: '' });
    assert.deepEqual(decodeRange(encode({ mode: 'custom', preset: 'x', from: '2026-01-01', to: '2026-01-31' }), presets, defaults), { mode: 'custom', preset: 'last_30_days', from: '2026-01-01', to: '2026-01-31' });
    assert.deepEqual(decodeRange(new URLSearchParams('p=forever'), presets, defaults), defaultRange(defaults));
    assert.deepEqual(decodeRange(new URLSearchParams('from=2026-01-01&to=nope&p=last_7_days'), presets, defaults).preset, 'last_7_days');
});
