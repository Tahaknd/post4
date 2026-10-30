import assert from 'node:assert/strict';
import test from 'node:test';

import { RunQueue } from '../../resources/js/lib/runQueue.js';

function jobs(queue, names) {
    const started = [];
    for (const name of names) {
        queue.enqueue(name, () => started.push(name));
    }

    return started;
}

test('starts at most `limit` jobs at once and the rest in the order asked for', () => {
    const queue = new RunQueue(3);
    const started = jobs(queue, ['a', 'b', 'c', 'd', 'e']);

    assert.deepEqual(started, ['a', 'b', 'c']);
    assert.equal(queue.activeCount, 3);
    assert.equal(queue.waitingCount, 2);

    queue.release('b');
    assert.deepEqual(started, ['a', 'b', 'c', 'd']);
    queue.release('a');
    queue.release('c');
    assert.deepEqual(started, ['a', 'b', 'c', 'd', 'e']);
    assert.equal(queue.activeCount, 2);
    assert.equal(queue.waitingCount, 0);
});

test('the number of running jobs never exceeds the limit, whatever the order of releases', () => {
    const queue = new RunQueue(3);
    let peak = 0;
    const running = new Set();
    for (let i = 0; i < 30; i++) {
        queue.enqueue(`t${i}`, () => {
            running.add(`t${i}`);
            peak = Math.max(peak, running.size);
        });
    }
    let guard = 0;
    while (running.size > 0 && guard++ < 100) {
        const key = [...running][guard % running.size];
        running.delete(key);
        queue.release(key);
    }

    assert.equal(peak, 3);
    assert.equal(queue.activeCount, 0);
    assert.equal(queue.waitingCount, 0);
});

test('a key that runs or waits is not queued twice', () => {
    const queue = new RunQueue(1);
    const started = [];

    assert.equal(queue.enqueue('a', () => started.push('a')), true);
    assert.equal(queue.enqueue('a', () => started.push('a2')), false, 'running');
    assert.equal(queue.enqueue('b', () => started.push('b')), true);
    assert.equal(queue.enqueue('b', () => started.push('b2')), false, 'waiting');

    queue.release('a');
    queue.release('b');
    assert.deepEqual(started, ['a', 'b']);
    assert.equal(queue.enqueue('a', () => started.push('a3')), true, 'free again once released');
});

test('cancel takes a waiting job out and frees the slot of a running one', () => {
    const queue = new RunQueue(1);
    const started = jobs(queue, ['a', 'b', 'c']);

    queue.cancel('b');
    assert.equal(queue.waitingCount, 1);
    queue.cancel('a');
    assert.deepEqual(started, ['a', 'c']);
    queue.cancel('missing');
    assert.equal(queue.activeCount, 1);
});

test('release of a key without a slot is harmless, and a job that throws holds no slot', () => {
    const queue = new RunQueue(1);
    queue.release('nobody');
    queue.enqueue('bad', () => {
        throw new Error('cannot start');
    });
    const started = jobs(queue, ['ok']);

    assert.deepEqual(started, ['ok']);
    assert.equal(queue.isActive('bad'), false);
    assert.equal(queue.isActive('ok'), true);
});

test('listeners hear every change and can unsubscribe; clear forgets everything; a limit below 1 is 1', () => {
    const queue = new RunQueue(0);
    assert.equal(queue.limit, 1);
    let heard = 0;
    const off = queue.subscribe(() => heard++);

    jobs(queue, ['a', 'b']);
    queue.release('a');
    assert.ok(heard >= 3);
    off();
    const before = heard;
    queue.clear();
    assert.equal(heard, before);
    assert.equal(queue.activeCount + queue.waitingCount, 0);
});
