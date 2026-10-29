import { gunzipSync } from 'node:zlib';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// The real posthog-js in a real browser, against a real stack: sign in, create a throwaway project, open the demo page
// ("Send test events") and let the SDK talk to the server. Proves what only a browser can: the remote config the SDK
// loads, the gzip / base64 / sendBeacon bodies it posts, CORS-free same-origin traffic, no cookies from the SDK
// endpoints, a clean console, and that the events arrive (the app's own events API, polled with a deadline).
// Credentials come from E2E_EMAIL / E2E_PASSWORD (a throwaway administrator). The project is deleted at the end.

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const marker = `demo${Date.now().toString(36)}`;
const projectName = `E2E ${marker}`;
// A second project of the same user: its name must never end up in an event of the first.
const otherName = `Foreign ${marker}`;

test.describe.configure({ mode: 'serial' });

let context;
let page;
let projectId;
let otherProjectId;
let apiKey;
let baseOrigin;

const problems = [];
const warnings = [];
// Every request of the context, in order: { method, url, type, postData, status, headers } (status/headers when answered).
const traffic = [];

const sdkPath = /^\/(e|i|batch|flags|decide|array|s|static|engage)(\/|$)/;
const demoPath = () => `/projects/${projectId}/demo`;
const sdkRequests = (path) => traffic.filter((entry) => new URL(entry.url).pathname.startsWith(path));
const since = (mark) => traffic.slice(mark);

/**
 * Waits long enough that a request which WOULD be sent has been: the SDK flushes its queue every 500 ms (demoSdk.js),
 * so this is the one place a fixed wait is the point of the test (proving that something is NOT sent).
 */
const quiet = () => page.waitForTimeout(1500);

/**
 * The POST requests of the page under test since `mark`. A sendBeacon ping is left out: the Playwright event for the
 * beacon a PREVIOUS page sent while it unloaded can arrive after the next page has loaded, and what a beacon sends is
 * checked through the beacon log (leaveAndReadBeacons) instead.
 */
const posts = (mark) => since(mark).filter((entry) => entry.method === 'POST' && entry.type !== 'ping');

/** The events of the POST /e/ requests in `entries`, whatever the body encoding (gzip, JSON, base64 form). */
function sentEvents(entries) {
    return entries
        .filter((entry) => entry.method === 'POST' && new URL(entry.url).pathname === '/e/' && entry.postData)
        .flatMap((entry) => {
            let text = entry.postData.subarray(0, 2).toString('hex') === '1f8b' ? gunzipSync(entry.postData).toString() : entry.postData.toString();
            if (text.startsWith('data=')) {
                text = Buffer.from(decodeURIComponent(text.slice(5)), 'base64').toString();
            }
            const body = JSON.parse(text);

            return Array.isArray(body) ? body : (body.batch ?? [body]);
        });
}

