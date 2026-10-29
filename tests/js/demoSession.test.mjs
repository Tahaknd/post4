import assert from 'node:assert/strict';
import test from 'node:test';

import { createDemoSession } from '../../resources/js/lib/demoSession.js';

/** A load() whose SDKs are controlled by the test: each call returns a promise settled by hand. */
function harness({ stopFails = false } = {}) {
    const events = [];
    const loads = [];
    const session = createDemoSession({
        load: (options) =>
            new Promise((resolve, reject) => {
                loads.push({
                    options,
                    resolve: () => {
                        const sdk = {
                            id: loads.length,
                            stopped: false,
                            stop: async () => {
                                sdk.stopped = true;
                                events.push(`stop ${sdk.id}`);
                                if (stopFails) {
                                    throw new Error('stop failed');
                                }
                            },
                        };
                        resolve(sdk);

                        return sdk;
                    },
                    reject,
                });
            }),
        onStatus: (status) => events.push(`status ${status}`),
        onReady: (sdk) => events.push(`ready ${sdk.id}`),
        onFailure: (error) => events.push(`failure ${error.message}`),
        onStopFailure: (error) => events.push(`stop-failure ${error.message}`),
    });

    return { session, events, loads };
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

test('starts, reports loading then ready, and hands over the SDK', async () => {
    const { session, events, loads } = harness();
    const started = session.start(() => ({ n: 1 }));

    assert.deepEqual(events, ['status loading']);
    assert.deepEqual(loads[0].options, { n: 1 });
    const sdk = loads[0].resolve();
    await started;

    assert.deepEqual(events, ['status loading', 'status ready', 'ready 1']);
    assert.equal(session.current(), sdk);
});

test('reports a failed start once and keeps no SDK', async () => {
    const { session, events, loads } = harness();
    const started = session.start(() => ({}));
    loads[0].reject(new Error('chunk 404'));
    await started;

    assert.deepEqual(events, ['status loading', 'status failed', 'failure chunk 404']);
    assert.equal(session.current(), null);
});

test('restart stops the running SDK first, reads the options when the new load begins, and shows loading throughout', async () => {
    const { session, events, loads } = harness();
    let options = { autocapture: true };
    const first = session.start(() => options);
    const one = loads[0].resolve();
    await first;
    events.length = 0;

    options = { autocapture: false };
    const restarted = session.restart(() => options);
    assert.equal(events[0], 'status loading');
    await tick();
    assert.equal(one.stopped, true);
    assert.deepEqual(loads[1].options, { autocapture: false });
    loads[1].resolve();
    await restarted;

    assert.deepEqual(events, ['status loading', 'stop 1', 'status loading', 'status ready', 'ready 2']);
    assert.equal(session.current().id, 2);
});

test('an SDK that finishes loading after a restart began is stopped, not kept', async () => {
    const { session, events, loads } = harness();
    const first = session.start(() => ({}));
    const restarted = session.restart(() => ({}));
    await tick();

    const stale = loads[0].resolve();
    await first;
    assert.equal(stale.stopped, true);
    assert.equal(session.current(), null);

    loads[1].resolve();
    await restarted;
    assert.equal(session.current().id, 2);
    assert.ok(!events.includes('ready 1'));
});

test('two quick restarts end with exactly one running SDK, started with the newest options', async () => {
    const { session, loads } = harness();
    const first = session.start(() => ({}));
    loads[0].resolve();
    await first;

    let options = { gzip: true };
    const a = session.restart(() => options);
    options = { gzip: false };
    const b = session.restart(() => options);
    await tick();
    await tick();

    // Every load that began read the newest options; resolve them all in order.
    for (const load of loads.slice(1)) {
        assert.deepEqual(load.options, { gzip: false });
        load.resolve();
        await tick();
    }
    await Promise.all([a, b]);

    assert.equal(session.current().stopped, false);
    assert.equal(loads.slice(1).length >= 1, true);
});

test('a stop that throws is reported and does not wedge the session or reject', async () => {
    const { session, events, loads } = harness({ stopFails: true });
    const first = session.start(() => ({}));
    loads[0].resolve();
    await first;

    const restarted = session.restart(() => ({}));
    await tick();
    loads[1].resolve();
    await restarted;

    assert.ok(events.includes('stop-failure stop failed'));
    assert.equal(events.at(-1), 'ready 2');
    assert.equal(session.current().id, 2);
});

test('dispose stops the running SDK and one that is still loading, and stays quiet afterwards', async () => {
    const { session, events, loads } = harness();
    const first = session.start(() => ({}));
    const running = loads[0].resolve();
    await first;

    const second = session.restart(() => ({}));
    await tick();
    await session.dispose();
    const late = loads[1].resolve();
    await second;

    assert.equal(running.stopped, true);
    assert.equal(late.stopped, true);
    assert.equal(session.current(), null);
    assert.ok(!events.includes('ready 2'));

    // a failure that arrives after the page was left is not reported either
    const { session: other, events: otherEvents, loads: otherLoads } = harness();
    const pending = other.start(() => ({}));
    await other.dispose();
    otherLoads[0].reject(new Error('late'));
    await pending;
    assert.ok(!otherEvents.some((event) => event.startsWith('failure')));
});
