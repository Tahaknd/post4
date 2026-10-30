import assert from 'node:assert/strict';
import test from 'node:test';

import {
    DEBOUNCE_MS,
    FAST_POLLS,
    InsightRunClient,
    MAX_BUSY_RETRIES,
    MIN_POST_GAP_MS,
    POLL_FAST_MS,
    POLL_SLOW_MS,
    REQUEST_TIMEOUT_MS,
    TIMEOUT_MS,
    errorOf,
    readXsrfToken,
    retryAfterMs,
} from '../../resources/js/lib/insightRunClient.js';
import { FakeClock, fakeFetch, flush, json } from './helpers.mjs';

const TRENDS = '/api/projects/7/insights/trends';
const RUNS = '/api/projects/7/insights/runs/';
const ORIGIN = 'http://localhost:8000';
const RUN_A = 'a'.repeat(32);
const RUN_B = 'b'.repeat(32);
const url = (id) => `/api/projects/7/insights/runs/${id}`;
const RESULT = { timezone: 'UTC', buckets: [0], labels: ['x'], series: [{ event: 'signup', breakdown: null, is_other: false, is_none: false, data: [3] }] };
const ERROR = { message: 'The query took too long.', error: { kind: 'timeout', message: 'The query took too long.', retryable: false, retry_after_seconds: null } };

function setup(routes, cookie = 'XSRF-TOKEN=tok%3D%3D', fetchOptions = {}) {
    const clock = new FakeClock();
    const { fetch, calls } = fakeFetch((call, all) => {
        call.at = clock.time;

        return routes(call, all);
    }, fetchOptions);
    const states = [];
    const client = new InsightRunClient({
        postUrl: TRENDS,
        runsUrl: RUNS,
        origin: ORIGIN,
        onChange: (state) => states.push(state),
        fetch,
        now: clock.now,
        setTimer: clock.setTimer,
        clearTimer: clock.clearTimer,
        getCookie: () => readXsrfToken(cookie),
    });

    return { clock, calls, states, client, last: () => client.state };
}

const posts = (calls) => calls.filter((call) => call.method === 'POST');
const polls = (calls) => calls.filter((call) => call.method === 'GET');
const deletes = (calls) => calls.filter((call) => call.method === 'DELETE');

test('debounces: only the last definition of a burst is posted, after the debounce', async () => {
    const { client, clock, calls, last } = setup(() => json(200, { status: 'done', run_id: null, result: RESULT }));

    client.schedule({ events: ['a'] });
    await clock.advance(DEBOUNCE_MS - 100);
    client.schedule({ events: ['b'] });
    await clock.advance(DEBOUNCE_MS - 1);
    assert.equal(posts(calls).length, 0);
    assert.equal(last().phase, 'loading');

    await clock.advance(2);
    assert.equal(posts(calls).length, 1);
    assert.deepEqual(posts(calls)[0].body, { events: ['b'] });
    assert.equal(last().phase, 'done');
    assert.deepEqual(last().result, RESULT);
});

test('sends JSON, the decoded XSRF token, same-origin credentials and no raw cookie value', async () => {
    const { client, clock, calls } = setup(() => json(200, { status: 'done', run_id: null, result: RESULT }));

    client.schedule({ events: ['a'] });
    await clock.advance(DEBOUNCE_MS + 1);

    const [post] = posts(calls);
    assert.equal(post.headers.Accept, 'application/json');
    assert.equal(post.headers['Content-Type'], 'application/json');
    assert.equal(post.headers['X-XSRF-TOKEN'], 'tok==');
    assert.equal(post.credentials, 'same-origin');
    assert.equal(post.url, TRENDS);
});

test('a cached 200 is the result at once and starts no polling', async () => {
    const { client, clock, calls, last } = setup(() => json(200, { status: 'done', run_id: null, result: RESULT }));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 1);
    await clock.advance(10000);

    assert.equal(last().phase, 'done');
    assert.equal(polls(calls).length, 0);
    assert.equal(deletes(calls).length, 0);
});

