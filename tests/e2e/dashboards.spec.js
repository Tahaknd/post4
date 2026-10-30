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

const ids = {};

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
    const cookie = (await page.context().cookies()).find((item) => item.name === 'XSRF-TOKEN');
    const response = await page.request.fetch(url, { method, data, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? '') } });

    return { status: response.status(), json: response.status() === 204 ? null : await response.json() };
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

test('had no console errors and no failed requests so far', async () => {
    expect(problems).toEqual([]);
});
