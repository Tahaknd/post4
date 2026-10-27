import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// One smoke flow of the Trends page against a real stack: sign in, create a throwaway project, send events through the
// real capture endpoint, open Trends, pick an event with the keyboard, see the chart, toggle bar/line and the table,
// check there are no console errors or failed requests and that axe finds no serious or critical accessibility issue.
// The project is deleted at the end. Credentials come from E2E_EMAIL / E2E_PASSWORD (a throwaway administrator).

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const marker = `e2e${Date.now().toString(36)}`;
const eventName = `${marker}_signup`;
const projectName = `E2E ${marker}`;

test.describe.configure({ mode: 'serial' });

let page;
let projectId;
let apiKey;
const problems = [];

test.beforeAll(async ({ browser }) => {
    if (!email || !password) {
        throw new Error('Set E2E_EMAIL and E2E_PASSWORD to a throwaway administrator (see scripts/e2e.sh).');
    }

    // axe needs a page of its own context (browser.newContext()), not browser.newPage().
    page = await (await browser.newContext()).newPage();
    page.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
    // A request the page aborted itself (a superseded search or run) is not a failure.
    page.on('requestfailed', (request) => request.failure()?.errorText === 'net::ERR_ABORTED' || problems.push(`request failed: ${request.method()} ${request.url()}`));
    page.on('response', (response) => {
        // 429 is a legitimate answer of the busy/throttle paths; everything else >= 400 is a problem.
        if (response.status() >= 400 && response.status() !== 429) {
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

    await page.getByRole('button', { name: 'Reveal key' }).click();
    apiKey = (await page.getByTestId('api-key').textContent()).trim();
    expect(apiKey).toMatch(/^phc_/);

    // Five signups over three days, two purchases: through the real capture endpoint.
    const day = 86_400_000;
    const events = [
        ...[0, 0, 1, 2, 2].map((ago, i) => ({ event: eventName, distinct_id: `u${i % 3}`, properties: { $browser: i % 2 ? 'Firefox' : 'Chrome' }, timestamp: new Date(Date.now() - ago * day - 60_000).toISOString() })),
        ...[1, 2].map((ago) => ({ event: `${marker}_purchase`, distinct_id: 'u0', properties: {}, timestamp: new Date(Date.now() - ago * day - 60_000).toISOString() })),
    ];
    const capture = await page.request.post('/batch/', { data: { api_key: apiKey, batch: events } });
    expect(capture.ok()).toBeTruthy();

    // Wait until ClickHouse has them. Each poll uses a DIFFERENT prefix of the marker: an answer is cached per search
    // text for a couple of minutes, and an early empty answer must not hide the events from the later polls.
    let seen = false;
    for (let length = marker.length; length >= 3 && !seen; length--) {
        const answer = await page.request.get(`/api/projects/${projectId}/insights/events?q=${marker.slice(0, length)}`);
        seen = answer.ok() && (await answer.json()).events.some((item) => item.name === eventName);
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

test('the picker works by keyboard alone', async () => {
    await page.goto(`/projects/${projectId}/insights/trends`);
    const input = page.getByRole('combobox', { name: /Events/ });

    await expect(page.getByTestId('state-idle')).toBeVisible();
    await input.focus();
    await expect(input).toHaveAttribute('aria-expanded', 'true');

    await page.keyboard.type(marker);
    const options = page.getByRole('option');
    await expect(options.first()).toContainText(eventName);

    await page.keyboard.press('ArrowDown');
    await expect(input).toHaveAttribute('aria-activedescendant', /option-0$/);
    await page.keyboard.press('End');
    await expect(input).toHaveAttribute('aria-activedescendant', /option-\d+$/);
    await page.keyboard.press('Home');
    await expect(input).toHaveAttribute('aria-activedescendant', /option-0$/);

    await page.keyboard.press('Escape');
    await expect(input).toHaveAttribute('aria-expanded', 'false');

    await page.keyboard.press('ArrowDown');
    await expect(input).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(page.getByRole('list', { name: 'Selected events' })).toContainText(eventName);
    await expect(page.getByRole('status').filter({ hasText: 'added' })).toHaveCount(1);

    // Backspace in the empty input removes the last chip again
    await page.keyboard.press('Backspace');
    await expect(page.getByRole('list', { name: 'Selected events' })).toHaveCount(0);
    await expect(page.getByTestId('state-idle')).toBeVisible();
});

test('shows the chart, toggles bar and the table, and is accessible', async () => {
    await page.goto(`/projects/${projectId}/insights/trends`);
    const input = page.getByRole('combobox', { name: /Events/ });

    await input.fill(eventName);
    await input.press('Enter'); // a typed name is added as it is
    await expect(page.getByRole('list', { name: 'Selected events' })).toContainText(eventName);

    const chart = page.getByTestId('chart');
    await expect(chart.locator('svg')).toBeVisible();
    await expect(page.getByTestId('chart-total')).toContainText('Total: 5');
    await expect(chart).toHaveAttribute('aria-label', /Line chart of events per day, 1 series/);

    const bar = page.getByRole('button', { name: 'Bar' });
    await bar.click();
    await expect(bar).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: 'Line' })).toHaveAttribute('aria-pressed', 'false');
    await expect(chart).toHaveAttribute('aria-label', /Bar chart/);

    await page.getByText('Show data as a table').click();
    const table = page.getByRole('table');
    await expect(table).toBeVisible();
    await expect(table.getByRole('columnheader').nth(1)).toHaveText(eventName);
    await expect(table.getByRole('row', { name: /Sum/ })).toContainText('5');

    // the URL is shareable: it carries the definition
    await expect(page).toHaveURL(new RegExp(`e=${eventName}`));

    expect(await seriousViolations()).toEqual([]);

    // and with the picker's list open
    await input.focus();
    await expect(page.getByRole('listbox')).toBeVisible();
    expect(await seriousViolations()).toEqual([]);
});

test('had no console errors and no failed requests', async () => {
    expect(problems).toEqual([]);
});
