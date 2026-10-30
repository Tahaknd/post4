import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// One flow of saved insights and dashboards against a real stack: sign in, create a throwaway project (time zone UTC), send the
// events of seven people through the real capture endpoint, then SAVE a Trends, a Funnel and a Retention insight from their own
// pages (the dialog, a new dashboard, an existing one), open the dashboard and check the numbers of every tile, refresh a tile,
// reorder with the keyboard, remove and add a tile, repair a tile that needs review, filter and manage the saved insights, delete
// the dashboard. Also: never more than three tile runs at once, tiles that are out of view do not run, axe in light and dark, a
// narrow screen, and a clean console. The project is deleted at the end. Credentials come from E2E_EMAIL / E2E_PASSWORD (a
// throwaway administrator).
//
// The events are those of retention.spec.js (every one at 12:00 UTC of its day, never today, so a run just after midnight does not
// matter). `signup` is the first step and the cohort event, `visit` the second step and the return event; days are D-3 .. D-1:
//   D-3  signup: u1 u2 u3 u4
//   D-2  signup: u1 (again) u5 u6      visit: u1 u2
//   D-1  signup: u7                    visit: u1 u3 u5
// Trends signup, last 7 days:        8 events.
// Funnel signup -> visit (ordered):  7 people start, 4 convert (u1 u2 u3 u5), 57.14%.
// Retention first_time, day, D-3..D-1, 3 periods:  3 cohorts, 7 people, average of day 1 42.86%.

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const marker = `e2e${Date.now().toString(36)}`;
const signup = `${marker}_signup`;
const visit = `${marker}_visit`;
const projectName = `E2E ${marker}`;

const TRENDS_NAME = 'Signups last week';
const FUNNEL_NAME = 'Signup to visit';
const RETENTION_NAME = 'Visitors who come back';
const BOARD = 'Weekly board';

test.describe.configure({ mode: 'serial' });

const DAY = 86_400_000;
const today = Math.floor(Date.now() / DAY) * DAY;
const day = (offset) => new Date(today + offset * DAY).toISOString().slice(0, 10);
const noon = (offset, second) => new Date(today + offset * DAY + 12 * 3_600_000 + second * 1000).toISOString();

let page;
let projectId;
let apiKey;
let base;
const problems = [];
// Set while a test provokes errors on purpose: those are not problems.
let provoking = false;

const ids = { bulk: [] };