test.beforeAll(async ({ browser }) => {
    if (!email || !password) {
        throw new Error('Set E2E_EMAIL and E2E_PASSWORD to a throwaway administrator (see scripts/e2e.sh).');
    }

    context = await browser.newContext();
    // posthog-js drops an event when it takes the browser for a bot: `navigator.webdriver` is set, the user agent or one of
    // the client hint brands says Headless. A real visitor has none of that, so the test browser must not either. The
    // user agent is pinned at document creation because Playwright's emulation is already gone while a page unloads (the
    // `$pageleave` of a real navigation would otherwise be dropped as a bot's). The page under test stays free of any
    // test-only option.
    await context.addInitScript(() => {
        const userAgent = navigator.userAgent;
        Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false });
        Object.defineProperty(Navigator.prototype, 'userAgent', { get: () => userAgent });
        Object.defineProperty(Navigator.prototype, 'userAgentData', { get: () => undefined });

        // What a page sends with sendBeacon while it unloads: the request is not reliably reported to the test, so the
        // calls are written to localStorage (it outlives the page) and read back from the next page of the same origin.
        const original = navigator.sendBeacon.bind(navigator);
        Navigator.prototype.sendBeacon = function (url, data) {
            try {
                const list = JSON.parse(localStorage.getItem('__e2e_beacons') || '[]');
                list.push({ url: String(url), type: data && data.type ? data.type : typeof data, size: (data && (data.size ?? data.length)) || 0 });
                localStorage.setItem('__e2e_beacons', JSON.stringify(list));
            } catch {
                // the test would notice the missing record
            }

            return original(url, data);
        };
    });
    page = await context.newPage();

    page.on('console', (message) => {
        // Chrome logs a report-only Content-Security-Policy violation as an INFO message ("... violates the following Content
        // Security Policy directive ... The policy is report-only"), not as an error: match the text, whatever the level.
        if (/Content Security Policy|\[Report Only\]/i.test(message.text())) {
            problems.push(`csp violation: ${message.text()}`);
        } else if (message.type() === 'error') {
            problems.push(`console error: ${message.text()}`);
        } else if (message.type() === 'warning') {
            warnings.push(`console warning: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));

    context.on('request', (request) => traffic.push({ method: request.method(), url: request.url(), type: request.resourceType(), postData: request.postDataBuffer(), headers: request.headers() }));
    // A request the page aborted itself is not a failure; anything else that never got an answer is.
    context.on('requestfailed', (request) => request.failure()?.errorText === 'net::ERR_ABORTED' || problems.push(`request failed: ${request.method()} ${request.url()} ${request.failure()?.errorText}`));
    context.on('response', (response) => {
        const entry = [...traffic].reverse().find((item) => item.url === response.url() && item.status === undefined);
        if (entry) {
            entry.status = response.status();
            entry.responseHeaders = response.headers();
        }
        if (response.status() >= 400 && !tolerated(response)) {
            problems.push(`http ${response.status()}: ${response.request().method()} ${response.url()}`);
        }
    });

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/$/);
    baseOrigin = new URL(page.url()).origin;

    await page.goto('/projects/create');
    await page.getByLabel('Name').fill(projectName);
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/\d+$/);
    projectId = Number(page.url().split('/').pop());

    await page.getByRole('button', { name: 'Reveal key' }).click();
    apiKey = (await page.getByTestId('api-key').textContent()).trim();
    expect(apiKey).toMatch(/^phc_/);

    await page.goto('/projects/create');
    await page.getByLabel('Name').fill(otherName);
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/\d+$/);
    otherProjectId = Number(page.url().split('/').pop());
});

// A response >= 400 a test provokes on purpose.
let tolerating = [];
function tolerated(response) {
    return tolerating.some((fragment) => response.url().includes(fragment));
}

test.afterAll(async () => {
    for (const [id, name] of [[projectId, projectName], [otherProjectId, otherName]]) {
        if (id !== undefined) {
            await page.goto(`/projects/${id}`);
            await page.getByRole('button', { name: 'Delete project…' }).click();
            await page.getByRole('dialog').getByLabel('Project name').fill(name);
            await page.getByRole('dialog').getByRole('button', { name: 'Delete project' }).click();
            await expect(page).toHaveURL(/\/projects$/);
        }
    }
    await context.close();
});

/** Opens the demo page (a full load, or through the project page's link) and waits until the SDK reports ready. */
async function openDemo({ query = '', viaLink = false } = {}) {
    if (viaLink) {
        await page.goto(`/projects/${projectId}`);
        await page.getByRole('link', { name: 'Send test events' }).click();
        await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/demo$`));
    } else {
        await page.goto(`${demoPath()}${query}`);
    }
    await expect(page.getByTestId('demo-status')).toContainText('Ready, posthog-js');
}

/** Distinct search texts for an event name: the answer of the events API is cached per text, so a poll must not repeat one. */
function* searches(name) {
    // The search is case-insensitive but the cache key is the text, so the upper-case spelling doubles the supply.
    for (const spell of [(text) => text, (text) => text.toUpperCase()]) {
        for (let length = name.length; length >= 3; length--) {
            for (let start = 0; start + length <= name.length; start++) {
                yield spell(name.slice(start, start + length));
            }
        }
    }
}

const polled = new Map();

/** Waits (deadline) until the events API of the app lists `name` with at least `min` events. */
async function waitForEvent(name, min = 1, deadlineMs = 90_000) {
    const unused = polled.get(name) ?? searches(name);
    polled.set(name, unused);
    const deadline = Date.now() + deadlineMs;
    let last = 0;

    while (Date.now() < deadline) {
        const next = unused.next();
        if (next.done) {
            break;
        }
        const answer = await page.request.get(`/api/projects/${projectId}/insights/events?q=${encodeURIComponent(next.value)}`);
        if (answer.ok()) {
            last = (await answer.json()).events.find((item) => item.name === name)?.count ?? 0;
            if (last >= min) {
                return last;
            }
        }
        await page.waitForTimeout(2000);
    }

    throw new Error(`${name} did not reach ${min} in the events API (last count ${last})`);
}

