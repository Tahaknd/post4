import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// One flow of the Funnel page against a real stack: sign in, create a throwaway project, send events of five people through the
// real capture endpoint, build a funnel with the keyboard, run it and check the numbers in the table and the bars, the strict
// order, the validation messages, a server 422, the cancel button, the shareable URL, a failed run, the empty state, dark mode
// and axe. The project is deleted at the end. Credentials come from E2E_EMAIL / E2E_PASSWORD (a throwaway administrator).
//
// The five people (all start two days ago, one hour apart between steps):
//   u0  signup, activate, purchase        reaches 3 steps (ordered and strict)
//   u1  signup, activate                  reaches 2
//   u2  signup, noise, activate           reaches 2 ordered, 1 strict (noise interrupts the chain)
//   u3  signup                            reaches 1
//   u4  signup                            reaches 1
// Ordered: 5 / 3 / 1 (from first 100%, 60%, 20%; from previous -, 60%, 33.33%). Strict: 5 / 2 / 1.

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const marker = `e2e${Date.now().toString(36)}`;
const signup = `${marker}_signup`;
const activate = `${marker}_activate`;
const purchase = `${marker}_purchase`;
const noise = `${marker}_noise`;
const never = `${marker}_never`;
const projectName = `E2E ${marker}`;

test.describe.configure({ mode: 'serial' });

let page;
let projectId;
let apiKey;
let funnelUrl;
const problems = [];
// Set while a test provokes errors on purpose (an intercepted answer, an aborted chunk): those are not problems.
let provoking = false;

