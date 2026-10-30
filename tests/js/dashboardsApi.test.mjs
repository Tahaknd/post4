import assert from 'node:assert/strict';
import test from 'node:test';

import { addTile, createSavedInsight, deleteDashboard, firstMessages, getSavedInsight, isUuid, listAll, removeTile, reorderTiles, savedInsightRunUrl, updateSavedInsight } from '../../resources/js/lib/dashboardsApi.js';
import { fakeFetch, json } from './helpers.mjs';

const ID = '0197a3b2-1c4d-7e5f-8a6b-7c8d9e0f1a2b';
const TILE = '0197a3b2-1c4d-7e5f-8a6b-aaaaaaaaaaaa';
const INSIGHTS = '/api/projects/7/saved-insights';
const DASHBOARDS = '/api/projects/7/dashboards';

const api = (routes) => {
    const { fetch, calls } = fakeFetch(routes);

    return { options: { fetch }, calls };
};

test('a 201 gives the data; the body is JSON with the XSRF-capable headers', async () => {
    const { options, calls } = api(() => json(201, { data: { id: ID, name: 'N' } }));
    const answer = await createSavedInsight(INSIGHTS, { name: 'N', type: 'trends', query: {} }, options);

    assert.equal(answer.ok, true);
    assert.deepEqual(answer.data, { id: ID, name: 'N' });
    assert.equal(calls[0].method, 'POST');
    assert.equal(calls[0].headers['Content-Type'], 'application/json');
    assert.equal(calls[0].headers.Accept, 'application/json');
    assert.deepEqual(calls[0].body, { name: 'N', type: 'trends', query: {} });
});

test('a 204 is ok with no data; item URLs are the collection plus a checked uuid', async () => {
    const { options, calls } = api(() => ({ status: 204, body: undefined, headers: {} }));
    const answer = await deleteDashboard(DASHBOARDS, ID, options);

    assert.deepEqual([answer.ok, answer.status, answer.data], [true, 204, null]);
    assert.equal(calls[0].url, `${DASHBOARDS}/${ID}`);
    assert.equal(calls[0].method, 'DELETE');
    assert.equal(savedInsightRunUrl(INSIGHTS, ID), `${INSIGHTS}/${ID}/run`);
    assert.equal(savedInsightRunUrl(INSIGHTS, '../x'), null);
    assert.equal(isUuid(ID), true);
    assert.equal(isUuid('x'), false);
});

test('a bad id is a not_found without any request', async () => {
    const { options, calls } = api(() => json(200, {}));

    for (const answer of [await getSavedInsight(INSIGHTS, '../../x', options), await updateSavedInsight(INSIGHTS, 'nope', {}, options), await removeTile(DASHBOARDS, ID, 'nope', options)]) {
        assert.deepEqual([answer.ok, answer.kind], [false, 'not_found']);
    }
    assert.equal(calls.length, 0);
});

test('tile calls use the dashboard url, the right verbs and bodies', async () => {
    const { options, calls } = api(() => json(200, { data: {} }));
    await addTile(DASHBOARDS, ID, TILE, options);
    await reorderTiles(DASHBOARDS, ID, [TILE, ID], options);
    await removeTile(DASHBOARDS, ID, TILE, options);

    assert.deepEqual(calls.map((call) => [call.method, call.url]), [['POST', `${DASHBOARDS}/${ID}/tiles`], ['PUT', `${DASHBOARDS}/${ID}/tiles/order`], ['DELETE', `${DASHBOARDS}/${ID}/tiles/${TILE}`]]);
    assert.deepEqual(calls[0].body, { saved_insight_id: TILE });
    assert.deepEqual(calls[1].body, { tiles: [TILE, ID] });
});

test('a 422 keeps the first message of every field', async () => {
    const { options } = api(() => json(422, { message: 'The name is required.', error: { kind: 'validation', message: 'The name is required.', retryable: false, retry_after_seconds: null }, errors: { name: ['A name of 1 to 120 characters is required.', 'x'], 'query.events': ['Provide events.'] } }));
    const answer = await createSavedInsight(INSIGHTS, {}, options);

    assert.deepEqual([answer.ok, answer.kind, answer.status], [false, 'validation', 422]);
    assert.deepEqual(answer.errors, { name: 'A name of 1 to 120 characters is required.', 'query.events': 'Provide events.' });
    assert.deepEqual(firstMessages({ a: [], b: ['', 'x'], c: 'm' }), { c: 'm' });
});

test('the failures are told apart: not found, signed out, expired, busy with Retry-After, server, network', async () => {
    const cases = [
        [json(404, { error: { kind: 'not_found', message: 'Not found.' } }), 'not_found'],
        [json(401, {}), 'unauthenticated'],
        [json(419, {}), 'page_expired'],
        [json(429, { error: { kind: 'rate_limited', message: 'Too many requests. Try again shortly.', retryable: true, retry_after_seconds: 12 } }, { 'Retry-After': '12' }), 'busy'],
        [json(503, { error: { kind: 'unavailable', message: 'The service is temporarily unavailable. Try again shortly.', retryable: true, retry_after_seconds: null } }), 'busy'],
        [json(500, {}), 'server'],
        [new Error('offline'), 'network'],
    ];

    for (const [answer, kind] of cases) {
        const { options } = api(() => answer);
        const result = await getSavedInsight(INSIGHTS, ID, options);
        assert.equal(result.kind, kind);
        assert.equal(result.ok, false);
        assert.ok(result.message.length > 0);
    }

    const { options } = api(() => json(429, { error: { kind: 'rate_limited', message: 'Slow down.', retryable: true, retry_after_seconds: 12 } }, { 'Retry-After': '12' }));
    const busy = await getSavedInsight(INSIGHTS, ID, options);
    assert.equal(busy.retryAfter, 12);
    assert.equal(busy.message, 'Slow down.');
});

test('listAll reads every page at 100 per page and stops at the last one', async () => {
    const { options, calls } = api((call) => {
        const page = Number(new URL(call.url, 'http://x').searchParams.get('page'));

        return json(200, { data: Array.from({ length: page < 3 ? 100 : 20 }, (_, i) => ({ id: `${page}-${i}` })), meta: { page, per_page: 100, total: 220, last_page: 3 } });
    });
    const answer = await listAll(DASHBOARDS, options);

    assert.equal(answer.ok, true);
    assert.equal(answer.data.length, 220);
    assert.equal(answer.meta.total, 220);
    assert.deepEqual(calls.map((call) => call.url), [`${DASHBOARDS}?page=1&per_page=100`, `${DASHBOARDS}?page=2&per_page=100`, `${DASHBOARDS}?page=3&per_page=100`]);
});

test('listAll hands back the failure of a page', async () => {
    const { options } = api(() => json(429, { error: { kind: 'rate_limited', message: 'Slow down.', retryable: true, retry_after_seconds: 3 } }));
    const answer = await listAll(INSIGHTS, options);

    assert.deepEqual([answer.ok, answer.kind], [false, 'busy']);
});