/** How many events of this name the events API lists right now (0 when none): one query, never a repeated search text. */
async function countOf(name) {
    const unused = polled.get(name) ?? searches(name);
    polled.set(name, unused);
    const next = unused.next();
    const answer = next.done ? null : await page.request.get(`/api/projects/${projectId}/insights/events?q=${encodeURIComponent(next.value)}`);

    return answer?.ok() ? ((await answer.json()).events.find((item) => item.name === name)?.count ?? 0) : 0;
}

async function seriousViolations() {
    const results = await new AxeBuilder({ page }).analyze();

    return results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact)).map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`);
}

test('starts the real SDK from the project page and talks to this server only', async () => {
    await openDemo({ viaLink: true });

    // Secure only on https or localhost; a plain-http LAN or `.test` address is not, and the SDK must work there too.
    const { hostname, protocol } = new URL(page.url());
    expect(await page.evaluate(() => window.isSecureContext)).toBe(protocol === 'https:' || ['localhost', '127.0.0.1', '[::1]'].includes(hostname));

    // The page that starts the SDK is the demo page; its host is the origin the browser opened.
    await expect(page.getByTestId('demo-host')).toHaveText(baseOrigin);
    await expect(page.getByTestId('demo-key')).toHaveText(/^phc_…\w{4}$/);
    expect(await page.content()).toContain('phc_…');

    // The SDK asked for its remote config script exactly once, got 200 JavaScript, and nothing it did not need.
    const config = sdkRequests(`/array/${apiKey}/config.js`);
    expect(config).toHaveLength(1);
    // "Ready" means the SDK was initialised, not that its config request has come back.
    await expect.poll(() => config[0].status).toBe(200);
    await page.waitForLoadState('networkidle');
    expect(config[0].responseHeaders['content-type']).toBe('application/javascript; charset=utf-8');
    expect(sdkRequests('/flags')).toHaveLength(0);
    for (const path of ['/s/', '/static/', '/engage', '/decide', '/i/']) {
        expect(sdkRequests(path), path).toHaveLength(0);
    }
    expect(sdkRequests('/array/').map((entry) => new URL(entry.url).pathname)).toEqual([`/array/${apiKey}/config.js`]);

    // Everything the context requested stayed on this origin (no CDN, no third party).
    const external = traffic.map((entry) => entry.url).filter((url) => !/^(data|blob|about):/.test(url) && new URL(url).origin !== baseOrigin);
    expect(external).toEqual([]);

    // The log shows the request with its status.
    await expect(page.getByTestId('demo-log')).toContainText('/array/phc_');
    await expect(page.getByTestId('demo-log')).toContainText('config.js HTTP 200');
});

test('captures a custom event: gzip body, 200, no cookie, and it reaches the events API', async () => {
    const name = `${marker}_custom`;
    await page.getByLabel('Event name').fill(name);
    await page.getByLabel('Property name (optional)').fill('plan');
    await page.getByLabel('Property value').fill('pro');

    const mark = traffic.length;
    const [response] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/e/' && r.request().method() === 'POST'),
        page.getByRole('button', { name: 'Capture custom event' }).click(),
    ]);

    expect(response.status()).toBe(200);
    expect(await response.json()).toEqual({ status: 1 });
    const url = new URL(response.url());
    // posthog-js 1.438 marks a gzip body by its content (the gzip magic bytes), not by a `compression=gzip-js` query parameter.
    expect(url.searchParams.has('compression')).toBe(false);
    const request = response.request();
    expect(request.headers()['content-type']).toContain('text/plain');
    expect([...(request.postDataBuffer() ?? [])].slice(0, 2)).toEqual([0x1f, 0x8b]);

    // The page's own log: the event the SDK was about to send, then the request with its status.
    await expect(page.getByTestId('demo-log')).toContainText(`${name} as `);
    await expect(page.getByTestId('demo-log')).toContainText('/e/ HTTP 200');

    // Not one SDK answer sets a cookie.
    for (const entry of since(mark).filter((item) => sdkPath.test(new URL(item.url).pathname))) {
        expect(entry.responseHeaders?.['set-cookie'], entry.url).toBeUndefined();
    }

    expect(await waitForEvent(name)).toBeGreaterThanOrEqual(1);
});

test('the SDK calls /flags when the remote config says there are flags, and the stub answers it', async () => {
    // Make the server's config claim flags exist: the one way to see the real SDK's /flags request without a Milestone 7.
    await page.route('**/array/*/config.js', async (route) => {
        const answer = await route.fetch();
        await route.fulfill({ response: answer, body: (await answer.text()).replace('"hasFeatureFlags":false', '"hasFeatureFlags":true') });
    });

    try {
        const mark = traffic.length;
        const [response] = await Promise.all([
            page.waitForResponse((r) => new URL(r.url()).pathname === '/flags/'),
            page.reload(),
        ]);

        expect(response.request().method()).toBe('POST');
        expect(response.status()).toBe(200);
        const query = new URL(response.url()).searchParams;
        expect(query.get('v')).toBe('2');
        expect(response.request().headers()['content-type']).toContain('text/plain');
        expect([...(response.request().postDataBuffer() ?? [])].slice(0, 2)).toEqual([0x1f, 0x8b]);
        const body = await response.json();
        expect(body.flags).toEqual({});
        expect(body.errorsWhileComputingFlags).toBe(false);
        expect(body.quotaLimited).toEqual([]);
        expect(body.requestId).toMatch(/^[0-9a-f-]{36}$/);
        expect(response.headers()['cache-control']).toContain('no-store');
        expect(response.headers()['set-cookie']).toBeUndefined();
        expect(response.headers()['access-control-allow-origin']).toBe('*');
        await expect(page.getByTestId('demo-status')).toContainText('Ready, posthog-js');
        expect(since(mark).filter((entry) => new URL(entry.url).pathname === '/flags/')).toHaveLength(1);
    } finally {
        await page.unroute('**/array/*/config.js');
    }
});

test('identify sends $identify and shows the new distinct id', async () => {
    await openDemo();
    const before = await page.getByTestId('demo-distinct-id').textContent();
    const id = `${marker}-user`;

    await page.getByLabel('Identify as').fill(id);
    await page.getByRole('button', { name: 'Identify', exact: true }).click();

    await expect(page.getByTestId('demo-distinct-id')).toHaveText(id);
    expect(before).not.toBe(id);
    await waitForEvent('$identify');

    // Reset forgets the visitor: a new anonymous id.
    await page.getByRole('button', { name: 'Reset' }).click();
    await expect(page.getByTestId('demo-distinct-id')).not.toHaveText(id);
});

test('autocapture sends $autocapture for the target only, and stays silent when switched off', async () => {
    await openDemo();

    await page.getByRole('button', { name: 'Autocapture target' }).click();
    await waitForEvent('$autocapture');

    // The controls of the bench are not autocaptured: a click on one sends no request.
    await page.getByRole('button', { name: 'Clear activity log' }).click();
    await quiet();
    const mark = traffic.length;
    await page.getByRole('button', { name: 'Clear activity log' }).click();
    await quiet();
    expect(posts(mark)).toEqual([]);

    // Switched off: the SDK restarts, and the target sends nothing.
    await page.getByLabel('Autocapture clicks and form changes').uncheck();
    await expect(page.getByTestId('demo-log')).toContainText('autocapture off');
    await expect(page.getByTestId('demo-status')).toContainText('Ready, posthog-js');
    const before = traffic.length;
    await page.getByRole('button', { name: 'Autocapture target' }).click();
    await quiet();
    expect(posts(before)).toEqual([]);
});

test('autocapture records the demo target only: clicks on the app chrome send nothing, and no other project name leaves the page', async () => {
    await openDemo();
    await page.evaluate(() => localStorage.removeItem('__e2e_beacons'));
    const mark = traffic.length;

    // Positive control: the target is recorded, as an element of this page.
    const [response] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/e/' && r.request().method() === 'POST'),
        page.getByRole('button', { name: 'Autocapture target' }).click(),
    ]);
    expect(response.status()).toBe(200);
    const recorded = sentEvents(posts(mark));
    expect(recorded.map((event) => event.event)).toEqual(['$autocapture']);
    expect(JSON.stringify(recorded)).toContain('demo-autocapture-target');

    // The app's own chrome: the project picker (three quick clicks, the stuff a rage click is made of), its panel, the
    // header links and the page's own links. None of it may become an event.
    const picker = page.getByRole('button', { name: /^Project:/ });
    const afterTarget = traffic.length;
    for (let i = 0; i < 4; i++) {
        await picker.click({ delay: 20 });
    }
    await expect(picker).toHaveAttribute('aria-expanded', 'false');
    await picker.click();
    await page.getByRole('link', { name: 'All projects' }).hover();
    await page.getByRole('link', { name: 'Trends' }).first().hover();
    await quiet();
    expect(posts(afterTarget)).toEqual([]);

    // Picking the other project (a client-side navigation away from the page) records nothing either, and the
    // shutdown that follows flushes nothing: no request and no beacon.
    await page.getByRole('link', { name: otherName }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${otherProjectId}$`));
    await quiet();
    const sent = sentEvents(posts(mark));
    expect(sent.map((event) => event.event)).toEqual(['$autocapture']);
    expect(JSON.stringify(sent)).not.toContain(otherName);
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('__e2e_beacons') || '[]'))).toEqual([]);
});

