import assert from 'node:assert/strict';
import test from 'node:test';

import { arrange, canMove, move, moveMessage, sameOrder } from '../../resources/js/lib/tileOrder.js';

const IDS = ['a', 'b', 'c', 'd'];

test('moves a tile one place earlier or later and returns a new list', () => {
    assert.deepEqual(move(IDS, 'b', -1), ['b', 'a', 'c', 'd']);
    assert.deepEqual(move(IDS, 'b', 1), ['a', 'c', 'b', 'd']);
    assert.deepEqual(IDS, ['a', 'b', 'c', 'd'], 'the input is untouched');
});

test('the first cannot move earlier, the last cannot move later, an unknown id cannot move', () => {
    assert.equal(canMove(IDS, 'a', -1), false);
    assert.equal(canMove(IDS, 'd', 1), false);
    assert.equal(canMove(IDS, 'a', 1), true);
    assert.equal(canMove(IDS, 'zzz', 1), false);
    assert.equal(move(IDS, 'a', -1), IDS);
    assert.equal(move(IDS, 'zzz', 1), IDS);
});

test('a move keeps exactly the same set of ids', () => {
    let ids = IDS;
    for (const [id, delta] of [['a', 1], ['a', 1], ['d', -1], ['b', -1], ['c', 1]]) {
        ids = move(ids, id, delta);
        assert.deepEqual([...ids].sort(), [...IDS].sort());
    }
});

test('the message says where the tile went', () => {
    assert.equal(moveMessage('Sessions', move(IDS, 'c', -1), 'c', -1), 'Sessions moved earlier, now position 2 of 4.');
    assert.equal(moveMessage('Sessions', move(IDS, 'a', 1), 'a', 1), 'Sessions moved later, now position 2 of 4.');
});

test('arrange orders tiles by ids; unknown ids drop, tiles outside the list go last', () => {
    const tiles = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];

    assert.deepEqual(arrange(tiles, ['c', 'a', 'b']).map((tile) => tile.id), ['c', 'a', 'b']);
    assert.deepEqual(arrange(tiles, ['c', 'x']).map((tile) => tile.id), ['c', 'a', 'b']);
});

test('sameOrder compares ids in order', () => {
    assert.equal(sameOrder(['a', 'b'], ['a', 'b']), true);
    assert.equal(sameOrder(['a', 'b'], ['b', 'a']), false);
    assert.equal(sameOrder(['a'], ['a', 'b']), false);
});