test.beforeAll(async ({ browser }) => {
    if (!email || !password) {
        throw new Error('Set E2E_EMAIL and E2E_PASSWORD to a throwaway administrator (see scripts/e2e.sh).');
    }

    // axe needs a page of its own context (browser.newContext()), not browser.newPage().
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
    // A request the page aborted itself (a superseded search or run) is not a failure.
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
    funnelUrl = `/projects/${projectId}/insights/funnel`;

    await page.getByRole('button', { name: 'Reveal key' }).click();
    apiKey = (await page.getByTestId('api-key').textContent()).trim();
    expect(apiKey).toMatch(/^phc_/);

    const start = Date.now() - 2 * 86_400_000;
    const hour = 3_600_000;
    const at = (person, offsetHours) => new Date(start + offsetHours * hour + person * 1000).toISOString();
    const events = [
        ...[0, 1, 2, 3, 4].map((person) => ({ event: signup, distinct_id: `u${person}`, properties: { plan: person < 3 ? 'pro' : 'free' }, timestamp: at(person, 0) })),
        { event: activate, distinct_id: 'u0', properties: {}, timestamp: at(0, 1) },
        { event: activate, distinct_id: 'u1', properties: {}, timestamp: at(1, 1) },
        { event: noise, distinct_id: 'u2', properties: {}, timestamp: at(2, 0.5) },
        { event: activate, distinct_id: 'u2', properties: {}, timestamp: at(2, 1) },
        { event: purchase, distinct_id: 'u0', properties: {}, timestamp: at(0, 2) },
    ];
    const capture = await page.request.post('/batch/', { data: { api_key: apiKey, batch: events } });
    expect(capture.ok()).toBeTruthy();

    // Wait until ClickHouse has them. An answer of the event list is cached per search text for a couple of minutes, so an early
    // empty answer must neither be asked again nor be one the tests type later: each poll searches for a different TAIL of the
    // marker (the search matches anywhere in the name), and the tests only ever type the whole marker or more.
    let seen = false;
    for (let from = 1; from < marker.length - 2 && !seen; from++) {
        const answer = await page.request.get(`/api/projects/${projectId}/insights/events?q=${marker.slice(from)}`);
        seen = answer.ok() && (await answer.json()).events.some((item) => item.name === purchase);
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

const combobox = (n) => page.getByRole('combobox', { name: `Step ${n} event` });
const runButton = () => page.getByRole('button', { name: 'Run funnel' });
const table = () => page.getByTestId('funnel-table');
const bars = () => page.getByTestId('chart').locator('svg');

/** Types an event name into the step's field and closes the suggestions again (the typed text is the value). */
async function setStep(n, name) {
    await combobox(n).fill(name);
    await page.keyboard.press('Escape');
    await expect(combobox(n)).toHaveValue(name);
}

async function rowCells(name) {
    return (await table().getByRole('row', { name: new RegExp(name) }).getByRole('cell').allTextContents()).map((text) => text.trim());
}

async function fillThreeSteps() {
    await setStep(1, signup);
    await setStep(2, activate);
    await page.getByRole('button', { name: 'Add step' }).click();
    await expect(combobox(3)).toBeFocused();
    await setStep(3, purchase);
}

test('shows the Funnel link in the project navigation and an idle page that cannot run yet', async () => {
    await page.goto(`/projects/${projectId}`);
    await page.getByRole('link', { name: 'Funnel', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${funnelUrl}$`));
    await expect(page.getByRole('heading', { name: 'Funnel', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Funnel' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('state-idle')).toBeVisible();
    await expect(page.getByRole('list', { name: 'Funnel steps' }).getByRole('listitem')).toHaveCount(2);
    await expect(runButton()).toHaveAttribute('aria-disabled', 'true');
});

test('a refused Run names what is missing and moves focus there; then the funnel is built and run by keyboard', async () => {
    await page.goto(funnelUrl);

    // nothing is reported for fields nobody has left, then a Run that is refused reports them all and focuses the first
    await expect(page.locator('[role="alert"]')).toHaveCount(0);
    await runButton().click({ force: true }); // aria-disabled: the click is still delivered (it is what reveals the messages)
    await expect(page.getByText('Choose an event for step 1.')).toBeVisible();
    await expect(page.getByText('Choose an event for step 2.')).toBeVisible();
    await expect(page.getByTestId('run-hint')).toContainText('Fix the highlighted fields');
    await expect(combobox(1)).toBeFocused();
    await expect(combobox(1)).toHaveAttribute('aria-invalid', 'true');
    await expect(combobox(1)).toHaveAccessibleDescription('Choose an event for step 1.');

    // the suggestions come from the project's events and work by arrow keys alone
    await page.keyboard.type(marker);
    const options = page.getByRole('listbox').getByRole('option');
    await expect(options.first()).toContainText(marker);
    await expect(combobox(1)).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('ArrowDown');
    await expect(combobox(1)).toHaveAttribute('aria-activedescendant', /option-0$/);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowUp');
    await page.keyboard.press('Escape');
    await expect(combobox(1)).toHaveAttribute('aria-expanded', 'false');
    await combobox(1).fill(`${marker}_sign`);
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('listbox').getByRole('option', { name: new RegExp(signup) })).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(combobox(1)).toHaveValue(signup);
    await expect(page.getByText('Choose an event for step 1.')).toHaveCount(0);

    await page.keyboard.press('Tab');
    await combobox(2).fill(activate);
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Add step' }).click();
    await expect(combobox(3)).toBeFocused();
    await page.keyboard.type(purchase);
    await page.keyboard.press('Escape');

    await runButton().focus();
    await page.keyboard.press('Enter');

    await expect(bars()).toBeVisible();
    await expect(page.getByTestId('chart')).toHaveAttribute('aria-label', new RegExp(`Horizontal bar chart of a funnel with 3 steps\\. 1\\. ${signup}: 5; 2\\. ${activate}: 3; 3\\. ${purchase}: 1\\.`));
    // the bars are drawn: one rect per step carries the series colour from the design tokens
    await expect(bars().locator('path[fill], rect[fill]').first()).toBeAttached();

    expect(await rowCells(signup)).toEqual(['5', '0', '—Not applicable', '100%']);
    expect(await rowCells(activate)).toEqual(['3', '2', '60%', '60%']);
    expect(await rowCells(purchase)).toEqual(['1', '2', '33.33%', '20%']);

    const summary = page.getByTestId('funnel-summary');
    await expect(summary).toContainText('Entrants5');
    await expect(summary).toContainText('Converted1');
    await expect(summary).toContainText('Overall conversion20%');
    await expect(summary).toContainText('Window2 weeks');
    await expect(page.getByTestId('status')).toHaveText('Funnel updated: 5 people started, 1 person completed all 3 steps, overall conversion 20%.');

    // the URL carries the funnel
    await expect(page).toHaveURL(new RegExp('s1=%5B%22' + signup));
});

test('reorders, removes and adds steps with the keyboard, keeps focus, and says what the limits are', async () => {
    await page.goto(funnelUrl);
    await fillThreeSteps();

    const moveDown1 = page.getByRole('button', { name: 'Move down step 1' });
    const moveUp1 = page.getByRole('button', { name: 'Move up step 1' });
    await expect(moveUp1).toHaveAttribute('aria-disabled', 'true');

    await moveDown1.focus();
    await page.keyboard.press('Enter');
    await expect(combobox(1)).toHaveValue(activate);
    await expect(combobox(2)).toHaveValue(signup);
    await expect(combobox(3)).toHaveValue(purchase);
    await expect(page.getByRole('status').filter({ hasText: 'moved down to position 2 of 3' })).toHaveCount(1);
    // focus stayed with the step that moved (it is step 2 now), on the same button
    await expect(page.getByRole('button', { name: 'Move down step 2' })).toBeFocused();

    await page.keyboard.press('Enter');
    await expect(combobox(2)).toHaveValue(purchase);
    await expect(combobox(3)).toHaveValue(signup);
    // it reached the end: focus moved to the twin button, which is still enabled
    await expect(page.getByRole('button', { name: 'Move down step 3' })).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByRole('button', { name: 'Move up step 3' })).toBeFocused();
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await expect(combobox(1)).toHaveValue(signup);
    await expect(combobox(2)).toHaveValue(activate);

    // the filters travel with their step
    await page.getByRole('button', { name: 'Add filter to step 2' }).click();
    await page.getByLabel('Step 2 filter 1 property key').fill('plan');
    await page.getByLabel('Step 2 filter 1 value').fill('pro');
    await page.getByRole('button', { name: 'Move up step 2' }).focus();
    await page.keyboard.press('Enter');
    await expect(combobox(1)).toHaveValue(activate);
    await expect(page.getByLabel('Step 1 filter 1 property key')).toHaveValue('plan');
    await page.getByRole('button', { name: 'Move down step 1' }).focus();
    await page.keyboard.press('Enter');
    await page.getByRole('button', { name: 'Remove filter 1' }).or(page.getByRole('button', { name: 'Remove step 2 filter 1' })).first().click();

    // removing hands focus to the neighbour; the minimum is two steps
    await page.getByRole('button', { name: 'Remove step 3' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('list', { name: 'Funnel steps' }).getByRole('listitem')).toHaveCount(2);
    await expect(combobox(2)).toBeFocused();
    await expect(page.getByRole('button', { name: 'Remove step 1' })).toHaveAttribute('aria-disabled', 'true');
    await page.getByRole('button', { name: 'Remove step 1' }).click({ force: true });
    await expect(page.getByRole('list', { name: 'Funnel steps' }).getByRole('listitem')).toHaveCount(2);

    // the maximum is eight
    const add = page.getByRole('button', { name: 'Add step' });
    for (let i = 0; i < 6; i++) {
        await add.click();
    }
    await expect(page.getByRole('list', { name: 'Funnel steps' }).getByRole('listitem')).toHaveCount(8);
    await expect(add).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByText('8 of 8 steps. At most 8.')).toBeVisible();
    await add.click({ force: true });
    await expect(page.getByRole('list', { name: 'Funnel steps' }).getByRole('listitem')).toHaveCount(8);
});

test('a strict funnel counts less when another event interrupts, and the help says SDK events count too', async () => {
    await page.goto(funnelUrl);
    await fillThreeSteps();
    await expect(page.getByText('including the events the SDK records by itself')).toHaveCount(0);

    await page.getByLabel('Step order').selectOption('strict_order');
    await expect(page.getByText(/including the events the SDK records by itself, such as \$pageview and \$identify/)).toBeVisible();
    await expect(page.getByLabel('Step order')).toHaveAccessibleDescription(/\$pageview/);

    await runButton().click();
    await expect(bars()).toBeVisible();
    expect(await rowCells(signup)).toEqual(['5', '0', '—Not applicable', '100%']);
    expect(await rowCells(activate)).toEqual(['2', '3', '40%', '40%']);
    expect(await rowCells(purchase)).toEqual(['1', '1', '50%', '20%']);
    await expect(page.getByTestId('funnel-order')).toHaveText('Order: Strict order');
    await expect(page).toHaveURL(/o=strict_order/);
});

test('filters on a step narrow who counts, and a changed form marks the result as out of date', async () => {
    await page.goto(funnelUrl);
    await fillThreeSteps();
    await page.getByRole('button', { name: 'Add filter to step 1' }).click();
    await page.getByLabel('Step 1 filter 1 property key').fill('plan');
    await page.getByLabel('Step 1 filter 1 value').fill('pro');
    await runButton().click();
    await expect(bars()).toBeVisible();
    // only u0, u1, u2 are on the pro plan
    expect(await rowCells(signup)).toEqual(['3', '0', '—Not applicable', '100%']);
    expect(await rowCells(activate)).toEqual(['3', '0', '100%', '100%']);
    expect(await rowCells(purchase)).toEqual(['1', '2', '33.33%', '33.33%']);
    await expect(page.getByTestId('status-stale')).toHaveCount(0);

    await page.getByLabel('Step order').selectOption('strict_order');
    await expect(page.getByTestId('status-stale')).toContainText('Run the funnel again');
    await expect(bars()).toBeVisible();
    await page.getByLabel('Step order').selectOption('ordered');
    await expect(page.getByTestId('status-stale')).toHaveCount(0);
});

test('says what is wrong with the window and the dates after the field is left, and never runs an invalid form', async () => {
    await page.goto(funnelUrl);
    await fillThreeSteps();
    let posts = 0;
    await page.route('**/api/projects/*/insights/funnel', (route) => {
        posts++;

        return route.continue();
    });

    const length = page.getByLabel('Window length');
    await length.fill('0');
    await expect(page.getByText('Enter the window as a whole number from 1 to 100000.')).toHaveCount(0);
    await page.keyboard.press('Tab');
    await expect(page.getByText('Enter the window as a whole number from 1 to 100000.')).toBeVisible();
    await expect(length).toHaveAttribute('aria-invalid', 'true');
    await expect(runButton()).toHaveAttribute('aria-disabled', 'true');

    await length.fill('91');
    await page.getByLabel('Window unit').selectOption('day');
    await page.getByLabel('Step order').focus();
    await expect(page.getByText('The window must be between 1 minute and 90 days.')).toBeVisible();

    await runButton().click({ force: true });
    await expect(length).toBeFocused();
    expect(posts, 'the form is invalid: nothing is sent').toBe(0);

    await length.fill('1.5');
    await expect(page.getByText('Enter the window as a whole number from 1 to 100000.')).toBeVisible();
    await length.fill('3');
    await expect(page.getByText(/whole number from 1/)).toHaveCount(0);

    // dates
    await page.getByLabel('Date range preset').selectOption('custom');
    await page.getByLabel('From').fill('2026-03-08');
    await page.getByLabel('To').fill('2026-03-01');
    await page.getByLabel('To').blur();
    await expect(page.getByText('The end date must not be before the start date.')).toBeVisible();
    await expect(runButton()).toHaveAttribute('aria-disabled', 'true');
    await page.getByLabel('Date range preset').selectOption('last_30_days');
    await expect(page.getByText('The end date must not be before the start date.')).toHaveCount(0);

    await page.unroute('**/api/projects/*/insights/funnel');
});

test('shows the server messages of a 422 on the controls they belong to, and accepts a fixed form afterwards', async () => {
    provoking = true;
    try {
        await page.goto(funnelUrl);
        await fillThreeSteps();
        await page.route('**/api/projects/*/insights/funnel', (route) => route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({
                message: 'The given data was invalid.',
                error: { kind: 'validation', message: 'The given data was invalid.', retryable: false, retry_after_seconds: null },
                errors: { 'steps.1.event': ['Each step needs an event name of 1 to 200 characters of text.'], 'window.unit': ['The window unit must be minute, hour, day or week.'], definition: ['This combination is not allowed.'] },
            }),
        }));

        await runButton().click();
        await expect(page.getByText('Each step needs an event name of 1 to 200 characters of text.')).toBeVisible();
        await expect(combobox(2)).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByText('The window unit must be minute, hour, day or week.')).toBeVisible();
        await expect(page.getByTestId('general-error')).toContainText('This combination is not allowed.');
        expect(await seriousViolations()).toEqual([]);

        await page.unroute('**/api/projects/*/insights/funnel');
    } finally {
        provoking = false;
    }
    await runButton().click();
    await expect(bars()).toBeVisible();
    await expect(page.getByTestId('general-error')).toHaveCount(0);
});

test('cancels a run that is queued or running: the run is deleted, nothing is left on screen, focus returns to Run', async () => {
    await page.goto(funnelUrl);
    await fillThreeSteps();

    const runId = 'c'.repeat(32);
    const deleted = [];
    await page.route('**/api/projects/*/insights/funnel', (route) => route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ status: 'queued', run_id: runId, poll_url: `/api/projects/${projectId}/insights/runs/${runId}` }) }));
    let polls = 0;
    await page.route('**/api/projects/*/insights/runs/*', (route) => {
        if (route.request().method() === 'DELETE') {
            deleted.push(route.request().url());

            return route.fulfill({ status: 204 });
        }
        polls++;

        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: polls < 2 ? 'queued' : 'running', run_id: runId }) });
    });

    await runButton().click();
    await expect(runButton()).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByRole('region', { name: 'Results' })).toHaveAttribute('aria-busy', 'true');
    await expect(page.getByTestId('status-visible')).toHaveText('Waiting for a free slot…');
    await expect(page.getByTestId('status-visible')).toHaveText('Running the funnel…');
    await expect(page.getByTestId('status')).toHaveText('Running the funnel…');

    await page.getByRole('button', { name: 'Cancel run' }).click();
    await expect(page.getByTestId('state-cancelled')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cancel run' })).toHaveCount(0);
    await expect(runButton()).toBeFocused();
    await expect(runButton()).not.toHaveAttribute('aria-disabled', 'true');
    expect(deleted).toEqual([expect.stringContaining(`/insights/runs/${runId}`)]);
    await expect(page.getByTestId('status')).toHaveText('The run was cancelled.');
    const seen = polls;
    await page.waitForTimeout(2500);
    expect(polls, 'the run is not polled any more').toBe(seen);
    expect(await seriousViolations()).toEqual([]);

    await page.unroute('**/api/projects/*/insights/funnel');
    await page.unroute('**/api/projects/*/insights/runs/*');
    await page.getByRole('button', { name: 'Run again' }).click();
    await expect(bars()).toBeVisible();
});

test('shows a failed run with the server sentence and what to try, and a query that was too large', async () => {
    await page.goto(funnelUrl);
    await fillThreeSteps();

    const runId = 'd'.repeat(32);
    await page.route('**/api/projects/*/insights/funnel', (route) => route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ status: 'queued', run_id: runId, poll_url: `/api/projects/${projectId}/insights/runs/${runId}` }) }));
    await page.route('**/api/projects/*/insights/runs/*', (route) => route.request().method() === 'DELETE'
        ? route.fulfill({ status: 204 })
        : route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'failed', run_id: runId, error: { kind: 'memory_limit', message: 'The query is too large. Try a shorter date range, fewer series or a simpler breakdown.', retryable: false, retry_after_seconds: null } }) }));

    await runButton().click();
    const failed = page.getByTestId('state-failed');
    await expect(failed).toContainText('The query is too large.');
    await expect(page.getByTestId('failure-help')).toContainText('Try a shorter date range or window, add a filter to the first step');
    await expect(failed.getByRole('button', { name: 'Try again' })).toHaveCount(0);
    expect(await seriousViolations()).toEqual([]);

    await page.unroute('**/api/projects/*/insights/funnel');
    await page.unroute('**/api/projects/*/insights/runs/*');
    await runButton().click();
    await expect(bars()).toBeVisible();
});

test('says so when nobody started the funnel, and shows an unknown rate as a dash', async () => {
    await page.goto(funnelUrl);
    await setStep(1, never);
    await setStep(2, signup);
    await runButton().click();

    const empty = page.getByTestId('state-empty');
    await expect(empty).toContainText('Nobody started this funnel in this range');
    await expect(empty).toContainText(never);
    await expect(page.getByTestId('chart')).toHaveCount(0);
    await expect(page.getByTestId('status')).toHaveText('Nobody started this funnel in this range.');
    expect(await seriousViolations()).toEqual([]);

    // step 1 done by someone, step 2 by nobody who did step 1: a rate of 0, not a dash; and a funnel where only step 1 is empty
    // has no rate at all (the server answers null): shown as an em dash
    await page.route('**/api/projects/*/insights/funnel', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
            status: 'done',
            run_id: null,
            result: {
                steps: [
                    { index: 1, event: 'a', count: 4, dropped_off: 0, conversion_from_previous: null, conversion_from_first: 1 },
                    { index: 2, event: 'b', count: 0, dropped_off: 4, conversion_from_previous: 0, conversion_from_first: 0 },
                    { index: 3, event: 'c', count: 0, dropped_off: 0, conversion_from_previous: null, conversion_from_first: 0 },
                ],
                entrants: 4, converted: 0, conversion_rate: 0, window_seconds: 86400, order: 'ordered',
            },
        }),
    }));
    await page.getByRole('button', { name: 'Add step' }).click();
    await setStep(3, 'c');
    await runButton().click();
    await expect(bars()).toBeVisible();
    expect(await rowCells('^2\\.\\s*b')).toEqual(['0', '4', '0%', '0%']);
    expect(await rowCells('^3\\.\\s*c')).toEqual(['0', '0', '—No rate: nobody reached the previous step', '0%']);
    await expect(page.getByTestId('funnel-summary')).toContainText('Window1 day');
    await page.unroute('**/api/projects/*/insights/funnel');
});

test('keeps the funnel in the URL: a link runs it, reload and back restore it, and a hostile link degrades safely', async () => {
    const steps = [[signup, []], [activate, [['plan', 'equals', 'pro']]], [purchase, []]].map((step, index) => `s${index + 1}=${encodeURIComponent(JSON.stringify(step))}`).join('&');
    await page.goto(`${funnelUrl}?${steps}&wv=3&wu=day&o=strict_order&p=last_90_days`);

    await expect(bars()).toBeVisible();
    await expect(combobox(1)).toHaveValue(signup);
    await expect(combobox(2)).toHaveValue(activate);
    await expect(combobox(3)).toHaveValue(purchase);
    await expect(page.getByLabel('Step 2 filter 1 property key')).toHaveValue('plan');
    await expect(page.getByLabel('Window length')).toHaveValue('3');
    await expect(page.getByLabel('Window unit')).toHaveValue('day');
    await expect(page.getByLabel('Step order')).toHaveValue('strict_order');
    await expect(page.getByLabel('Date range preset')).toHaveValue('last_90_days');
    await expect(page.getByTestId('funnel-summary')).toContainText('Window3 days');

    await page.reload();
    await expect(combobox(2)).toHaveValue(activate);
    await expect(bars()).toBeVisible();

    await page.getByRole('link', { name: projectName }).first().click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}$`));
    await page.goBack();
    await expect(page).toHaveURL(/s3=/);
    await expect(combobox(3)).toHaveValue(purchase);
    await expect(bars()).toBeVisible();

    // a link made of nonsense gives the defaults, no error and nothing is run
    await page.goto(`${funnelUrl}?s1=garbage&s2=%5B1%5D&wv=abc&wu=century&o=sideways&p=forever&from=2026-02-30&to=x&junk=1`);
    await expect(page.getByTestId('state-idle')).toBeVisible();
    await expect(page.getByRole('list', { name: 'Funnel steps' }).getByRole('listitem')).toHaveCount(2);
    await expect(page.getByLabel('Window length')).toHaveValue('2');
    await expect(page.getByLabel('Window unit')).toHaveValue('week');
    await expect(page.getByLabel('Step order')).toHaveValue('ordered');
    await expect(page.getByLabel('Date range preset')).toHaveValue('last_30_days');
    await expect(page.locator('[role="alert"]')).toHaveCount(0);

    // an incomplete funnel in a link is filled in, not run
    await page.goto(`${funnelUrl}?s1=${encodeURIComponent(JSON.stringify([signup, []]))}`);
    await expect(combobox(1)).toHaveValue(signup);
    await expect(combobox(2)).toHaveValue('');
    await expect(page.getByTestId('state-idle')).toBeVisible();
});

test('is accessible: with the result, with a suggestion list open, in dark mode by media query and by data-theme', async () => {
    await page.goto(funnelUrl);
    await fillThreeSteps();
    await page.getByRole('button', { name: 'Add filter to step 1' }).click();
    await runButton().click();
    await expect(bars()).toBeVisible();
    expect(await seriousViolations()).toEqual([]);

    await combobox(2).focus();
    await expect(combobox(2)).toHaveAttribute('aria-expanded', 'false');
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('listbox').getByRole('option').first()).toBeVisible();
    expect(await seriousViolations()).toEqual([]);
    await page.keyboard.press('Escape');

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark');
    await expect(bars()).toBeVisible();
    expect(await seriousViolations()).toEqual([]);

    // the bars take their colour from the tokens of the scheme: --chart-1 differs between light and dark
    const dark = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--chart-1').trim());
    await expect.poll(() => bars().evaluate((svg) => [...svg.querySelectorAll('path, rect')].map((node) => node.getAttribute('fill')).filter(Boolean))).toContain(dark);

    await page.emulateMedia({ colorScheme: 'light' });
    await page.evaluate(() => (document.documentElement.dataset.theme = 'dark'));
    expect(await seriousViolations()).toEqual([]);
    await page.evaluate(() => delete document.documentElement.dataset.theme);
});

test('offers a retry when the chart code cannot be loaded, and the table still has the numbers', async () => {
    provoking = true;
    try {
        await page.route('**/build/assets/FunnelCanvas-*.js', (route) => route.abort());
        await page.goto(funnelUrl);
        await fillThreeSteps();
        await runButton().click();

        const alert = page.getByTestId('chart-error');
        await expect(alert).toBeVisible();
        await expect(alert).toHaveAttribute('role', 'alert');
        expect(await rowCells(activate)).toEqual(['3', '2', '60%', '60%']);

        await page.unroute('**/build/assets/FunnelCanvas-*.js');
        await alert.getByRole('button', { name: 'Try again' }).click();
        await expect(bars()).toBeVisible();
    } finally {
        provoking = false;
    }
});

test('works on a narrow screen: the steps stack and nothing scrolls sideways', async () => {
    await page.setViewportSize({ width: 375, height: 800 });
    try {
        await page.goto(funnelUrl);
        await fillThreeSteps();
        await runButton().click();
        await expect(bars()).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(0);
        expect(await seriousViolations()).toEqual([]);
    } finally {
        await page.setViewportSize({ width: 1280, height: 720 });
    }
});

test('had no console errors and no failed requests', async () => {
    expect(problems).toEqual([]);
});