test('opt out stops all sending until opt in', async () => {
    await openDemo();
    const optOut = page.getByRole('button', { name: 'Opt out' });

    await optOut.click();
    await expect(optOut).toHaveAttribute('aria-pressed', 'true');
    const mark = traffic.length;
    await page.getByRole('button', { name: 'Capture $pageview' }).click();
    await page.getByRole('button', { name: 'Capture custom event' }).click();
    await quiet();
    expect(posts(mark)).toEqual([]);

    await optOut.click();
    await expect(optOut).toHaveAttribute('aria-pressed', 'false');
    const [response] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/e/'),
        page.getByRole('button', { name: 'Capture custom event' }).click(),
    ]);
    expect(response.status()).toBe(200);
});

test('UTM parameters and the page URL arrive as columns and properties of a $pageview', async () => {
    const source = `${marker}_src`;
    await openDemo({ query: `?utm_source=${source}&utm_medium=e2e&utm_campaign=demo39` });

    const [response] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/e/'),
        page.getByRole('button', { name: 'Capture $pageview' }).click(),
    ]);
    expect(response.status()).toBe(200);
    await waitForEvent('$pageview');

    // The Trends page breaks the event down by the materialised columns and by a property; poll until ClickHouse has it.
    const table = async (search) => {
        await page.goto(`/projects/${projectId}/insights/trends?e=%24pageview&${search}`);
        await page.getByText('Show data as a table').click();

        return page.getByRole('table');
    };

    await expect.poll(async () => (await (await table('b=utm_source')).textContent()) ?? '', { timeout: 60_000, intervals: [2000] }).toContain(source);
    await expect(await table('b=utm_medium')).toContainText('e2e');
    await expect(await table('b=utm_campaign')).toContainText('demo39');
    // posthog-js sends its own $browser / $os / $device_type: the columns are filled, not 'None'.
    for (const column of ['browser', 'os', 'device_type']) {
        const text = (await (await table(`b=${column}`)).textContent()) ?? '';
        expect(text, column).not.toMatch(/None/);
    }
    // The SDK reports the full URL, with its query parameters in its own order.
    const urls = await table('bp=%24current_url');
    await expect(urls).toContainText(`${baseOrigin}${demoPath()}?`);
    await expect(urls).toContainText(`utm_source=${source}`);
    await expect(await table('bp=%24lib')).toContainText('web');
});

