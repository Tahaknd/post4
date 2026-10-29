import assert from 'node:assert/strict';
import test from 'node:test';

import { isSafeTarget } from '../../playwright.config.js';

test('lets the e2e tests run against this machine, a LAN and reserved test names only', () => {
    for (const host of ['localhost', 'LOCALHOST', 'app.localhost', 'post4.test', '127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.254', '192.168.1.20', '169.254.1.1', '[::1]', 'fd12:3456::1']) {
        assert.equal(isSafeTarget(host), true, host);
    }

    for (const host of ['example.com', 'analytics.example.org', '8.8.8.8', '172.32.0.1', '172.15.0.1', '192.169.0.1', '11.0.0.1', 'localhost.example.com', 'test', 'notlocalhost.com', '2001:db8::1']) {
        assert.equal(isSafeTarget(host), false, host);
    }
});
