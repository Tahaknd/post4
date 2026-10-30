import assert from 'node:assert/strict';
import test from 'node:test';

import { paginate, rangeText } from '../../resources/js/lib/pagination.js';

const items = Array.from({ length: 45 }, (_, i) => i + 1);

test('slices pages and reports the range', () => {
    const second = paginate(items, 2, 20);
    assert.equal(second.items[0], 21);
    assert.equal(second.items.length, 20);
    assert.deepEqual([second.page, second.lastPage, second.total, second.from, second.to], [2, 3, 45, 21, 40]);
    assert.equal(rangeText(second, 'dashboards'), 'Showing 21 to 40 of 45');
    assert.deepEqual(paginate(items, 3, 20).items, [41, 42, 43, 44, 45]);
});

test('a page out of range or not a number is clamped; an empty list has one empty page', () => {
    assert.equal(paginate(items, 99, 20).page, 3);
    assert.equal(paginate(items, 0, 20).page, 1);
    assert.equal(paginate(items, 'x', 20).page, 1);
    const empty = paginate([], 1, 20);
    assert.deepEqual([empty.page, empty.lastPage, empty.from, empty.to, empty.items.length], [1, 1, 0, 0, 0]);
    assert.equal(rangeText(empty, 'saved insights'), 'No saved insights');
});
