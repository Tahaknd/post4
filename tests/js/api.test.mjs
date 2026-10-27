import assert from 'node:assert/strict';
import test from 'node:test';

import { apiHeaders, errorOf, readXsrfToken, requestJson, retryAfterMs, withTimeout } from '../../resources/js/lib/api.js';
import { FakeClock, fakeFetch, json } from './helpers.mjs';

test('builds the headers: JSON in and out, the decoded XSRF token, no Content-Type without a body', () => {
    assert.deepEqual(apiHeaders({ token: 'abc==' }), { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': 'abc==' });
    assert.deepEqual(apiHeaders({ token: '', body: true }), { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' });
    assert.equal(readXsrfToken('a=1; XSRF-TOKEN=eyJ%3D%3D; b=2'), 'eyJ==');
    assert.equal(readXsrfToken('XSRF-TOKEN=%E0%A4%A'), '');
});

test('parses Retry-After (delta seconds only, capped) and reduces an error body to what may be shown', () => {
    assert.equal(retryAfterMs('2'), 2000);
    assert.equal(retryAfterMs('99999'), 30000);
    assert.equal(retryAfterMs('Wed, 21 Oct 2026 07:28:00 GMT'), null);
    assert.equal(retryAfterMs(null), null);
    assert.equal(errorOf(null), null);
    assert.deepEqual(errorOf({ error: { kind: 'busy', message: 'm', retryable: true, retry_after_seconds: 3, extra: '<script>' } }), { kind: 'busy', message: 'm', retryable: true, retry_after_seconds: 3 });
    assert.equal(errorOf({ error: { kind: 'x', message: 'y'.repeat(1000) } }).message.length, 300);
});

test('withTimeout aborts at the limit, follows the caller signal, and done() clears the timer and the listener', async () => {
    const clock = new FakeClock();
    const outer = new AbortController();

    const a = withTimeout(outer.signal, 1000, { setTimer: clock.setTimer, clearTimer: clock.clearTimer });
    await clock.advance(999);
    assert.equal(a.signal.aborted, false);
    await clock.advance(2);
    assert.equal(a.signal.aborted, true);
    assert.equal(a.timedOut(), true);

    const b = withTimeout(outer.signal, 1000, { setTimer: clock.setTimer, clearTimer: clock.clearTimer });
    b.done();
    assert.equal(clock.pending, 0, 'the timer is cleared');
    outer.abort();
    assert.equal(b.signal.aborted, false, 'a finished request is no longer linked to the caller');

    const c = withTimeout(outer.signal, 1000, { setTimer: clock.setTimer, clearTimer: clock.clearTimer });
    assert.equal(c.signal.aborted, true, 'an already aborted caller aborts at once');
    assert.equal(c.timedOut(), false);
    c.done();
});

test('requestJson: answer, timeout, aborted and network are told apart', async () => {
    const clock = new FakeClock();
    const timers = { setTimer: clock.setTimer, clearTimer: clock.clearTimer };

    const ok = fakeFetch(() => json(200, { a: 1 }, { 'Retry-After': '3' }));
    assert.deepEqual(await requestJson({ method: 'GET', url: '/x', timeoutMs: 1000, fetch: ok.fetch, ...timers }), { status: 200, retryAfter: 3000, json: { a: 1 } });
    assert.equal(clock.pending, 0);

    const hang = fakeFetch(() => new Promise(() => {}));
    const pending = requestJson({ method: 'GET', url: '/x', timeoutMs: 1000, fetch: hang.fetch, ...timers });
    await clock.advance(1001);
    assert.equal(await pending, 'timeout');

    const outer = new AbortController();
    const hang2 = fakeFetch(() => new Promise(() => {}));
    const second = requestJson({ method: 'GET', url: '/x', signal: outer.signal, timeoutMs: 1000, fetch: hang2.fetch, ...timers });
    outer.abort();
    assert.equal(await second, 'aborted');

    assert.equal(await requestJson({ method: 'GET', url: '/x', timeoutMs: 1000, fetch: fakeFetch(() => new Error('socket')).fetch, ...timers }), 'network');

    const noBody = await requestJson({ method: 'GET', url: '/x', timeoutMs: 1000, fetch: fakeFetch(() => json(500, undefined)).fetch, ...timers });
    assert.deepEqual(noBody, { status: 500, retryAfter: null, json: null });
});