test('a 202 is polled every second, then done; nothing is cancelled', async () => {
    let polled = 0;
    const { client, clock, calls, last } = setup((call) => {
        if (call.method === 'POST') {
            return json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) });
        }
        polled++;

        return polled < 3 ? json(200, { status: polled === 1 ? 'queued' : 'running', run_id: RUN_A }) : json(200, { status: 'done', run_id: RUN_A, result: RESULT });
    });

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 1);
    assert.equal(polls(calls).length, 0);
    await clock.advance(POLL_FAST_MS);
    assert.equal(polls(calls).length, 1);
    await clock.advance(POLL_FAST_MS);
    assert.equal(polls(calls).length, 2);
    assert.equal(last().phase, 'loading');
    await clock.advance(POLL_FAST_MS);

    assert.equal(last().phase, 'done');
    assert.equal(polls(calls).length, 3);
    assert.equal(polls(calls)[0].url, url(RUN_A));
    assert.equal(deletes(calls).length, 0);
    await clock.advance(20000);
    assert.equal(polls(calls).length, 3, 'polling stops after done');
});

test('polls every second for the first four polls, then every 2.5 s; a 202 Retry-After sets the first delay', async () => {
    const { client, clock, calls } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) }, { 'Retry-After': '2' }) : json(200, { status: 'running', run_id: RUN_A })));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 1);
    const postAt = posts(calls)[0].at;
    await clock.advance(30000);

    const times = polls(calls).map((call) => call.at);
    const gaps = times.slice(1).map((at, i) => at - times[i]);
    assert.equal(times[0] - postAt, 2000, 'the first poll waits the Retry-After of the 202');
    assert.deepEqual(gaps.slice(0, FAST_POLLS - 1), Array(FAST_POLLS - 1).fill(POLL_FAST_MS));
    assert.ok(gaps.slice(FAST_POLLS).every((gap) => gap === POLL_SLOW_MS), JSON.stringify(gaps));
    assert.equal(gaps[FAST_POLLS - 1], POLL_SLOW_MS);
});

test('a poll answer with Retry-After is a floor for the next poll', async () => {
    const { client, clock, calls } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) }) : json(200, { status: 'queued', run_id: RUN_A }, { 'Retry-After': '4' })));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 30000);

    const times = polls(calls).map((call) => call.at);
    assert.equal(times[1] - times[0], 4000);
});

test('a change while a run is queued cancels it with DELETE and only the new run may change the state', async () => {
    let first = true;
    const { client, clock, calls, last } = setup((call) => {
        if (call.method === 'POST') {
            const run = first ? RUN_A : RUN_B;
            first = false;

            return json(202, { status: 'queued', run_id: run, poll_url: url(run) });
        }
        if (call.method === 'DELETE') {
            return json(204, undefined);
        }

        return call.url === url(RUN_A) ? json(200, { status: 'done', run_id: RUN_A, result: { ...RESULT, timezone: 'STALE' } }) : json(200, { status: 'done', run_id: RUN_B, result: RESULT });
    });

    client.schedule({ events: ['a'] });
    await clock.advance(DEBOUNCE_MS + 1);
    assert.equal(posts(calls).length, 1);

    client.schedule({ events: ['b'] });
    await clock.advance(1);
    assert.equal(deletes(calls).length, 1);
    assert.equal(deletes(calls)[0].url, url(RUN_A));
    assert.equal(deletes(calls)[0].credentials, 'same-origin');
    assert.equal(deletes(calls)[0].headers['X-XSRF-TOKEN'], 'tok==');

    await clock.advance(MIN_POST_GAP_MS + DEBOUNCE_MS + POLL_FAST_MS + 100);
    assert.equal(last().phase, 'done');
    assert.equal(last().result.timezone, 'UTC');
    assert.deepEqual(polls(calls).map((call) => call.url).filter((u) => u === url(RUN_A)), [], 'the superseded run is never polled');
});