test.beforeAll(async ({ browser }) => {
    if (!email || !password) {
        throw new Error('Set E2E_EMAIL and E2E_PASSWORD to a throwaway administrator (see scripts/e2e.sh).');
    }

    page = await (await browser.newContext()).newPage();
    page.on('console', (message) => {
        // A report-only Content-Security-Policy violation is logged by Chrome as an INFO message, so match the text (see demo.spec.js).
        if (/Content Security Policy|\[Report Only\]/i.test(message.text())) {
            problems.push(`csp violation: ${message.text()}`);
        } else if (message.type() === 'error' && !provoking) {
            problems.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
    page.on('requestfailed', (request) => provoking || request.failure()?.errorText === 'net::ERR_ABORTED' || problems.push(`request failed: ${request.method()} ${request.url()}`));
    page.on('response', (response) => {
        // 429 is a legitimate answer of the busy/throttle paths; everything else >= 400 is a problem.
        if (response.status() >= 400 && response.status() !== 429 && !provoking) {
            problems.push(`http ${response.status()}: ${response.url()}`);
        }
    });

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/$/);

    await page.goto('/projects/create');
    await page.getByLabel('Name').fill(projectName);
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/\d+$/);
    projectId = Number(page.url().split('/').pop());
    base = `/projects/${projectId}`;
    await expect(page.getByText('Timezone UTC')).toBeVisible();

    await page.getByRole('button', { name: 'Reveal key' }).click();
    apiKey = (await page.getByTestId('api-key').textContent()).trim();
    expect(apiKey).toMatch(/^phc_/);

    const sign = (person, offset, second, plan) => ({ event: signup, distinct_id: `u${person}`, properties: { plan }, timestamp: noon(offset, second) });
    const back = (person, offset, second) => ({ event: visit, distinct_id: `u${person}`, properties: {}, timestamp: noon(offset, second) });
    const events = [
        sign(1, -3, 1, 'pro'), sign(2, -3, 2, 'pro'), sign(3, -3, 3, 'free'), sign(4, -3, 4, 'free'),
        sign(1, -2, 1, 'pro'), sign(5, -2, 5, 'pro'), sign(6, -2, 6, 'free'),
        sign(7, -1, 7, 'pro'),
        back(1, -2, 10), back(2, -2, 11),
        back(1, -1, 10), back(3, -1, 11), back(5, -1, 12),
    ];
    const capture = await page.request.post('/batch/', { data: { api_key: apiKey, batch: events } });
    expect(capture.ok()).toBeTruthy();

    // Wait until ClickHouse has them (see funnel.spec.js for why each poll searches for a different tail of the marker).
    let seen = false;
    for (let from = 1; from < marker.length - 2 && !seen; from++) {
        const answer = await page.request.get(`/api/projects/${projectId}/insights/events?q=${marker.slice(from)}`);
        seen = answer.ok() && (await answer.json()).events.some((item) => item.name === visit);
        if (!seen) {
            await page.waitForTimeout(2000);
        }
    }
    expect(seen, 'the events reached ClickHouse').toBeTruthy();
});

test.afterAll(async () => {
    if (projectId !== undefined) {
        await page.goto(`/projects/${projectId}`);
        await page.getByRole('button', { name: 'Delete project…' }).click();
        await page.getByRole('dialog').getByLabel('Project name').fill(projectName);
        await page.getByRole('dialog').getByRole('button', { name: 'Delete project' }).click();
        await expect(page).toHaveURL(/\/projects$/);
    }
    await page.close();
});

async function seriousViolations() {
    const results = await new AxeBuilder({ page }).analyze();

    return results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact)).map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`);
}

/** A call of the JSON API as the signed-in user (session cookie and the XSRF header of the cookie). */
async function api(method, url, data) {
    for (let attempt = 0; ; attempt++) {
        const cookie = (await page.context().cookies()).find((item) => item.name === 'XSRF-TOKEN');
        const response = await page.request.fetch(url, { method, data, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? '') } });

        // the writes of a test are fast; a throttled one waits as long as the server says and tries again
        if (response.status() === 429 && attempt < 5) {
            await page.waitForTimeout(Number(response.headers()['retry-after'] ?? 5) * 1000 + 250);
            continue;
        }

        return { status: response.status(), json: response.status() === 204 ? null : await response.json() };
    }
}

async function savedInsights() {
    return (await api('GET', `/api/projects/${projectId}/saved-insights?per_page=100`)).json.data;
}

const tile = (name) => page.getByRole('article', { name });
const titles = async () => page.getByTestId('tile-title').allTextContents();
const dialog = () => page.getByRole('dialog');

async function saveFromPage(name, { description, dashboard, newDashboard } = {}) {
    await page.getByRole('button', { name: 'Save insight…' }).click();
    await expect(dialog()).toBeVisible();
    await dialog().getByLabel('Name').fill(name);
    if (description) {
        await dialog().getByLabel('Description').fill(description);
    }
    if (newDashboard) {
        await dialog().getByLabel('Add to dashboard').selectOption('new');
        await dialog().getByLabel('New dashboard name').fill(newDashboard);
    } else if (dashboard) {
        await dialog().getByLabel('Add to dashboard').selectOption({ label: dashboard });
    }
    await dialog().getByRole('button', { name: 'Save insight' }).click();
    await expect(dialog()).toBeHidden();
}

test('saves a Trends insight from its page: the dialog is accessible, validates, and a relative range stays relative', async () => {
    await page.goto(`${base}/insights/trends?e=${signup}&p=last_7_days`);
    await expect(page.getByTestId('chart-total')).toContainText('8');

    // not complete: saving says what is missing
    await page.goto(`${base}/insights/trends`);
    await expect(page.getByRole('button', { name: 'Save insight…' })).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByText('Complete the form to save it.')).toBeVisible();

    await page.goto(`${base}/insights/trends?e=${signup}&p=last_7_days`);
    await expect(page.getByTestId('chart-total')).toContainText('8');
    const opener = page.getByRole('button', { name: 'Save insight…' });
    await opener.click();
    await expect(dialog()).toBeVisible();
    await expect(dialog()).toHaveAttribute('aria-modal', 'true');
    await expect(dialog()).toHaveAccessibleName('Save insight');
    await expect(dialog().getByLabel('Name')).toBeFocused();

    // Escape closes it and gives focus back to the button that opened it
    await page.keyboard.press('Escape');
    await expect(dialog()).toBeHidden();
    await expect(opener).toBeFocused();

    // the name is required, the message is on the field
    await opener.click();
    await dialog().getByRole('button', { name: 'Save insight' }).click();
    await expect(dialog().getByText('Enter a name.')).toBeVisible();
    await expect(dialog().getByLabel('Name')).toHaveAttribute('aria-invalid', 'true');
    expect(await seriousViolations()).toEqual([]);

    // a new dashboard may be created in the same step
    await dialog().getByLabel('Name').fill(TRENDS_NAME);
    await dialog().getByLabel('Description').fill('Signups of the last seven days');
    await dialog().getByLabel('Add to dashboard').selectOption('new');
    await dialog().getByRole('button', { name: 'Save insight' }).click();
    await expect(dialog().getByText('Enter a name for the new dashboard.')).toBeVisible();
    await dialog().getByLabel('New dashboard name').fill(BOARD);
    await dialog().getByRole('button', { name: 'Save insight' }).click();
    await expect(dialog()).toBeHidden();

    const status = page.getByTestId('save-status');
    await expect(status).toContainText(`Saved “${TRENDS_NAME}” and added it to the dashboard “${BOARD}”.`);
    await expect(status.getByRole('link', { name: 'View saved insights' })).toHaveAttribute('href', `${base}/saved-insights`);
    await expect(status.getByRole('link', { name: `Open dashboard ${BOARD}` })).toBeVisible();

    // the page now edits the saved insight, and the address says so
    await expect(page.getByRole('button', { name: 'Update saved insight' })).toBeVisible();
    await expect(page).toHaveURL(/saved=[0-9a-f-]{36}/);
    await expect(page.getByTestId('saved-editing')).toContainText(`Editing the saved insight “${TRENDS_NAME}”.`);

    const saved = (await savedInsights()).find((item) => item.name === TRENDS_NAME);
    ids.trends = saved.id;
    expect(saved.type).toBe('trends');
    expect(saved.date.preset).toBe('last_7_days');
    expect(saved.state.status).toBe('ok');
    const detail = (await api('GET', `/api/projects/${projectId}/saved-insights/${saved.id}`)).json.data;
    expect(detail.query.events).toEqual([signup]);
    const boards = (await api('GET', `/api/projects/${projectId}/dashboards`)).json.data;
    ids.board = boards.find((item) => item.name === BOARD).id;
    expect(boards.find((item) => item.name === BOARD).tiles_count).toBe(1);
});

test('saves a Funnel insight from its page onto the existing dashboard', async () => {
    await page.goto(`${base}/insights/funnel?s1=${encodeURIComponent(JSON.stringify([signup, []]))}&s2=${encodeURIComponent(JSON.stringify([visit, []]))}`);
    await expect(page.getByTestId('funnel-summary')).toContainText('Entrants7');
    await expect(page.getByTestId('funnel-summary')).toContainText('Converted4');

    await saveFromPage(FUNNEL_NAME, { dashboard: BOARD });
    await expect(page.getByTestId('save-status')).toContainText(`Saved “${FUNNEL_NAME}” and added it to the dashboard “${BOARD}”.`);

    const saved = (await savedInsights()).find((item) => item.name === FUNNEL_NAME);
    ids.funnel = saved.id;
    expect(saved.type).toBe('funnel');
    expect(saved.date.preset).toBe('last_30_days');
    expect((await api('GET', `/api/projects/${projectId}/dashboards/${ids.board}`)).json.data.tiles_count).toBe(2);
});

test('saves a Retention insight with absolute dates: an absolute range stays absolute', async () => {
    const t = encodeURIComponent(JSON.stringify([signup, []]));
    const r = encodeURIComponent(JSON.stringify([visit, []]));
    await page.goto(`${base}/insights/retention?t=${t}&r=${r}&n=3&from=${day(-3)}&to=${day(-1)}`);
    await expect(page.getByTestId('retention-summary')).toContainText('People in cohorts7');

    await saveFromPage(RETENTION_NAME, { dashboard: BOARD });

    const saved = (await savedInsights()).find((item) => item.name === RETENTION_NAME);
    ids.retention = saved.id;
    expect(saved.type).toBe('retention');
    expect(saved.date.preset).toBeNull();
    const detail = (await api('GET', `/api/projects/${projectId}/saved-insights/${saved.id}`)).json.data;
    expect(detail.query.range).toEqual({ from: `${day(-3)}T00:00:00.000Z`, to: `${day(0)}T00:00:00.000Z` });
    expect((await api('GET', `/api/projects/${projectId}/dashboards/${ids.board}`)).json.data.tiles_count).toBe(3);
});


const boardUrl = () => `${base}/dashboards/${ids.board}`;
const summary = (name, id) => tile(name).getByTestId(id);

/** The sum of the layout shifts of the page since it was opened (the browser's own measure, without input-caused ones). */
async function layoutShift() {
    return page.evaluate(() => window.__shift ?? 0);
}

test('lists the dashboard in the navigation and on the dashboards page', async () => {
    await page.addInitScript(() => {
        window.__shift = 0;
        try {
            new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (!entry.hadRecentInput) {
                        window.__shift += entry.value;
                    }
                }
            }).observe({ type: 'layout-shift', buffered: true });
        } catch {
            // a browser without layout-shift entries: the test then measures nothing
        }
    });

    await page.goto(base);
    await expect(page.getByRole('heading', { name: 'Dashboards', exact: true })).toBeVisible();
    await page.getByRole('navigation').getByRole('link', { name: 'Dashboards' }).click();
    await expect(page).toHaveURL(new RegExp(`${base}/dashboards$`));
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Dashboards' })).toHaveAttribute('aria-current', 'page');

    const card = page.getByTestId('dashboard-card').filter({ hasText: BOARD });
    await expect(card).toHaveCount(1);
    await expect(card.getByTestId('tile-count')).toHaveText('3 tiles');
    await expect(card).toContainText('Last viewed');
    await card.getByRole('link', { name: BOARD }).click();
    await expect(page).toHaveURL(new RegExp(`${base}/dashboards/${ids.board}$`));
});

test('shows every tile in position order with the exact numbers of its insight, when it was computed, and no layout shift', async () => {
    await page.goto(boardUrl());
    await expect(page.getByRole('heading', { name: BOARD, level: 1 })).toBeVisible();
    await expect(page.getByTestId('tile-title')).toHaveCount(3);
    expect(await titles()).toEqual([TRENDS_NAME, FUNNEL_NAME, RETENTION_NAME]);

    await expect(summary(TRENDS_NAME, 'chart-total')).toHaveText('8');
    await expect(tile(TRENDS_NAME).getByTestId('chart').locator('svg')).toBeVisible();
    await expect(tile(TRENDS_NAME).getByTestId('type-badge')).toHaveText('Trends');

    await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Entrants7');
    await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Converted4');
    await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Overall conversion57.14%');
    await expect(tile(FUNNEL_NAME).getByTestId('chart')).toHaveAttribute('aria-label', new RegExp(`1\\. ${signup}: 7; 2\\. ${visit}: 4\\.`));

    await expect(summary(RETENTION_NAME, 'retention-summary')).toContainText('Cohorts3');
    await expect(summary(RETENTION_NAME, 'retention-summary')).toContainText('People in cohorts7');
    await expect(summary(RETENTION_NAME, 'retention-summary')).toContainText('Average, day 142.86%');
    await expect(tile(RETENTION_NAME).getByTestId('type-badge')).toHaveText('Retention');

    // "Updated 5 minutes ago": the relative time, with the exact moment in `datetime` and in the title
    for (const name of [TRENDS_NAME, FUNNEL_NAME, RETENTION_NAME]) {
        const updated = summary(name, 'tile-updated');
        await expect(updated).toContainText(/^Updated (just now|\d+ minutes? ago)$/);
        await expect(updated.locator('time')).toHaveAttribute('datetime', /^\d{4}-\d{2}-\d{2}T/);
        await expect(updated.locator('time')).toHaveAttribute('title', /\d{4}/);
    }

    // every tile has the same reserved height, whatever it shows
    const heights = await page.getByTestId('dashboard-tile').evaluateAll((nodes) => nodes.map((node) => Math.round(node.getBoundingClientRect().height)));
    expect(new Set(heights).size).toBe(1);
    expect(heights[0]).toBeGreaterThanOrEqual(528);
    expect(await layoutShift()).toBeLessThan(0.1);
});

test('refreshes one tile with fresh: true and the whole dashboard through the same queue', async () => {
    await page.goto(boardUrl());
    await expect(summary(TRENDS_NAME, 'chart-total')).toHaveText('8');
    await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Entrants7');
    await expect(summary(RETENTION_NAME, 'retention-summary')).toContainText('Cohorts3');

    const bodies = [];
    const onRequest = (request) => request.method() === 'POST' && /saved-insights\/[0-9a-f-]{36}\/run$/.test(request.url()) && bodies.push({ url: request.url(), body: request.postDataJSON() });
    page.on('request', onRequest);
    try {
        await page.getByRole('button', { name: `Refresh ${TRENDS_NAME}` }).click();
        await expect(page.getByTestId('status')).toHaveText(`${TRENDS_NAME} refreshed.`);
        expect(bodies).toEqual([{ url: expect.stringContaining(ids.trends), body: { fresh: true } }]);
        await expect(summary(TRENDS_NAME, 'chart-total')).toHaveText('8');

        bodies.length = 0;
        await page.getByRole('button', { name: 'Refresh all' }).click();
        await expect(page.getByTestId('status')).toHaveText('Refreshing every tile.');
        await expect.poll(() => bodies.length).toBe(3);
        expect(bodies.every((item) => item.body.fresh === true)).toBe(true);
        expect(new Set(bodies.map((item) => item.url)).size).toBe(3);
        await expect(page.getByTestId('status')).toContainText('refreshed.');
    } finally {
        page.off('request', onRequest);
    }
});

test('moves tiles earlier and later with the keyboard, keeps focus, saves the order, and reloads when the order is stale', async () => {
    await page.goto(boardUrl());
    await expect(summary(RETENTION_NAME, 'retention-summary')).toContainText('Cohorts3');
    const later = (name) => page.getByRole('button', { name: `Move later: ${name}` });
    const earlier = (name) => page.getByRole('button', { name: `Move earlier: ${name}` });

    await expect(earlier(TRENDS_NAME)).toHaveAttribute('aria-disabled', 'true');
    await expect(later(RETENTION_NAME)).toHaveAttribute('aria-disabled', 'true');

    const puts = [];
    page.on('request', (request) => request.method() === 'PUT' && puts.push(request.postDataJSON()));

    await later(TRENDS_NAME).focus();
    await page.keyboard.press('Enter');
    await expect.poll(titles).toEqual([FUNNEL_NAME, TRENDS_NAME, RETENTION_NAME]);
    await expect(page.getByTestId('status')).toHaveText(`${TRENDS_NAME} moved later, now position 2 of 3.`);
    await expect(later(TRENDS_NAME)).toBeFocused();

    await page.keyboard.press('Enter');
    await expect.poll(titles).toEqual([FUNNEL_NAME, RETENTION_NAME, TRENDS_NAME]);
    // it reached the end: focus moved to the twin button, which is still usable
    await expect(later(TRENDS_NAME)).toHaveAttribute('aria-disabled', 'true');
    await expect(earlier(TRENDS_NAME)).toBeFocused();
    await expect.poll(() => puts.length).toBe(2);
    expect(puts[1].tiles).toHaveLength(3);

    // the order is saved: a reload shows it, the API has it
    await page.reload();
    await expect.poll(titles).toEqual([FUNNEL_NAME, RETENTION_NAME, TRENDS_NAME]);
    const stored = (await api('GET', `/api/projects/${projectId}/dashboards/${ids.board}`)).json.data.tiles.map((item) => item.saved_insight.name);
    expect(stored).toEqual([FUNNEL_NAME, RETENTION_NAME, TRENDS_NAME]);

    // back to the first order, again by keyboard
    await earlier(TRENDS_NAME).focus();
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await expect.poll(titles).toEqual([TRENDS_NAME, FUNNEL_NAME, RETENTION_NAME]);

    // a stale order (the server answers 422): the page reloads the dashboard instead of guessing
    provoking = true;
    try {
        await page.route('**/tiles/order', (route) => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'x', error: { kind: 'validation', message: 'x', retryable: false, retry_after_seconds: null }, errors: { tiles: ['tiles must be the current set.'] } }) }), { times: 1 });
        await later(TRENDS_NAME).focus();
        await page.keyboard.press('Enter');
        await expect(page.getByTestId('status')).toContainText('changed elsewhere');
        await expect.poll(titles).toEqual([TRENDS_NAME, FUNNEL_NAME, RETENTION_NAME]);
    } finally {
        await page.unroute('**/tiles/order');
        provoking = false;
    }
});

test('removes a tile without deleting its saved insight, and adds it again from the picker', async () => {
    await page.goto(boardUrl());
    await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Entrants7');

    await page.getByRole('button', { name: `Remove from dashboard: ${FUNNEL_NAME}` }).click();
    await expect.poll(titles).toEqual([TRENDS_NAME, RETENTION_NAME]);
    await expect(page.getByTestId('status')).toHaveText(`Removed ${FUNNEL_NAME} from the dashboard. The saved insight is kept.`);
    expect((await savedInsights()).some((item) => item.id === ids.funnel)).toBe(true);

    await page.getByRole('button', { name: 'Add tile' }).first().click();
    await expect(dialog()).toBeVisible();
    await expect(dialog()).toHaveAccessibleName('Add a tile');
    await expect(dialog().getByLabel('Search saved insights')).toBeFocused();
    // only what is not on the dashboard is offered, and it can be searched
    await expect(dialog().getByTestId('picker-list').getByRole('listitem')).toHaveCount(1);
    await dialog().getByLabel('Search saved insights').fill('zzz nothing');
    await expect(dialog().getByTestId('picker-empty')).toHaveText('No saved insight matches.');
    await dialog().getByLabel('Search saved insights').fill('signup to');
    await dialog().getByRole('button', { name: `Add ${FUNNEL_NAME}` }).click();
    await expect(dialog().getByRole('status')).toHaveText(`Added ${FUNNEL_NAME} to the dashboard.`);
    await dialog().getByLabel('Search saved insights').fill('');
    await expect(dialog().getByTestId('picker-empty')).toHaveText('Every saved insight is already on this dashboard.');
    await dialog().getByRole('button', { name: 'Done' }).click();
    await expect(dialog()).toBeHidden();

    await expect.poll(titles).toEqual([TRENDS_NAME, RETENTION_NAME, FUNNEL_NAME]);
    await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Entrants7');
    // back in the first order for the next tests
    await page.getByRole('button', { name: `Move earlier: ${FUNNEL_NAME}` }).focus();
    await page.keyboard.press('Enter');
    await expect.poll(titles).toEqual([TRENDS_NAME, FUNNEL_NAME, RETENTION_NAME]);
});

/** Stores a definition that no longer passes the rules of its type (an `outdated_version`), the way a future release would leave it. */
function corrupt(insightId) {
    const code = `$i = App\\Models\\SavedInsight::where('uuid', '${insightId}')->firstOrFail(); $q = $i->query; $q['v'] = 99; $i->query = $q; $i->query_version = 99; $i->save(); echo 'ok';`;
    try {
        return execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'tinker', '--execute', code], { cwd: process.cwd(), stdio: 'pipe', timeout: 60_000 }).toString().includes('ok');
    } catch {
        return false;
    }
}

test('a tile that needs review explains why, is never run, and is repaired from its editor page', async () => {
    test.skip(!corrupt(ids.funnel), 'needs `docker compose exec app php artisan tinker` to store a definition that no longer validates (there is no API for it)');

    const runs = [];
    const onRequest = (request) => request.method() === 'POST' && /saved-insights\/[0-9a-f-]{36}\/run$/.test(request.url()) && runs.push(request.url());
    page.on('request', onRequest);
    try {
        await page.goto(boardUrl());
        await expect(summary(TRENDS_NAME, 'chart-total')).toHaveText('8');
        await expect(summary(RETENTION_NAME, 'retention-summary')).toContainText('Cohorts3');
        const broken = summary(FUNNEL_NAME, 'tile-needs-review');
        await expect(broken).toBeVisible();
        await expect(broken).toContainText('Needs review');
        await expect(broken).toContainText('older version');
        expect(runs.some((url) => url.includes(ids.funnel))).toBe(false);
        expect(runs).toHaveLength(2);
        await expect(page.getByRole('button', { name: `Refresh ${FUNNEL_NAME}` })).toHaveCount(0);
    } finally {
        page.off('request', onRequest);
    }

    // the list of saved insights says so too
    await page.goto(`${base}/saved-insights`);
    const row = page.getByTestId('saved-insight-row').filter({ hasText: FUNNEL_NAME });
    await expect(row.getByTestId('state-badge')).toHaveText(/Needs review/);
    await expect(row.getByTestId('review-reason')).toContainText('older version');
    await expect(row.getByRole('link', { name: `Open to repair ${FUNNEL_NAME}` })).toBeVisible();

    // "Open to repair" opens the Funnel page with the stored definition in the form, and Update repairs it
    await page.goto(boardUrl());
    await page.getByRole('link', { name: `Open ${FUNNEL_NAME} to repair` }).click();
    await expect(page).toHaveURL(new RegExp(`${base}/insights/funnel\\?.*saved=${ids.funnel}`));
    await expect(page.getByTestId('saved-needs-review')).toContainText('Needs review');
    await expect(page.getByRole('combobox', { name: 'Step 1 event' })).toHaveValue(signup);
    await expect(page.getByRole('combobox', { name: 'Step 2 event' })).toHaveValue(visit);
    await expect(page.getByTestId('funnel-summary')).toContainText('Entrants7');

    await page.getByRole('button', { name: 'Update saved insight' }).click();
    await expect(page.getByTestId('save-status')).toContainText(`Updated “${FUNNEL_NAME}”.`);
    await expect(page.getByTestId('saved-needs-review')).toHaveCount(0);
    const repaired = (await savedInsights()).find((item) => item.id === ids.funnel);
    expect(repaired.state).toEqual({ status: 'ok', reason: null, migrated: false });

    await page.goto(boardUrl());
    await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Converted4');
});


test('opens a saved insight in its page with the form filled in, and Update saved insight replaces its definition', async () => {
    // from the saved insight list: Open leads to the page with saved=<uuid> only, the page fills the form from the stored definition
    await page.goto(`${base}/saved-insights`);
    await page.getByRole('link', { name: `Open ${RETENTION_NAME}`, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${base}/insights/retention\\?.*saved=${ids.retention}`));
    await expect(page.getByTestId('saved-editing')).toContainText(`Editing the saved insight “${RETENTION_NAME}”.`);
    await expect(page.getByTestId('retention-summary')).toContainText('People in cohorts7');
    await expect(page.getByRole('combobox', { name: 'Cohort event', exact: true })).toHaveValue(signup);
    await expect(page.getByLabel('Number of periods')).toHaveValue('3');
    // the address carries the definition in the page's own keys, and still the saved key
    await expect(page).toHaveURL(new RegExp(`from=${day(-3)}&to=${day(-1)}|from=${day(-3)}.*to=${day(-1)}`));

    // a trends insight keeps "Last 7 days" as a preset in the form
    await page.goto(`${base}/insights/trends?saved=${ids.trends}`);
    await expect(page.getByTestId('chart-total')).toContainText('8');
    await expect(page.getByLabel('Date range')).toHaveValue('last_7_days');

    // Update saved insight replaces the definition
    await page.goto(`${base}/insights/retention?saved=${ids.retention}`);
    await expect(page.getByTestId('retention-summary')).toContainText('People in cohorts7');
    await page.getByLabel('Number of periods').fill('4');
    await page.getByRole('button', { name: 'Update saved insight' }).click();
    await expect(page.getByTestId('save-status')).toContainText(`Updated “${RETENTION_NAME}”.`);
    const detail = (await api('GET', `/api/projects/${projectId}/saved-insights/${ids.retention}`)).json.data;
    expect(detail.query.periods).toBe(4);
    expect(detail.date.preset).toBeNull();

    // Save as new insight: the dialog starts from the name of the saved one and makes another insight
    await page.getByRole('button', { name: 'Save as new insight…' }).click();
    await expect(dialog()).toHaveAccessibleName('Save as new insight');
    await expect(dialog().getByLabel('Name')).toHaveValue(RETENTION_NAME);
    await dialog().getByLabel('Name').fill(`${RETENTION_NAME} copy`);
    await dialog().getByRole('button', { name: 'Save as new insight' }).click();
    await expect(dialog()).toBeHidden();
    await expect(page.getByTestId('saved-editing')).toContainText(`${RETENTION_NAME} copy`);
    expect((await savedInsights()).filter((item) => item.name.startsWith(RETENTION_NAME))).toHaveLength(2);
    const copy = (await savedInsights()).find((item) => item.name === `${RETENTION_NAME} copy`);
    await api('DELETE', `/api/projects/${projectId}/saved-insights/${copy.id}`);

    // a link to an insight that does not exist, or one of another type, is said so and does not break the page
    await page.goto(`${base}/insights/funnel?saved=${ids.trends}`);
    await expect(page.getByTestId('saved-note')).toContainText('of another type');
    provoking = true; // the API answers that one with a 404, on purpose
    try {
        await page.goto(`${base}/insights/funnel?saved=0197a3b2-1c4d-7e5f-8a6b-7c8d9e0f1a2b`);
        await expect(page.getByTestId('saved-note')).toContainText('no longer exists');
    } finally {
        provoking = false;
    }
});

