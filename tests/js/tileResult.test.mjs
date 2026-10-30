import assert from 'node:assert/strict';
import test from 'node:test';

import { EMPTY_TEXT, isDrawable, isEmptyFor, trendsMeta } from '../../resources/js/lib/tileResult.js';

const TRENDS = { timezone: 'UTC', buckets: [0, 86400], labels: ['a'], series: [{ event: 'x', breakdown: null, is_other: false, is_none: false, data: [1, 2] }] };
const EMPTY_TRENDS = { ...TRENDS, series: [{ ...TRENDS.series[0], data: [0, 0] }] };
const FUNNEL = {
    steps: [{ index: 1, event: 'a', count: 2, dropped_off: 0, conversion_from_previous: null, conversion_from_first: 1 }, { index: 2, event: 'b', count: 1, dropped_off: 1, conversion_from_previous: 0.5, conversion_from_first: 0.5 }],
    entrants: 2, converted: 1, conversion_rate: 0.5, window_seconds: 1209600, order: 'ordered',
};

test('each type draws only the result shape of its page', () => {
    assert.equal(isDrawable('trends', TRENDS), true);
    assert.equal(isDrawable('trends', FUNNEL), false);
    assert.equal(isDrawable('funnel', FUNNEL), true);
    assert.equal(isDrawable('funnel', TRENDS), false);
    assert.equal(isDrawable('retention', FUNNEL), false);
    assert.equal(isDrawable('retention', null), false);
    assert.equal(isDrawable('paths', TRENDS), false);
});

test('empty results are told apart from results with numbers', () => {
    assert.equal(isEmptyFor('trends', TRENDS), false);
    assert.equal(isEmptyFor('trends', EMPTY_TRENDS), true);
    assert.equal(isEmptyFor('funnel', FUNNEL), false);
    assert.equal(isEmptyFor('funnel', { ...FUNNEL, entrants: 0 }), true);
    assert.equal(isEmptyFor('paths', TRENDS), false);
    for (const type of ['trends', 'funnel', 'retention']) {
        assert.ok(EMPTY_TEXT[type].length > 0);
    }
});

test('the interval and metric of a trends tile come from the stored definition', () => {
    assert.deepEqual(trendsMeta({ query: { interval: 'week', metric: 'unique_users' } }), { interval: 'week', metric: 'unique_users' });
    assert.equal(trendsMeta({ query: { interval: 'week' } }), null);
    assert.equal(trendsMeta(null), null);
});
