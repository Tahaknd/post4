import assert from 'node:assert/strict';
import test from 'node:test';

import { canRepair, needsReview, reasonText, stateBadge, tileView, typeLabel } from '../../resources/js/lib/savedInsightState.js';
import { filterSavedInsights, pickable } from '../../resources/js/lib/filterInsights.js';

const ok = { type: 'trends', state: { status: 'ok', reason: null } };
const broken = { type: 'funnel', state: { status: 'needs_review', reason: 'outdated_version' } };

test('needs_review is read from the state, with a fixed sentence per reason', () => {
    assert.equal(needsReview(ok), false);
    assert.equal(needsReview(broken), true);
    assert.match(reasonText({ reason: 'outdated_version' }), /older version/);
    assert.match(reasonText({ reason: 'invalid_date' }), /date range/);
    assert.match(reasonText({ reason: 'something new' }), /cannot run as it is stored/);
    assert.deepEqual(stateBadge(broken), { label: 'Needs review', tone: 'warning' });
    assert.deepEqual(stateBadge(ok), { label: 'OK', tone: 'success' });
});

test('a type the app no longer has cannot be repaired in a page', () => {
    assert.equal(canRepair(broken), true);
    assert.equal(canRepair({ type: 'paths' }), false);
    assert.equal(typeLabel('retention'), 'Retention');
    assert.equal(typeLabel('paths'), 'paths');
});

test('the view of a tile: needs_review first, then waiting, then the phase of its run', () => {
    const base = { insight: ok, phase: 'idle', waiting: false, hasResult: false, empty: false };

    assert.equal(tileView({ ...base, insight: broken, phase: 'done', hasResult: true }), 'needs_review');
    assert.equal(tileView({ ...base, waiting: true }), 'waiting');
    assert.equal(tileView({ ...base, phase: 'loading' }), 'loading');
    assert.equal(tileView({ ...base, phase: 'idle' }), 'loading');
    assert.equal(tileView({ ...base, phase: 'done', hasResult: true }), 'result');
    assert.equal(tileView({ ...base, phase: 'done', hasResult: true, empty: true }), 'empty');
    assert.equal(tileView({ ...base, phase: 'done', hasResult: false }), 'failed');
    assert.equal(tileView({ ...base, phase: 'failed' }), 'failed');
    assert.equal(tileView({ ...base, phase: 'invalid' }), 'failed');
    assert.equal(tileView({ ...base, phase: 'expired' }), 'expired');
    assert.equal(tileView({ ...base, phase: 'cancelled' }), 'cancelled');
});

const LIST = [
    { id: '1', name: 'Signups', description: 'Weekly', type: 'trends' },
    { id: '2', name: 'Checkout funnel', description: null, type: 'funnel' },
    { id: '3', name: 'Week one retention', description: 'signups come back', type: 'retention' },
];

test('filters by type and by a case-insensitive search on name and description', () => {
    assert.deepEqual(filterSavedInsights(LIST, { type: 'funnel' }).map((i) => i.id), ['2']);
    assert.deepEqual(filterSavedInsights(LIST, { search: ' SIGNUPS ' }).map((i) => i.id), ['1', '3']);
    assert.deepEqual(filterSavedInsights(LIST, { type: 'trends', search: 'signups' }).map((i) => i.id), ['1']);
    assert.deepEqual(filterSavedInsights(LIST, {}).length, 3);
});

test('the picker lists only insights that are not on the dashboard yet', () => {
    assert.deepEqual(pickable(LIST, ['1']).map((i) => i.id), ['2', '3']);
    assert.deepEqual(pickable(LIST, ['1'], 'week').map((i) => i.id), ['3']);
    assert.deepEqual(pickable(LIST, ['1', '2', '3']), []);
});
