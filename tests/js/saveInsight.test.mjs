import assert from 'node:assert/strict';
import test from 'node:test';

import { DESCRIPTION_MAX, NAME_MAX, buildSaveBody, buildUpdateBody, isRelative, mapSaveErrors, savePlan, validateLabels } from '../../resources/js/lib/saveInsight.js';

const PRESET_QUERY = { events: ['signup'], metric: 'total_events', interval: 'day', date_preset: 'last_7_days', filters: [] };
const ABSOLUTE_QUERY = { events: ['signup'], metric: 'total_events', interval: 'day', date_from: '2026-10-01', date_to: '2026-10-07', filters: [] };

test('the query is the body of the page, untouched: a preset stays a preset, absolute days stay absolute', () => {
    const relative = buildSaveBody({ type: 'trends', name: ' Signups ', description: '', query: PRESET_QUERY });
    const absolute = buildSaveBody({ type: 'trends', name: 'Signups', description: 'October', query: ABSOLUTE_QUERY });

    assert.equal(relative.query, PRESET_QUERY);
    assert.equal(relative.query.date_preset, 'last_7_days');
    assert.equal('date_from' in relative.query, false);
    assert.equal(isRelative(relative.query), true);
    assert.equal(absolute.query, ABSOLUTE_QUERY);
    assert.equal('date_preset' in absolute.query, false);
    assert.equal(isRelative(absolute.query), false);
});

test('the name is trimmed and the description is left out when blank', () => {
    assert.deepEqual(buildSaveBody({ type: 'funnel', name: '  A funnel  ', description: '   ', query: {} }), { name: 'A funnel', type: 'funnel', query: {} });
    assert.deepEqual(buildSaveBody({ type: 'funnel', name: 'A', description: ' note ', query: {} }), { name: 'A', type: 'funnel', query: {}, description: 'note' });
    assert.deepEqual(buildUpdateBody(PRESET_QUERY), { query: PRESET_QUERY });
});

test('instant label validation counts characters like the server', () => {
    assert.deepEqual(validateLabels({ name: '', description: '' }), { name: 'Enter a name.' });
    assert.deepEqual(validateLabels({ name: '   ', description: '' }), { name: 'Enter a name.' });
    assert.deepEqual(validateLabels({ name: 'a'.repeat(NAME_MAX), description: 'd'.repeat(DESCRIPTION_MAX) }), {});
    assert.match(validateLabels({ name: 'a'.repeat(NAME_MAX + 1), description: '' }).name, /at most 120/);
    assert.match(validateLabels({ name: 'a', description: 'd'.repeat(DESCRIPTION_MAX + 1) }).description, /at most 500/);
    assert.deepEqual(validateLabels({ name: '😀'.repeat(NAME_MAX), description: '' }), {}, 'an emoji is one character');
});

test('422 messages go to their fields, everything else becomes one general sentence', () => {
    assert.deepEqual(mapSaveErrors({ name: 'A name is required.', description: 'Too long.' }), { name: 'A name is required.', description: 'Too long.' });
    assert.deepEqual(mapSaveErrors({ 'query.events': 'Provide events.', limit: 'A project can have at most 200 saved insights. Delete one first.', 'query.date_preset': 'Provide events.' }), {
        general: 'Provide events. A project can have at most 200 saved insights. Delete one first.',
    });
    assert.deepEqual(mapSaveErrors({}), {});
    assert.deepEqual(mapSaveErrors(undefined), {});
});

test('the plan skips what is already done', () => {
    assert.deepEqual(savePlan({ target: { mode: 'none' } }), ['insight']);
    assert.deepEqual(savePlan({ target: { mode: 'existing', id: 'd' } }), ['insight', 'tile']);
    assert.deepEqual(savePlan({ target: { mode: 'new', name: 'N' } }), ['insight', 'dashboard', 'tile']);
    assert.deepEqual(savePlan({ target: { mode: 'new', name: 'N' }, insightId: 'i' }), ['dashboard', 'tile']);
    assert.deepEqual(savePlan({ target: { mode: 'new', name: 'N' }, insightId: 'i', dashboardId: 'd' }), ['tile']);
    assert.deepEqual(savePlan({ target: { mode: 'none' }, insightId: 'i' }), []);
});