test('the 202 of a POST that was superseded while in flight is ignored and its run is cancelled at once', async () => {
    let release;
    const gate = new Promise((resolve) => (release = resolve));
    let n = 0;
    const { client, clock, calls, last } = setup(async (call) => {
        if (call.method === 'POST') {
            n++;
            if (n === 1) {
                await gate;

                return json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) });
            }

            return json(200, { status: 'done', run_id: null, result: RESULT });
        }

        return json(204, undefined);
    });

    client.schedule({ events: ['a'] });
    await clock.advance(DEBOUNCE_MS + 1);
    client.schedule({ events: ['b'] });
    release();
    await flush();

    assert.deepEqual(deletes(calls).map((call) => call.url), [url(RUN_A)]);
    await clock.advance(MIN_POST_GAP_MS + DEBOUNCE_MS + 10);
    assert.equal(last().phase, 'done');
    assert.equal(polls(calls).length, 0, 'the superseded run is never polled');
});

test('a 404 poll means expired, a failed run shows the server error verbatim, a cancelled run is expired too', async () => {
    for (const [answer, check] of [
        [json(404, { message: 'Not found.', error: { kind: 'not_found', message: 'Not found.', retryable: false, retry_after_seconds: null } }), (s) => assert.equal(s.phase, 'expired')],
        [json(200, { status: 'failed', run_id: RUN_A, error: ERROR.error }), (s) => { assert.equal(s.phase, 'failed'); assert.deepEqual(s.error, ERROR.error); }],
        [json(200, { status: 'cancelled', run_id: RUN_A }), (s) => assert.equal(s.phase, 'expired')],
    ]) {
        const { client, clock, calls, last } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) }) : answer));

        client.schedule({});
        await clock.advance(DEBOUNCE_MS + POLL_FAST_MS + 5);

        check(last());
        assert.equal(last().result, null);
        await clock.advance(10000);
        assert.equal(polls(calls).length, 1, 'polling stopped');
    }
});

test('gives up after 60 s, cancels the run and offers a retry', async () => {
    const { client, clock, calls, last } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) }) : call.method === 'DELETE' ? json(204, undefined) : json(200, { status: 'running', run_id: RUN_A })));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + TIMEOUT_MS + POLL_SLOW_MS * 2);

    assert.equal(last().phase, 'failed');
    assert.equal(last().error.kind, 'client_timeout');
    assert.equal(last().error.retryable, true);
    assert.equal(deletes(calls).length, 1);
    assert.equal(deletes(calls)[0].url, url(RUN_A));
});

test('429 and 503 on POST honour Retry-After, retry a bounded number of times, then show the server error', async () => {
    const busy = { message: 'busy', error: { kind: 'busy', message: 'Too many insights of this project are running. Try again in a few seconds.', retryable: true, retry_after_seconds: 3 } };
    const { client, clock, calls, last } = setup(() => json(429, busy, { 'Retry-After': '2' }));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 1);
    assert.equal(posts(calls).length, 1);
    assert.equal(last().notice, 'The server is busy. Retrying…');

    await clock.advance(1998);
    assert.equal(posts(calls).length, 1, 'waits the full Retry-After');
    await clock.advance(3);
    assert.equal(posts(calls).length, 2);

    await clock.advance(2000 * MAX_BUSY_RETRIES + 100);
    assert.equal(posts(calls).length, 1 + MAX_BUSY_RETRIES);
    assert.equal(last().phase, 'failed');
    assert.deepEqual(last().error, busy.error);
    assert.equal(last().error.retryable, true);
});

test('a busy answer recovers when a retry succeeds', async () => {
    let n = 0;
    const { client, clock, last } = setup(() => (++n === 1 ? json(503, { message: 'x', error: { kind: 'unavailable', message: 'Insights are temporarily unavailable.', retryable: true, retry_after_seconds: 5 } }, { 'Retry-After': '5' }) : json(200, { status: 'done', run_id: null, result: RESULT })));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 5200);

    assert.equal(last().phase, 'done');
});

