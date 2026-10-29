import assert from 'node:assert/strict';
import test from 'node:test';

import { capturedEntry, describe, effectiveApiHost, isSdkRequest, maskKey, MAX_LOG_ENTRIES, pathOf, pushEntry, requestEntry, statusTone } from '../../resources/js/lib/demoLog.js';

const KEY = `phc_${'a'.repeat(39)}WXYZ`;

test('masks the key down to its prefix and last four characters, and never invents one', () => {
    assert.equal(maskKey(KEY), 'phc_…WXYZ');
    assert.equal(maskKey(''), '');
    assert.equal(maskKey(undefined), '');
    assert.equal(maskKey('short'), '');
});

test('prefers the origin the page was opened from over the configured APP_URL', () => {
    assert.equal(effectiveApiHost('http://172.18.0.5:8000', 'https://analytics.example'), 'http://172.18.0.5:8000');
    assert.equal(effectiveApiHost('http://[::1]:8000', 'x'), 'http://[::1]:8000');
    // an origin never has a path: anything else is not one
    assert.equal(effectiveApiHost('https://a.example/', 'x'), 'x');
    assert.equal(effectiveApiHost('null', 'https://analytics.example/'), 'https://analytics.example');
    assert.equal(effectiveApiHost('file://', 'http://localhost:8000'), 'http://localhost:8000');
    assert.equal(effectiveApiHost(undefined, undefined), '');
});

test('describes values as JSON with the key masked and long output cut', () => {
    const text = describe({ token: KEY, nested: { token: KEY } }, KEY);
    assert.ok(!text.includes(KEY));
    assert.equal(text.split('phc_…WXYZ').length - 1, 2);

    const long = describe({ blob: 'x'.repeat(5000) });
    assert.ok(long.length < 2200);
    assert.match(long, /more characters\)$/);

    const cyclic = {};
    cyclic.self = cyclic;
    assert.equal(describe(cyclic), '[unserializable]');
    assert.equal(describe(undefined), 'undefined');
});

test('summarises a captured event with its name and distinct id, without the key', () => {
    const entry = capturedEntry({ event: 'demo_event', properties: { distinct_id: 'u1', token: KEY } }, KEY, 1000);

    assert.equal(entry.kind, 'captured');
    assert.equal(entry.text, 'demo_event as u1');
    assert.ok(!entry.detail.includes(KEY));
    assert.equal(capturedEntry({}, KEY, 1).text, '(no name)');
    assert.equal(capturedEntry(null, KEY, 1).text, '(no name)');
});

test('classifies response statuses', () => {
    assert.equal(statusTone(200), 'ok');
    assert.equal(statusTone(204), 'ok');
    assert.equal(statusTone(0), 'error');
    assert.equal(statusTone(401), 'error');
    assert.equal(statusTone(503), 'error');
    assert.equal(statusTone(304), 'info');
});

test('recognises SDK requests to this server only, by path', () => {
    const host = 'http://10.0.0.5:8000';

    for (const path of ['/e/?ip=0', '/i/v0/e/', '/batch/', '/flags/?v=2', '/decide/', '/array/phc_x/config.js']) {
        assert.equal(isSdkRequest(`${host}${path}`, host, host), true, path);
    }

    for (const url of [`${host}/build/assets/app.js`, `${host}/projects/1/demo`, `${host}/events`, 'https://other.example/e/', `${host}/array`]) {
        assert.equal(isSdkRequest(url, host, host), url.endsWith('/array'), url);
    }

    assert.equal(isSdkRequest('::bad::', host, host), false);
});

test('shows the path and query of a URL, the raw text when it cannot be parsed', () => {
    assert.equal(pathOf('http://10.0.0.5:8000/e/?ip=0&_=1', 'http://10.0.0.5:8000'), '/e/?ip=0&_=1');
    assert.equal(pathOf('/flags/?v=2', 'http://x'), '/flags/?v=2');
    assert.equal(pathOf('http://[bad', 'http://x'), 'http://[bad');
});

test('builds the entry of a finished request, a beacon and an unknown status', () => {
    const base = 'http://h:8000';
    const ok = requestEntry({ name: `${base}/e/?ip=0`, initiatorType: 'fetch', responseStatus: 200, duration: 12.4, transferSize: 300 }, base, 5, KEY);
    assert.deepEqual([ok.kind, ok.tone, ok.text], ['request', 'ok', '/e/?ip=0 HTTP 200']);
    assert.match(ok.detail, /"durationMs": 12/);

    const config = requestEntry({ name: `${base}/array/${KEY}/config.js`, initiatorType: 'script', responseStatus: 200, duration: 3 }, base, 5, KEY);
    assert.equal(config.text, '/array/phc_\u2026WXYZ/config.js HTTP 200');
    assert.ok(!config.detail.includes(KEY));

    const beacon = requestEntry({ name: `${base}/e/`, initiatorType: 'beacon', responseStatus: 0, duration: 1 }, base, 5);
    assert.deepEqual([beacon.kind, beacon.tone, beacon.text], ['beacon', 'error', '/e/ status unknown']);
});

test('keeps the newest entries, numbers them and leaves the given list alone', () => {
    let list = [];
    for (let i = 0; i < MAX_LOG_ENTRIES + 5; i++) {
        const before = list;
        list = pushEntry(list, { text: `e${i}` });
        assert.ok(before.length <= MAX_LOG_ENTRIES);
    }

    assert.equal(list.length, MAX_LOG_ENTRIES);
    assert.equal(list[0].text, 'e5');
    assert.equal(list.at(-1).id, MAX_LOG_ENTRIES + 5);

    const frozen = Object.freeze([]);
    assert.equal(pushEntry(frozen, { text: 'a' }, 3)[0].id, 1);
    assert.equal(pushEntry([{ id: 1 }, { id: 2 }, { id: 3 }], { text: 'd' }, 3).map((e) => e.id).join(), '2,3,4');
});