/** Leaves the page the way a closed tab does and returns the sendBeacon calls it made while unloading. */
async function leaveAndReadBeacons() {
    await page.evaluate(() => localStorage.removeItem('__e2e_beacons'));
    await page.goto('about:blank');
    // Back on a page of the app (it has no SDK), where localStorage can be read.
    await page.goto(`/projects/${projectId}`);

    return page.evaluate(() => JSON.parse(localStorage.getItem('__e2e_beacons') || '[]'));
}

test('page close sends $pageleave by sendBeacon with a gzip body, and it arrives', async () => {
    await openDemo();

    const leavesBefore = await countOf('$pageleave');
    const beacons = await leaveAndReadBeacons();

    expect(beacons).toHaveLength(1);
    const url = new URL(beacons[0].url);
    expect(url.pathname).toBe('/e/');
    // A gzip body travels as text/plain (a CORS simple request: a beacon cannot preflight); no compression parameter.
    expect(beacons[0].type).toBe('text/plain');
    expect(url.searchParams.has('compression')).toBe(false);

    await waitForEvent('$pageleave', leavesBefore + 1);
});

test('leaving the demo page by navigation inside the app shuts the SDK down: nothing is sent when the tab closes later', async () => {
    await openDemo();
    await page.getByRole('link', { name: projectName }).first().click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}$`));

    const mark = traffic.length;
    await quiet();
    // Closing the tab now fires the `pagehide` listener the stopped SDK instance could not remove: it must send no beacon.
    expect(await leaveAndReadBeacons()).toEqual([]);
    await quiet();

    expect(posts(mark)).toEqual([]);
});

test('without the remote config the SDK never asks for it and still gets its events and its page leave through', async () => {
    await openDemo();
    const mark = traffic.length;

    await page.getByLabel('Fetch the remote config').uncheck();
    await expect(page.getByTestId('demo-log')).toContainText('remote config off');
    await expect(page.getByTestId('demo-status')).toContainText('Ready, posthog-js');
    expect(since(mark).filter((entry) => /^\/(array|flags|decide)\b/.test(new URL(entry.url).pathname))).toEqual([]);

    const name = `${marker}_noconfig`;
    await page.getByLabel('Event name').fill(name);
    const [response] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/e/' && r.request().method() === 'POST'),
        page.getByRole('button', { name: 'Capture custom event' }).click(),
    ]);
    expect(response.status()).toBe(200);
    // The SDK gzips by default even when it has no remote config to say the server can read it.
    const sent = response.request();
    expect([...(sent.postDataBuffer() ?? [])].slice(0, 2)).toEqual([0x1f, 0x8b]);
    await waitForEvent(name);

    const leavesBefore = await countOf('$pageleave');
    const beacons = await leaveAndReadBeacons();
    expect(beacons).toHaveLength(1);
    expect(beacons[0].type).toBe('text/plain');
    await waitForEvent('$pageleave', leavesBefore + 1);
});

test('with compression off the SDK posts plain JSON and its page leave is a base64 form', async () => {
    await openDemo();

    await page.getByLabel('Compress request bodies').uncheck();
    await expect(page.getByTestId('demo-log')).toContainText('gzip off');
    await expect(page.getByTestId('demo-status')).toContainText('Ready, posthog-js');

    const name = `${marker}_plain`;
    await page.getByLabel('Event name').fill(name);
    await page.getByLabel('Property name (optional)').fill('plan');
    await page.getByLabel('Property value').fill('free');
    const [response] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/e/' && r.request().method() === 'POST'),
        page.getByRole('button', { name: 'Capture custom event' }).click(),
    ]);
    expect(response.status()).toBe(200);
    const sent = response.request();
    expect(sent.headers()['content-type']).toContain('application/json');
    expect((sent.postDataBuffer() ?? Buffer.alloc(0)).subarray(0, 1).toString()).toMatch(/[\[{]/);
    await waitForEvent(name);

    const leavesBefore = await countOf('$pageleave');
    const beacons = await leaveAndReadBeacons();
    expect(beacons).toHaveLength(1);
    const url = new URL(beacons[0].url);
    expect(url.pathname).toBe('/e/');
    expect(beacons[0].type).toBe('application/x-www-form-urlencoded');
    expect(url.searchParams.get('compression')).toBe('base64');
    await waitForEvent('$pageleave', leavesBefore + 1);
});

test('the demo page is accessible in light and dark', async () => {
    await openDemo();
    // Give the log something to show.
    await page.getByRole('button', { name: 'Capture $pageview' }).click();
    await expect(page.getByTestId('demo-log-entry').first()).toBeVisible();

    expect(await seriousViolations()).toEqual([]);

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark');
    expect(await seriousViolations()).toEqual([]);

    await page.emulateMedia({ colorScheme: 'light' });
    await page.evaluate(() => (document.documentElement.dataset.theme = 'dark'));
    expect(await seriousViolations()).toEqual([]);
    await page.evaluate(() => delete document.documentElement.dataset.theme);
});

test('is usable by keyboard alone', async () => {
    await openDemo();

    await page.getByLabel('Event name').focus();
    await page.getByLabel('Event name').fill(`${marker}_keyboard`);
    const [response] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/e/'),
        page.keyboard.press('Enter'),
    ]);
    expect(response.status()).toBe(200);
    await waitForEvent(`${marker}_keyboard`);
});

test('the browser console stayed clean and nothing failed', async () => {
    expect(problems).toEqual([]);
    expect(warnings).toEqual([]);
    expect(tolerating).toEqual([]);
});
