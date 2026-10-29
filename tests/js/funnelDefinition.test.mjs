import assert from 'node:assert/strict';
import test from 'node:test';

import {
    addStep,
    canAddStep,
    canMoveStep,
    canRemoveStep,
    decodeDefinition,
    defaultDefinition,
    encodeDefinition,
    formatDuration,
    mapServerErrors,
    moveStep,
    newStep,
    patchStep,
    removeStep,
    stepEvent,
    toRequestBody,
    validateDefinition,
    windowSeconds,
} from '../../resources/js/lib/funnelDefinition.js';
import { newFilter } from '../../resources/js/lib/insightQuery.js';

const props = {
    options: {
        presets: ['last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'last_month'].map((value) => ({ value, label: value })),
        operators: [
            { value: 'equals', label: 'equals', needs_value: true },
            { value: 'not_equals', label: 'does not equal', needs_value: true },
            { value: 'contains', label: 'contains', needs_value: true },
            { value: 'is_set', label: 'is set', needs_value: false },
        ],
        window_units: [
            { value: 'minute', label: 'Minutes', seconds: 60 },
            { value: 'hour', label: 'Hours', seconds: 3600 },
            { value: 'day', label: 'Days', seconds: 86400 },
            { value: 'week', label: 'Weeks', seconds: 604800 },
        ],
        orders: [{ value: 'ordered', label: 'Ordered' }, { value: 'strict_order', label: 'Strict order' }],
    },
    defaults: { date_preset: 'last_30_days', window: { value: 2, unit: 'week' }, order: 'ordered' },
    limits: {
        min_steps: 2, max_steps: 8, max_event_length: 200, max_filters_per_step: 5, max_filters: 20, max_filter_key_length: 200,
        max_filter_value_length: 1000, max_range_days: 366, min_window_seconds: 60, max_window_seconds: 7776000, max_window_value: 100000,
        max_search_length: 100, event_window_days: 30,
    },
};

const filled = (...events) => ({ ...defaultDefinition(props), steps: events.map((event) => newStep({ event })) });
const ids = (steps) => steps.map((step) => step.id);

// ---------------------------------------------------------------- step list operations

test('starts with the minimum number of blank steps and the server defaults', () => {
    const definition = defaultDefinition(props);

    assert.equal(definition.steps.length, 2);
    assert.deepEqual(definition.steps.map((step) => [step.event, step.filters]), [['', []], ['', []]]);
    assert.equal(new Set(ids(definition.steps)).size, 2, 'every step has its own id');
    assert.deepEqual(definition.window, { value: '2', unit: 'week' });
    assert.equal(definition.order, 'ordered');
    assert.deepEqual(definition.range, { mode: 'preset', preset: 'last_30_days', from: '', to: '' });
});

test('adds a blank step at the end up to the maximum, and then changes nothing', () => {
    let steps = filled('a', 'b').steps;
    for (let i = 0; i < 6; i++) {
        assert.equal(canAddStep(steps, props.limits), true);
        steps = addStep(steps, props.limits);
    }

    assert.equal(steps.length, 8);
    assert.equal(steps[7].event, '');
    assert.equal(canAddStep(steps, props.limits), false);
    assert.equal(addStep(steps, props.limits), steps, 'the same list: nothing to render');
});

test('removes a step down to the minimum, and never an unknown one', () => {
    const steps = filled('a', 'b', 'c').steps;

    assert.equal(canRemoveStep(steps, props.limits), true);
    const fewer = removeStep(steps, steps[1].id, props.limits);
    assert.deepEqual(fewer.map(stepEvent), ['a', 'c']);
    assert.equal(canRemoveStep(fewer, props.limits), false);
    assert.equal(removeStep(fewer, fewer[0].id, props.limits), fewer);
    assert.equal(removeStep(steps, 999999, props.limits), steps);
});

test('moves a step up or down by one place, keeping its id, its filters and the other steps', () => {
    const steps = filled('a', 'b', 'c').steps;
    steps[1].filters = [newFilter({ key: 'plan', value: 'pro' })];
    const [a, b, c] = steps;

    assert.deepEqual(ids(moveStep(steps, b.id, -1)), [b.id, a.id, c.id]);
    assert.deepEqual(ids(moveStep(steps, b.id, 1)), [a.id, c.id, b.id]);
    assert.equal(moveStep(steps, b.id, -1)[0].filters[0].key, 'plan', 'the filters travel with the step');
    assert.deepEqual(ids(steps), [a.id, b.id, c.id], 'the original list is untouched');
});

test('cannot move the first step up, the last one down, or a step that is not there', () => {
    const steps = filled('a', 'b').steps;

    assert.equal(canMoveStep(steps, steps[0].id, -1), false);
    assert.equal(canMoveStep(steps, steps[0].id, 1), true);
    assert.equal(canMoveStep(steps, steps[1].id, 1), false);
    assert.equal(canMoveStep(steps, 999999, 1), false);
    assert.equal(moveStep(steps, steps[0].id, -1), steps);
    assert.equal(moveStep(steps, steps[1].id, 1), steps);
    assert.equal(moveStep(steps, 999999, 1), steps);
});

test('patching one step leaves the others alone', () => {
    const steps = filled('a', 'b').steps;
    const next = patchStep(steps, steps[1].id, { event: 'z' });

    assert.deepEqual(next.map((step) => step.event), ['a', 'z']);
    assert.equal(next[0], steps[0]);
});

// ---------------------------------------------------------------- window

test('reads the window as seconds, or null when it is not a whole number', () => {
    const seconds = (value, unit) => windowSeconds({ value, unit }, props.options);

    assert.equal(seconds('2', 'week'), 1209600);
    assert.equal(seconds(' 90 ', 'minute'), 5400);
    assert.equal(seconds('', 'day'), null);
    assert.equal(seconds('1.5', 'day'), null);
    assert.equal(seconds('-1', 'day'), null);
    assert.equal(seconds('1e3', 'day'), null);
    assert.equal(seconds('1', 'fortnight'), null);
});

test('writes a duration in the largest unit that divides it exactly', () => {
    assert.equal(formatDuration(1209600), '2 weeks');
    assert.equal(formatDuration(604800), '1 week');
    assert.equal(formatDuration(7776000), '90 days');
    assert.equal(formatDuration(5400), '90 minutes');
    assert.equal(formatDuration(60), '1 minute');
    assert.equal(formatDuration(7200), '2 hours');
    assert.equal(formatDuration(90), '90 seconds');
});

// ---------------------------------------------------------------- request body

test('builds the API body: trimmed events, filters per step with null for is_set, a numeric window, preset or custom dates', () => {
    const definition = filled(' signup ', 'purchase');
    definition.steps[0].filters = [newFilter({ key: 'plan', operator: 'equals', value: 'pro' }), newFilter(), newFilter({ key: 'vip', operator: 'is_set', value: 'junk' })];
    definition.window = { value: '3', unit: 'day' };
    definition.order = 'strict_order';

    assert.deepEqual(toRequestBody(definition, props), {
        steps: [
            { event: 'signup', filters: [{ key: 'plan', operator: 'equals', value: 'pro' }, { key: 'vip', operator: 'is_set', value: null }] },
            { event: 'purchase', filters: [] },
        ],
        window: { value: 3, unit: 'day' },
        order: 'strict_order',
        date_preset: 'last_30_days',
    });

    definition.range = { mode: 'custom', preset: 'last_30_days', from: '2026-03-01', to: '2026-03-07' };
    const custom = toRequestBody(definition, props);
    assert.deepEqual([custom.date_from, custom.date_to, custom.date_preset], ['2026-03-01', '2026-03-07', undefined]);
});

test('does not mutate the definition and sends every step in the order of the list', () => {
    const definition = filled('a', 'b', 'c');
    const before = JSON.stringify(definition);
    definition.steps = moveStep(definition.steps, definition.steps[2].id, -1);

    assert.deepEqual(toRequestBody(definition, props).steps.map((step) => step.event), ['a', 'c', 'b']);
    assert.notEqual(JSON.stringify(definition), before);
});

// ---------------------------------------------------------------- validation

test('a complete definition is valid; a blank step is an error that names its position', () => {
    assert.deepEqual(validateDefinition(filled('a', 'b'), props), { ok: true, errors: { steps: {} } });

    const definition = filled('a', '  ', 'c');
    const { ok, errors } = validateDefinition(definition, props);

    assert.equal(ok, false);
    assert.deepEqual(Object.keys(errors.steps), [String(definition.steps[1].id)]);
    assert.equal(errors.steps[definition.steps[1].id].event, 'Choose an event for step 2.');
});

test('mirrors the server limits: event length by code points, filters per step and in all, step count', () => {
    const emoji = filled('😀'.repeat(200), 'b');
    assert.equal(validateDefinition(emoji, props).ok, true, '200 emoji are 200 characters');

    const long = filled('x'.repeat(201), 'b');
    assert.equal(validateDefinition(long, props).errors.steps[long.steps[0].id].event, 'Event names are 1 to 200 characters.');

    const many = filled('a', 'b');
    many.steps[0].filters = Array.from({ length: 6 }, (_, i) => newFilter({ key: `k${i}`, value: 'v' }));
    assert.equal(validateDefinition(many, props).errors.steps[many.steps[0].id].general, 'Use at most 5 filters per step.');

    const total = filled('a', 'b', 'c', 'd', 'e');
    total.steps.forEach((step) => (step.filters = Array.from({ length: 5 }, (_, i) => newFilter({ key: `k${i}`, value: 'v' }))));
    assert.equal(validateDefinition(total, props).errors.general, 'Use at most 20 filters over all steps.');

    assert.equal(validateDefinition({ ...filled('a'), steps: [newStep({ event: 'a' })] }, props).errors.general, 'A funnel needs 2 to 8 steps.');
    assert.equal(validateDefinition({ ...filled('a', 'b'), steps: Array.from({ length: 9 }, () => newStep({ event: 'a' })) }, props).errors.general, 'A funnel needs 2 to 8 steps.');
});

test('a blank filter row is a draft and is not validated; a half-filled one is', () => {
    const definition = filled('a', 'b');
    definition.steps[0].filters = [newFilter()];
    assert.equal(validateDefinition(definition, props).ok, true);

    const row = newFilter({ key: '', value: 'x' });
    definition.steps[1].filters = [row];
    const { ok, errors } = validateDefinition(definition, props);

    assert.equal(ok, false);
    assert.equal(errors.steps[definition.steps[1].id].filters[row.id].key, 'Enter a property key.');
});

test('validates the window: whole number, at least a minute, at most 90 days, at most 100000 of a unit', () => {
    const check = (value, unit) => validateDefinition({ ...filled('a', 'b'), window: { value, unit } }, props).errors.window;

    assert.equal(check('14', 'day'), undefined);
    assert.equal(check('90', 'day'), undefined);
    assert.equal(check('1', 'minute'), undefined);
    assert.equal(check('', 'day'), 'Enter the window as a whole number from 1 to 100000.');
    assert.equal(check('0', 'day'), 'Enter the window as a whole number from 1 to 100000.');
    assert.equal(check('1.5', 'day'), 'Enter the window as a whole number from 1 to 100000.');
    assert.equal(check('100001', 'minute'), 'Enter the window as a whole number from 1 to 100000.', 'the server refuses a value above its sanity bound');
    assert.equal(check('91', 'day'), 'The window must be between 1 minute and 90 days.');
    assert.equal(check('13', 'week'), 'The window must be between 1 minute and 90 days.');
    assert.equal(check('1', 'week'), undefined);
});

test('validates the date range like every insight page', () => {
    const custom = (from, to) => validateDefinition({ ...filled('a', 'b'), range: { mode: 'custom', preset: 'last_30_days', from, to } }, props).errors.date;

    assert.equal(custom('2026-03-01', '2026-03-07'), undefined);
    assert.equal(custom('', ''), 'Pick both a start and an end date.');
    assert.equal(custom('2026-03-08', '2026-03-07'), 'The end date must not be before the start date.');
    assert.equal(custom('2025-01-01', '2026-03-07'), 'The range can be at most 366 days.');
});

// ---------------------------------------------------------------- server errors

test('maps the API 422 errors onto the step, filter, window and date controls, and the rest to general', () => {
    const definition = filled('a', 'b');
    definition.steps[1].filters = [newFilter(), newFilter({ key: 'plan', value: 'x' }), newFilter({ key: 'other', value: 'y' })];
    const [, second] = definition.steps;
    const realRows = second.filters.filter((row) => row.key !== '');

    const errors = mapServerErrors({
        'steps.0.event': ['Each step needs an event name of 1 to 200 characters of text.'],
        'steps.1.filters.1.operator': ['A filter operator must be equals, not_equals, contains or is_set.'],
        'steps.1.filters.0.value': ['A filter value must be text.'],
        'window.value': ['The window value must be a whole number from 1 to 100000.'],
        'window.unit': ['The window unit must be minute, hour, day or week.'],
        date_preset: ['Pick one preset.'],
        order: ['The order must be ordered or strict_order.'],
        steps: ['Provide a list of 2 to 8 steps.'],
        body: ['The request contains fields that are not supported.'],
        definition: ['This combination is not allowed.'],
        'steps.9.event': ['Beyond the list.'],
    }, definition);

    assert.equal(errors.steps[definition.steps[0].id].event, 'Each step needs an event name of 1 to 200 characters of text.');
    assert.equal(errors.steps[second.id].filters[realRows[1].id].key, 'A filter operator must be equals, not_equals, contains or is_set.');
    assert.equal(errors.steps[second.id].filters[realRows[0].id].value, 'A filter value must be text.');
    assert.equal(errors.window, 'The window value must be a whole number from 1 to 100000.');
    assert.equal(errors.date, 'Pick one preset.');
    assert.equal(errors.order, 'The order must be ordered or strict_order.');
    assert.equal(errors.general, 'Provide a list of 2 to 8 steps. The request contains fields that are not supported. This combination is not allowed. Beyond the list.');
});

test('a server error that names a filter row that is not there goes to general instead of being lost', () => {
    const definition = filled('a', 'b');

    assert.equal(mapServerErrors({ 'steps.0.filters.3.key': ['Gone.'] }, definition).general, 'Gone.');
    assert.deepEqual(mapServerErrors(null, definition), { steps: {} });
    assert.deepEqual(mapServerErrors({ 'steps.0.event': [''] }, definition), { steps: {} });
});

// ---------------------------------------------------------------- query string

test('the untouched default definition has an empty query string', () => {
    assert.equal(encodeDefinition(defaultDefinition(props), props), '');
    assert.equal(decodeDefinition('', props), null);
    assert.equal(decodeDefinition('?', props), null);
});

test('round-trips a definition through the query string; defaults are omitted', () => {
    const definition = filled('signup', 'purchase');
    definition.steps[0].filters = [newFilter({ key: 'plan', operator: 'equals', value: 'pro & "co"' }), newFilter(), newFilter({ key: 'vip', operator: 'is_set', value: '' })];
    definition.window = { value: '3', unit: 'day' };
    definition.order = 'strict_order';
    definition.range = { mode: 'custom', preset: 'last_30_days', from: '2026-03-01', to: '2026-03-07' };

    const query = encodeDefinition(definition, props);
    const back = decodeDefinition(`?${query}`, props);

    assert.deepEqual(back.steps.map((step) => [step.event, step.filters.map(({ key, operator, value }) => [key, operator, value])]), [
        ['signup', [['plan', 'equals', 'pro & "co"'], ['vip', 'is_set', '']]],
        ['purchase', []],
    ]);
    assert.deepEqual(back.window, { value: '3', unit: 'day' });
    assert.equal(back.order, 'strict_order');
    assert.deepEqual(back.range, { mode: 'custom', preset: 'last_30_days', from: '2026-03-01', to: '2026-03-07' });

    const plain = encodeDefinition(filled('a', 'b'), props);
    assert.equal(new URLSearchParams(plain).getAll('s').length, 2);
    assert.equal(new URLSearchParams(plain).has('wv') || new URLSearchParams(plain).has('o') || new URLSearchParams(plain).has('p'), false, 'defaults are left out');
});

test('keeps blank steps and their positions, and the order of the steps', () => {
    const definition = { ...defaultDefinition(props), steps: [newStep({ event: 'a' }), newStep(), newStep({ event: 'c' })] };
    const back = decodeDefinition(encodeDefinition(definition, props), props);

    assert.deepEqual(back.steps.map((step) => step.event), ['a', '', 'c']);
});

test('never trusts the URL: every bad field is dropped and the rest is kept', () => {
    const encoded = (steps) => steps.map((step) => `s=${encodeURIComponent(step)}`).join('&');
    const back = decodeDefinition([
        encoded(['["a",[]]', 'not json', '{}', '["b"]', '[1,[]]', '["c",[["k","likes","v"],["k2","equals","ok"],["k3","equals",5],["", "equals","x"]]]', `["${'x'.repeat(201)}",[]]`, '["d",[]]']),
        'wv=abc&wu=day', 'o=sideways', 'p=forever', 'from=2026-02-30&to=2026-03-01',
    ].join('&'), props);

    assert.deepEqual(back.steps.map((step) => step.event), ['a', 'c', 'd']);
    assert.deepEqual(back.steps[1].filters.map((row) => row.key), ['k2'], 'only the valid filter of a step survives');
    assert.deepEqual(back.window, { value: '2', unit: 'week' });
    assert.equal(back.order, 'ordered');
    assert.deepEqual(back.range, { mode: 'preset', preset: 'last_30_days', from: '', to: '' });
});

test('fills a short list of steps up to the minimum and cuts a long one at the maximum', () => {
    assert.equal(decodeDefinition('s=%5B%22a%22%2C%5B%5D%5D', props).steps.length, 2);
    assert.equal(decodeDefinition('s=garbage', props).steps.length, 2);

    const many = Array.from({ length: 12 }, (_, i) => `s=${encodeURIComponent(JSON.stringify([`e${i}`, []]))}`).join('&');
    assert.deepEqual(decodeDefinition(many, props).steps.map((step) => step.event), ['e0', 'e1', 'e2', 'e3', 'e4', 'e5', 'e6', 'e7']);
});

test('reads at most the allowed filters per step and in all from a link', () => {
    const rows = Array.from({ length: 9 }, (_, i) => [`k${i}`, 'equals', 'v']);
    const query = Array.from({ length: 4 }, (_, i) => `s=${encodeURIComponent(JSON.stringify([`e${i}`, rows]))}`).join('&');
    const back = decodeDefinition(query, props);

    assert.deepEqual(back.steps.map((step) => step.filters.length), [5, 5, 5, 5]);
    const fiveSteps = decodeDefinition(Array.from({ length: 5 }, (_, i) => `s=${encodeURIComponent(JSON.stringify([`e${i}`, rows]))}`).join('&'), props);
    assert.equal(fiveSteps.steps.reduce((sum, step) => sum + step.filters.length, 0), 20, 'the total limit holds too');
});

test('a window that is not a whole number never reaches the definition, and a valid one is normalised', () => {
    assert.deepEqual(decodeDefinition('wv=007&wu=hour', props).window, { value: '7', unit: 'hour' });
    assert.deepEqual(decodeDefinition('wv=1.5&wu=hour', props).window, { value: '2', unit: 'week' });
    assert.deepEqual(decodeDefinition('wv=5&wu=century', props).window, { value: '2', unit: 'week' });
    assert.deepEqual(decodeDefinition('wv=1234567&wu=day', props).window, { value: '2', unit: 'week' });
});