test('manages the saved insights: filter by type and text, rename, delete with a confirmation, and page through a long list', async () => {
    await page.goto(`${base}/saved-insights`);
    const rows = page.getByTestId('saved-insight-row');
    await expect(rows).toHaveCount(3);
    await expect(page.getByTestId('saved-insights-table').getByRole('columnheader')).toHaveText(['Name', 'Type', 'State', 'Updated', 'Created by', 'Actions']);

    await page.getByLabel('Type').selectOption('funnel');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText(FUNNEL_NAME);
    await page.getByLabel('Type').selectOption('');
    await page.getByLabel('Search').fill('VISITORS');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText(RETENTION_NAME);
    await page.getByLabel('Search').fill('no such insight');
    await expect(page.getByTestId('state-no-match')).toBeVisible();
    await page.getByLabel('Search').fill('');
    await expect(rows).toHaveCount(3);

    // rename: the dialog starts from the stored name; the description may be set
    const renameButton = page.getByRole('button', { name: `Rename ${TRENDS_NAME}` });
    await renameButton.click();
    await expect(dialog()).toHaveAccessibleName('Rename saved insight');
    await expect(dialog().getByLabel('Name')).toHaveValue(TRENDS_NAME);
    await page.keyboard.press('Escape');
    await expect(renameButton).toBeFocused();
    await renameButton.click();
    await dialog().getByLabel('Name').fill('');
    await dialog().getByRole('button', { name: 'Save' }).click();
    await expect(dialog().getByText('Enter a name.')).toBeVisible();
    await dialog().getByLabel('Name').fill('Signups, last 7 days');
    await dialog().getByLabel('Description').fill('Renamed in a test');
    await dialog().getByRole('button', { name: 'Save' }).click();
    await expect(dialog()).toBeHidden();
    await expect(page.getByTestId('status')).toHaveText('Renamed the saved insight to Signups, last 7 days.');
    await expect(rows.filter({ hasText: 'Signups, last 7 days' })).toContainText('Renamed in a test');
    expect((await api('GET', `/api/projects/${projectId}/saved-insights/${ids.trends}`)).json.data.name).toBe('Signups, last 7 days');

    // delete: Cancel is focused first, Escape leaves it, the confirm deletes
    const made = (await api('POST', `/api/projects/${projectId}/saved-insights`, { name: 'Throwaway', type: 'trends', query: { events: [signup], date_preset: 'last_7_days' } })).json.data;
    await page.reload();
    await page.getByRole('button', { name: 'Delete Throwaway' }).click();
    await expect(dialog()).toHaveAccessibleName('Delete this saved insight?');
    await expect(dialog().getByRole('button', { name: 'Cancel' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog()).toBeHidden();
    await expect(rows.filter({ hasText: 'Throwaway' })).toHaveCount(1);
    await page.getByRole('button', { name: 'Delete Throwaway' }).click();
    await dialog().getByRole('button', { name: 'Delete saved insight' }).click();
    await expect(dialog()).toBeHidden();
    await expect(rows.filter({ hasText: 'Throwaway' })).toHaveCount(0);
    await expect(page.getByTestId('status')).toHaveText('Deleted the saved insight Throwaway.');
    expect((await api('GET', `/api/projects/${projectId}/saved-insights/${made.id}`)).status).toBe(404);

    // twenty-one more make a second page (20 a page)
    for (let n = 1; n <= 21; n++) {
        const made = await api('POST', `/api/projects/${projectId}/saved-insights`, { name: `Bulk ${String(n).padStart(2, '0')}`, type: 'trends', query: { events: [signup], date_preset: 'last_7_days', filters: [{ key: 'plan', operator: 'equals', value: `v${n}` }] } });
        ids.bulk.push(made.json.data.id);
    }
    await page.reload();
    await expect(rows).toHaveCount(20);
    await expect(page.getByTestId('pager-text')).toHaveText('Showing 1 to 20 of 24');
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(rows).toHaveCount(4);
    await expect(page.getByTestId('pager-text')).toHaveText('Showing 21 to 24 of 24');
    await expect(page.getByRole('button', { name: 'Next' })).toHaveAttribute('aria-disabled', 'true');
    await page.getByRole('button', { name: 'Previous' }).click();
    await expect(rows).toHaveCount(20);
});

test('dashboards: create from the list, rename, delete with a confirmation, rename and delete from the dashboard itself', async () => {
    await page.goto(`${base}/dashboards`);
    const createButton = page.getByRole('button', { name: 'New dashboard' });
    await createButton.click();
    await expect(dialog()).toHaveAccessibleName('New dashboard');
    await expect(dialog().getByLabel('Name')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(createButton).toBeFocused();
    await createButton.click();
    await dialog().getByRole('button', { name: 'Create dashboard' }).click();
    await expect(dialog().getByText('Enter a name.')).toBeVisible();
    await dialog().getByLabel('Name').fill('Scratch board');
    await dialog().getByLabel('Description').fill('To be deleted');
    await dialog().getByRole('button', { name: 'Create dashboard' }).click();
    // creating opens it: an empty dashboard says what to do
    await expect(page.getByRole('heading', { name: 'Scratch board', level: 1 })).toBeVisible();
    await expect(page.getByTestId('state-empty')).toContainText('This dashboard has no tiles yet');
    await expect(page.getByTestId('dashboard-description')).toHaveText('To be deleted');

    // rename it from its page
    await page.getByRole('button', { name: 'Rename', exact: true }).click();
    await expect(dialog().getByLabel('Name')).toHaveValue('Scratch board');
    await dialog().getByLabel('Name').fill('Scratch board 2');
    await dialog().getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('heading', { name: 'Scratch board 2', level: 1 })).toBeVisible();
    await expect(page).toHaveTitle(/Scratch board 2/);

    // delete it from the list, with the confirmation
    await page.goto(`${base}/dashboards`);
    const cards = page.getByTestId('dashboard-card');
    // the board and the one just created
    await expect(cards).toHaveCount(2);
    await page.getByRole('button', { name: 'Rename Scratch board 2' }).click();
    await dialog().getByLabel('Name').fill('Scratch board 3');
    await dialog().getByRole('button', { name: 'Save' }).click();
    await expect(cards.filter({ hasText: 'Scratch board 3' })).toHaveCount(1);
    await page.getByRole('button', { name: 'Delete Scratch board 3' }).click();
    await expect(dialog()).toHaveAccessibleName('Delete this dashboard?');
    await expect(dialog().getByRole('button', { name: 'Cancel' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(cards.filter({ hasText: 'Scratch board 3' })).toHaveCount(1);
    await page.getByRole('button', { name: 'Delete Scratch board 3' }).click();
    await dialog().getByRole('button', { name: 'Delete dashboard' }).click();
    await expect(cards.filter({ hasText: 'Scratch board 3' })).toHaveCount(0);
    await expect(page.getByTestId('status')).toHaveText('Deleted the dashboard Scratch board 3.');

    // the saved insights of a deleted dashboard stay; a deleted saved insight leaves its tiles
    expect((await savedInsights()).length).toBeGreaterThanOrEqual(24);
});

test('has no serious accessibility violations in the light and the dark scheme, with dialogs open', async () => {
    for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });

        await page.goto(boardUrl());
        await expect(summary(RETENTION_NAME, 'retention-summary')).toContainText('Cohorts3');
        await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Entrants7');
        await expect(summary('Signups, last 7 days', 'chart-total')).toHaveText('8');
        expect(await seriousViolations(), `dashboard, ${scheme}`).toEqual([]);

        await page.getByRole('button', { name: 'Add tile' }).first().click();
        await expect(dialog()).toBeVisible();
        expect(await seriousViolations(), `add tile dialog, ${scheme}`).toEqual([]);
        await page.keyboard.press('Escape');

        await page.goto(`${base}/dashboards`);
        await expect(page.getByTestId('dashboard-card').first()).toBeVisible();
        expect(await seriousViolations(), `dashboards, ${scheme}`).toEqual([]);

        await page.goto(`${base}/saved-insights`);
        await expect(page.getByTestId('saved-insight-row').first()).toBeVisible();
        expect(await seriousViolations(), `saved insights, ${scheme}`).toEqual([]);

        await page.goto(`${base}/insights/funnel?saved=${ids.funnel}`);
        await expect(page.getByTestId('saved-editing')).toBeVisible();
        await page.getByRole('button', { name: 'Save as new insight…' }).click();
        await expect(dialog()).toBeVisible();
        expect(await seriousViolations(), `save dialog, ${scheme}`).toEqual([]);
        await page.keyboard.press('Escape');
    }
    await page.emulateMedia({ colorScheme: 'light' });
});

test('works on a narrow screen: one column of tiles and nothing scrolls sideways', async () => {
    await page.setViewportSize({ width: 375, height: 800 });
    try {
        for (const url of [boardUrl(), `${base}/dashboards`, `${base}/saved-insights`, `${base}/insights/trends?saved=${ids.trends}`]) {
            await page.goto(url);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await page.waitForTimeout(800);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, url).toBeLessThanOrEqual(0);
        }

        await page.goto(boardUrl());
        await expect(summary(FUNNEL_NAME, 'funnel-summary')).toContainText('Entrants7');
        const lefts = await page.getByTestId('dashboard-tile').evaluateAll((nodes) => nodes.map((node) => Math.round(node.getBoundingClientRect().left)));
        expect(new Set(lefts).size).toBe(1);
        expect(await seriousViolations()).toEqual([]);

        await page.getByRole('button', { name: 'Add tile' }).first().click();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
        await page.keyboard.press('Escape');
    } finally {
        await page.setViewportSize({ width: 1280, height: 720 });
    }
});

test('never runs more than three tiles at once, and only the tiles that are in view', async () => {
    test.setTimeout(300_000);
    const many = (await api('POST', `/api/projects/${projectId}/dashboards`, { name: 'Many tiles' })).json.data;
    for (const id of ids.bulk.slice(0, 12)) {
        expect((await api('POST', `/api/projects/${projectId}/dashboards/${many.id}/tiles`, { saved_insight_id: id })).status).toBe(201);
    }

    // A tile that is told "busy" (429: the user's 60 runs a minute) asks again after Retry-After, so the NUMBER of POSTs may be above
    // the number of tiles; what is counted is the distinct tiles that asked, and the most POSTs that were in flight at once.
    let inflight = 0;
    let peak = 0;
    const asked = new Set();
    const freshAsked = new Set();
    const isRun = (request) => request.method() === 'POST' && /saved-insights\/[0-9a-f-]{36}\/run$/.test(request.url());
    const begin = (request) => {
        if (isRun(request)) {
            asked.add(request.url());
            peak = Math.max(peak, ++inflight);
            if (request.postDataJSON()?.fresh === true) {
                freshAsked.add(request.url());
            }
        }
    };
    const end = (request) => isRun(request) && inflight--;
    page.on('request', begin);
    page.on('requestfinished', end);
    page.on('requestfailed', end);
    // a slow answer, so that runs that would overlap really do
    await page.route('**/saved-insights/*/run', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 400));
        await route.continue();
    });

    try {
        // a normal window: the tiles below the fold do not ask until they come into view
        await page.goto(`${base}/dashboards/${many.id}`);
        await expect(page.getByTestId('dashboard-tile')).toHaveCount(12);
        await expect.poll(() => asked.size).toBeGreaterThanOrEqual(3);
        await page.waitForTimeout(4000);
        expect(asked.size).toBeLessThan(12);
        expect(asked.size).toBeLessThanOrEqual(6);
        expect(peak).toBeLessThanOrEqual(3);
        const early = asked.size;
        await page.getByTestId('dashboard-tile').last().scrollIntoViewIfNeeded();
        await expect.poll(() => asked.size, { timeout: 60_000 }).toBe(12);
        expect(early).toBeLessThan(12);
        expect(peak).toBeLessThanOrEqual(3);
        // the bulk insights filter on a plan nobody has: their tiles settle as `empty`, which is as finished as `result`
        await expect(page.locator('[data-testid="dashboard-tile"][data-state="result"], [data-testid="dashboard-tile"][data-state="empty"]')).toHaveCount(12, { timeout: 90_000 });

        // everything in view at once and a refresh of all of it: still three at a time, all 12 asked fresh
        await page.setViewportSize({ width: 1280, height: 4200 });
        peak = 0;
        await page.getByRole('button', { name: 'Refresh all' }).click();
        await expect.poll(() => freshAsked.size, { timeout: 90_000 }).toBe(12);
        expect(peak).toBeLessThanOrEqual(3);
        await expect(page.getByTestId('status')).toContainText('refreshed.', { timeout: 60_000 });
    } finally {
        await page.setViewportSize({ width: 1280, height: 720 });
        await page.unroute('**/saved-insights/*/run');
        page.off('request', begin);
        page.off('requestfinished', end);
        page.off('requestfailed', end);
    }
    ids.many = many.id;
});

test('deletes the dashboards, which keeps their saved insights', async () => {
    await page.goto(boardUrl());
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await page.getByRole('button', { name: 'Delete dashboard' }).click();
    await expect(dialog()).toHaveAccessibleName('Delete this dashboard?');
    await expect(dialog().getByRole('button', { name: 'Cancel' })).toBeFocused();
    await dialog().getByRole('button', { name: 'Delete dashboard' }).click();
    await expect(page).toHaveURL(new RegExp(`${base}/dashboards$`));
    await expect(page.getByTestId('dashboard-card').filter({ hasText: BOARD })).toHaveCount(0);

    await api('DELETE', `/api/projects/${projectId}/dashboards/${ids.many}`);
    await page.reload();
    await expect(page.getByTestId('state-empty')).toContainText('No dashboards yet');
    expect((await api('GET', `/api/projects/${projectId}/dashboards/${ids.board}`)).status).toBe(404);
    expect((await savedInsights()).some((item) => item.id === ids.funnel)).toBe(true);
});

test('had no console errors and no failed requests so far', async () => {
    expect(problems).toEqual([]);
});