test('never posts twice within a second, however fast a retry is requested', async () => {
    const { client, clock, calls } = setup(() => json(200, { status: 'done', run_id: null, result: RESULT }));

    client.schedule({ events: ['a'] });
    await clock.advance(DEBOUNCE_MS + 1);
    client.retry();
    client.retry();
    client.retry();
    await clock.advance(5000);

    const times = posts(calls).map((call) => call.at);
    assert.ok(times.length >= 2, 'a retry posts again');
    for (let i = 1; i < times.length; i++) {
        assert.ok(times[i] - times[i - 1] >= MIN_POST_GAP_MS, `POST ${i} came ${times[i] - times[i - 1]} ms after the previous one`);
    }
});

test('422 maps to the invalid phase with the field errors and the server message', async () => {
    const body = { message: 'x', error: { kind: 'validation', message: 'The events field is required.', retryable: false, retry_after_seconds: null }, errors: { 'filters.0.key': ['A filter key must be 1 to 200 characters of text.'] } };
    const { client, clock, last } = setup(() => json(422, body));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 1);

    assert.equal(last().phase, 'invalid');
    assert.deepEqual(last().fieldErrors, body.errors);
    assert.equal(last().error.message, 'The events field is required.');
});

test('401, 419 and 500 show their fixed server sentences; a non-JSON body and a dropped connection get client sentences', async () => {
    const cases = [
        [json(401, { message: 'Unauthenticated.', error: { kind: 'unauthenticated', message: 'Unauthenticated.', retryable: false, retry_after_seconds: null } }), 'unauthenticated', 'Unauthenticated.'],
        [json(419, { message: 'x', error: { kind: 'page_expired', message: 'The page expired. Reload and try again.', retryable: false, retry_after_seconds: null } }), 'page_expired', 'The page expired. Reload and try again.'],
        [json(500, undefined), 'unexpected', 'Something unexpected happened. Try again.'],
        [new Error('socket'), 'network', 'The server could not be reached. Check your connection and try again.'],
    ];

    for (const [answer, kind, message] of cases) {
        const { client, clock, last } = setup(() => answer);
        client.schedule({});
        await clock.advance(DEBOUNCE_MS + 1);

        assert.equal(last().phase, 'failed');
        assert.equal(last().error.kind, kind);
        assert.equal(last().error.message, message);
    }
});

test('refuses a poll url that is not a run of this project on this origin', async () => {
    for (const bad of [
        'https://evil.example/api/projects/7/insights/runs/' + RUN_A,
        'http://localhost:9999/api/projects/7/insights/runs/' + RUN_A,
        '/api/projects/8/insights/runs/' + RUN_A,
        '/api/projects/7/insights/runs/xyz',
        '/api/projects/7/insights/runs/' + RUN_A + '/../x',
        '/api/projects/7/insights/runs/' + RUN_A + '?x=1',
        '/api/projects/7/insights/runs/' + RUN_A + '#frag',
        '//evil.example/api/projects/7/insights/runs/' + RUN_A,
        'http://user:pw@localhost:8000/api/projects/7/insights/runs/' + RUN_A.toUpperCase(),
        42,
    ]) {
        const { client, clock, calls, last } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: bad }) : json(200, { status: 'running' })));

        client.schedule({});
        await clock.advance(DEBOUNCE_MS + POLL_FAST_MS * 3);

        assert.equal(last().phase, 'failed', String(bad));
        assert.equal(polls(calls).length, 0, String(bad));
        assert.equal(deletes(calls).length, 0, String(bad));
    }
});

test('accepts an absolute same-origin poll url and polls its path', async () => {
    const { client, clock, calls, last } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: ORIGIN + url(RUN_A) }) : json(200, { status: 'done', run_id: RUN_A, result: RESULT })));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + POLL_FAST_MS + 5);

    assert.equal(last().phase, 'done');
    assert.equal(polls(calls)[0].url, url(RUN_A));
});

test('never gives the POST an abort signal from outside: a superseded POST still delivers its answer, and its run is cancelled', async () => {
    let release;
    const gate = new Promise((resolve) => (release = resolve));
    let n = 0;
    const { client, clock, calls } = setup(async (call) => {
        if (call.method === 'POST' && ++n === 1) {
            await gate;

            return json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) });
        }

        return call.method === 'POST' ? json(200, { status: 'done', run_id: null, result: RESULT }) : json(204, undefined);
    });

    client.schedule({ events: ['a'] });
    await clock.advance(DEBOUNCE_MS + 1);
    const first = posts(calls)[0];
    client.schedule({ events: ['b'] });
    await flush();

    assert.equal(first.signal.aborted, false, 'the POST in flight was not aborted by the supersede');
    release();
    await flush();
    assert.deepEqual(deletes(calls).map((call) => call.url), [url(RUN_A)]);
});

test('a request that never answers ends in client_timeout: the POST after its own limit, nothing hangs', async () => {
    const { client, clock, calls, last } = setup(() => new Promise(() => {}));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + REQUEST_TIMEOUT_MS - 1);
    assert.equal(last().phase, 'loading');
    await clock.advance(2);

    assert.equal(last().phase, 'failed');
    assert.equal(last().error.kind, 'client_timeout');
    assert.equal(last().error.retryable, true);
    assert.equal(posts(calls).length, 1);
});

test('a poll that never answers is retried a bounded number of times, then the run is cancelled and reported as client_timeout', async () => {
    const { client, clock, calls, last } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) }) : call.method === 'DELETE' ? json(204, undefined) : new Promise(() => {})));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + TIMEOUT_MS);

    assert.equal(last().phase, 'failed');
    assert.equal(last().error.kind, 'client_timeout');
    assert.equal(polls(calls).length, 3);
    assert.deepEqual(deletes(calls).map((call) => call.url), [url(RUN_A)]);
});

test('a 200 without a usable result is an error, never a hang', async () => {
    const { client, clock, last } = setup(() => ({ status: 200, headers: {}, body: undefined }));
    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 10);

    assert.equal(last().phase, 'failed');
});

test('dispose stops polling, cancels the run and never touches the state again', async () => {
    const { client, clock, calls, states } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) }) : json(200, { status: 'running', run_id: RUN_A })));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + POLL_FAST_MS * 2 + 5);
    const seen = states.length;

    client.dispose();
    await clock.advance(30000);

    assert.equal(deletes(calls).length, 1);
    assert.equal(deletes(calls)[0].url, url(RUN_A));
    assert.equal(states.length, seen);
    assert.equal(polls(calls).length, 2);
    client.schedule({});
    await clock.advance(5000);
    assert.equal(posts(calls).length, 1);
});

test('reset goes idle, forgets the result and cancels a run in flight', async () => {
    const { client, clock, calls, last } = setup((call) => (call.method === 'POST' ? json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) }) : json(200, { status: 'running', run_id: RUN_A })));

    client.schedule({});
    await clock.advance(DEBOUNCE_MS + 5);
    client.reset();
    await clock.advance(5000);

    assert.equal(last().phase, 'idle');
    assert.equal(last().result, null);
    assert.equal(deletes(calls).length, 1);
    assert.equal(polls(calls).length, 0);
});

test('keeps the previous result while loading and drops it on failure', async () => {
    let n = 0;
    const { client, clock, last } = setup(() => (++n === 1 ? json(200, { status: 'done', run_id: null, result: RESULT }) : json(500, ERROR)));

    client.schedule({ events: ['a'] });
    await clock.advance(DEBOUNCE_MS + 1);
    assert.deepEqual(last().result, RESULT);

    client.schedule({ events: ['b'] });
    assert.equal(last().phase, 'loading');
    assert.deepEqual(last().result, RESULT, 'the old result stays while the new one loads');

    await clock.advance(MIN_POST_GAP_MS + DEBOUNCE_MS + 5);
    assert.equal(last().phase, 'failed');
    assert.equal(last().result, null);
    assert.equal(last().error.message, ERROR.error.message);
});

test('helpers: XSRF cookie decoding, Retry-After parsing and error reduction', () => {
    assert.equal(readXsrfToken('a=1; XSRF-TOKEN=eyJ%3D%3D; b=2'), 'eyJ==');
    assert.equal(readXsrfToken('a=1'), '');
    assert.equal(readXsrfToken('XSRF-TOKEN=%E0%A4%A'), '');

    assert.equal(retryAfterMs('2'), 2000);
    assert.equal(retryAfterMs('99999'), 30000);
    assert.equal(retryAfterMs('Wed, 21 Oct 2026 07:28:00 GMT'), null);
    assert.equal(retryAfterMs(null), null);
    assert.equal(retryAfterMs('-1'), null);

    assert.equal(errorOf(null), null);
    assert.equal(errorOf({ error: { message: '' } }), null);
    assert.deepEqual(errorOf({ error: { kind: 'busy', message: 'm', retryable: true, retry_after_seconds: 3, extra: '<script>' } }), { kind: 'busy', message: 'm', retryable: true, retry_after_seconds: 3 });
    assert.equal(errorOf({ error: { kind: 'x', message: 'y'.repeat(1000) } }).message.length, 300);
});

test('hold cancels what runs and keeps the last result (an unfinished edit does not wipe the chart)', async () => {
    let n = 0;
    const { client, clock, calls, last } = setup((call) => (call.method === 'POST' ? (++n === 1 ? json(200, { status: 'done', run_id: null, result: RESULT }) : json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) })) : json(200, { status: 'running', run_id: RUN_A })));

    client.schedule({ events: ['a'] });
    await clock.advance(DEBOUNCE_MS + 1);
    assert.deepEqual(last().result, RESULT);

    client.schedule({ events: ['b'] });
    await clock.advance(MIN_POST_GAP_MS + DEBOUNCE_MS + POLL_FAST_MS + 5);
    client.hold();
    await clock.advance(10000);

    assert.equal(last().phase, 'held');
    assert.deepEqual(last().result, RESULT);
    assert.deepEqual(deletes(calls).map((call) => call.url), [url(RUN_A)]);
    assert.equal(posts(calls).length, 2);
});

// ---------------------------------------------------------------- run() and cancel(): a page with a Run button

test('run() posts at once without the debounce, and the state says queued, then running, then done', async () => {
    let polled = 0;
    const { client, clock, calls, states, last } = setup((call) => {
        if (call.method === 'POST') {
            return json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) });
        }
        polled++;

        return polled === 1 ? json(200, { status: 'queued', run_id: RUN_A }) : polled === 2 ? json(200, { status: 'running', run_id: RUN_A }) : json(200, { status: 'done', run_id: RUN_A, result: RESULT });
    });

    client.run({ steps: [] });
    await flush();

    assert.equal(posts(calls).length, 1, 'no debounce');
    assert.deepEqual(posts(calls)[0].body, { steps: [] });
    assert.equal(last().phase, 'loading');
    assert.equal(last().stage, null);

    await clock.advance(POLL_FAST_MS);
    assert.equal(last().stage, 'queued');
    await clock.advance(POLL_FAST_MS);
    assert.equal(last().stage, 'running');
    await clock.advance(POLL_FAST_MS);
    assert.equal(last().phase, 'done');
    assert.equal(last().stage, null, 'the stage belongs to a run in progress');
    assert.deepEqual(states.map((state) => state.phase), ['loading', 'loading', 'loading', 'done']);
});

test('cancel() deletes the run, becomes cancelled and never polls it again', async () => {
    const { client, clock, calls, last } = setup((call) => {
        if (call.method === 'POST') {
            return json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) });
        }

        return call.method === 'DELETE' ? json(204, undefined) : json(200, { status: 'running', run_id: RUN_A });
    });

    client.run({});
    await clock.advance(POLL_FAST_MS);
    assert.equal(last().stage, 'running');

    client.cancel();
    await flush();

    assert.equal(last().phase, 'cancelled');
    assert.equal(last().stage, null);
    assert.deepEqual(deletes(calls).map((call) => call.url), [url(RUN_A)]);
    const polled = polls(calls).length;
    await clock.advance(30000);
    assert.equal(polls(calls).length, polled, 'polling stopped');
    assert.equal(last().phase, 'cancelled');
});

test('cancel() while the POST is still in flight cancels the run that POST creates', async () => {
    let release;
    const gate = new Promise((resolve) => (release = resolve));
    const { client, clock, calls, last } = setup(async (call) => {
        if (call.method === 'POST') {
            await gate;

            return json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) });
        }

        return json(204, undefined);
    });

    client.run({});
    await flush();
    client.cancel();
    assert.equal(last().phase, 'cancelled');

    release();
    await flush();

    assert.deepEqual(deletes(calls).map((call) => call.url), [url(RUN_A)]);
    await clock.advance(10000);
    assert.equal(polls(calls).length, 0);
    assert.equal(last().phase, 'cancelled', 'the late answer does not change the state');
});

test('cancel() with nothing running changes nothing, and a finished result stays done', async () => {
    const { client, states, last } = setup(() => json(200, { status: 'done', run_id: null, result: RESULT }));

    client.cancel();
    assert.equal(states.length, 0);

    client.run({});
    await flush();
    assert.equal(last().phase, 'done');
    const count = states.length;

    client.cancel();
    assert.equal(states.length, count);
    assert.equal(last().phase, 'done');
});

test('run() again after a cancel starts a fresh run, and a run waiting for the POST gap can be cancelled', async () => {
    const { client, clock, calls, last } = setup(() => json(200, { status: 'done', run_id: null, result: RESULT }));

    client.run({ n: 1 });
    await flush();
    client.run({ n: 2 });
    await flush();
    assert.equal(posts(calls).length, 1, 'the second POST waits for the gap');

    client.cancel();
    await clock.advance(MIN_POST_GAP_MS + 10);
    assert.equal(posts(calls).length, 1, 'a cancelled run never posts');
    assert.equal(last().phase, 'cancelled');

    client.run({ n: 3 });
    await clock.advance(MIN_POST_GAP_MS + 10);
    assert.deepEqual(posts(calls).map((call) => call.body), [{ n: 1 }, { n: 3 }]);
    assert.equal(last().phase, 'done');
});

test('a stored result answered at once carries its computed_at and source; every other result carries none', async () => {
    const { client, clock, last } = setup((call) => (call.method === 'POST' ? json(200, { status: 'done', run_id: null, result: RESULT, source: 'dashboard_cache', computed_at: '2026-10-30T12:00:00+00:00' }) : json(404, {})));

    client.run({ fresh: false });
    await clock.advance(10);
    assert.equal(last().phase, 'done');
    assert.equal(last().computedAt, '2026-10-30T12:00:00+00:00');
    assert.equal(last().source, 'dashboard_cache');

    client.reset();
    assert.equal(last().computedAt, null);
    assert.equal(last().source, null);
});

test('a polled result and a plain 200 have no stamp', async () => {
    const { client, clock, last } = setup((call) => {
        if (call.method === 'POST') {
            return json(202, { status: 'queued', run_id: RUN_A, poll_url: url(RUN_A) });
        }

        return json(200, { status: 'done', run_id: RUN_A, result: RESULT });
    });

    client.run({});
    await clock.advance(5000);
    assert.equal(last().phase, 'done');
    assert.equal(last().computedAt, null);
    assert.equal(last().source, null);
});
