import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// One flow of the Retention page against a real stack: sign in, create a throwaway project (time zone UTC, the default), send the
// events of seven people through the real capture endpoint with controlled timestamps, fill in the form by keyboard, run it and check
// every cell of the table: counts, shares, the Average row and the periods that have not started yet. Then the cohort filter, the
// recurring type, the same-event shortcut, the validation messages, a server 422, the cancel button, the shareable URL, a failed run,
// the empty state, wide tables and labels (answers made up in the browser), dark mode, axe, the contrast of the heat map as the
// browser really draws it, and a narrow screen. The project is deleted at the end. Credentials come from E2E_EMAIL / E2E_PASSWORD
// (a throwaway administrator).
//
// Days are UTC days, counted back from today: D is today, so the range is D-3 .. D-1 (three cohorts, `to` exclusive of today) and
// three follow-up periods. Every event happens at 12:00 UTC of its day, never today (a test run just after midnight must not
// depend on "now"). `visit` is the return event, `signup` the cohort event; plan is pro for u1 u2 u5 u7.
//
//   D-3  signup: u1 u2 u3 u4
//   D-2  signup: u1 (again) u5 u6      visit: u1 u2
//   D-1  signup: u7                    visit: u1 u3 u5
//   (u1: also visits D-1; u3 visits only D-1.) Nobody does anything today.
//
// first_time, signup -> visit, day:
//   D-3  size 4  [4, 2, 2, 0]          D-2  size 2  [2, 1, 0, —]          D-1  size 1  [1, 0, —, —]
//   Average  100% (3) | 42.86% (3) | 33.33% (2) | 0% (1)
// (the cell of D-2 in day 2 is today: it has started, nobody came; D-3 in day 3 is today as well.)

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const marker = `e2e${Date.now().toString(36)}`;
const signup = `${marker}_signup`;
const visit = `${marker}_visit`;
const never = `${marker}_never`;
const projectName = `E2E ${marker}`;

test.describe.configure({ mode: 'serial' });

const DAY = 86_400_000;
const today = Math.floor(Date.now() / DAY) * DAY;
const day = (offset) => new Date(today + offset * DAY).toISOString().slice(0, 10);
const noon = (offset, second) => new Date(today + offset * DAY + 12 * 3_600_000 + second * 1000).toISOString();

let page;
let projectId;
let apiKey;
let retentionUrl;
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
    retentionUrl = `/projects/${projectId}/insights/retention`;
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

    // Wait until ClickHouse has them. An answer of the event list is cached per search text for a couple of minutes, so an early
    // empty answer must neither be asked again nor be one the tests type later: each poll searches for a different TAIL of the
    // marker (the search matches anywhere in the name), and the tests only ever type the whole marker or more.
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

const cohortEvent = () => page.getByRole('combobox', { name: 'Cohort event', exact: true });
const returnEvent = () => page.getByRole('combobox', { name: 'Return event', exact: true });
const runButton = () => page.getByRole('button', { name: 'Run retention' });
const table = () => page.getByTestId('retention-table');
const curve = () => page.getByTestId('chart').locator('svg');
const select = (name) => page.getByLabel(name, { exact: true });
const from = () => page.getByLabel('From', { exact: true });
const to = () => page.getByLabel('To', { exact: true });
const same = () => page.getByRole('checkbox', { name: 'Same event' });

/** Types an event name into a combobox and closes the suggestions again (the typed text is the value). */
async function setEvent(combobox, name) {
    await combobox.fill(name);
    await page.keyboard.press('Escape');
    await expect(combobox).toHaveValue(name);
}

/** The three cohorts D-3 .. D-1, three periods, the days as a custom range. */
async function setDates() {
    await page.getByLabel('Date range preset').selectOption('custom');
    await from().fill(day(-3));
    await to().fill(day(-1));
}

async function setRange() {
    await select('Number of periods').fill('3');
    await setDates();
}

async function fillForm({ target = signup, returning = visit } = {}) {
    await setEvent(cohortEvent(), target);
    if (returning !== null) {
        await setEvent(returnEvent(), returning);
    }
    await setRange();
}

/** The cohort rows as [label, size, [[count, rate, kind], ...]]. */
function rows() {
    return table().evaluate((element) => [...element.querySelectorAll('tr[data-testid="retention-row"]')].map((tr) => [
        tr.querySelector('[data-label]').textContent.trim(),
        tr.querySelector('[data-size]').textContent.trim(),
        [...tr.querySelectorAll('td[data-cell]')].map((td) => [td.querySelector('[data-count]').textContent.trim(), td.querySelector('[data-rate]')?.textContent.trim() ?? '', td.dataset.kind]),
    ]));
}

/** The Average row as [[rate, cohorts], ...]. */
function averages() {
    return table().evaluate((element) => [...element.querySelectorAll('tr[data-testid="retention-average"] td[data-cell]')].map((td) => [td.querySelector('[data-rate]').textContent.trim(), td.querySelector('[data-cohorts]')?.textContent.trim() ?? '']));
}

const cohortLabel = (offset) => new Intl.DateTimeFormat('en', { weekday: 'short', month: 'short', day: 'numeric', ...(new Date(today + offset * DAY).getUTCFullYear() !== new Date(today - 3 * DAY).getUTCFullYear() || new Date(today - DAY).getUTCFullYear() !== new Date(today - 3 * DAY).getUTCFullYear() ? { year: 'numeric' } : {}), timeZone: 'UTC' }).format(new Date(today + offset * DAY));
const labels = [cohortLabel(-3), cohortLabel(-2), cohortLabel(-1)];

const FIRST_TIME = [
    [labels[0], '4', [['4', '100%', 'value'], ['2', '50%', 'value'], ['2', '50%', 'value'], ['0', '0%', 'value']]],
    [labels[1], '2', [['2', '100%', 'value'], ['1', '50%', 'value'], ['0', '0%', 'value'], ['—', '', 'future']]],
    [labels[2], '1', [['1', '100%', 'value'], ['0', '0%', 'value'], ['—', '', 'future'], ['—', '', 'future']]],
];

test('shows the Retention link in the project navigation and an idle page that cannot run yet', async () => {
    await page.goto(`/projects/${projectId}`);
    await page.getByRole('link', { name: 'Open Retention' }).click();
    await expect(page).toHaveURL(new RegExp(`${retentionUrl}$`));
    await expect(page.getByRole('heading', { name: 'Retention', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Retention' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('state-idle')).toBeVisible();
    await expect(runButton()).toHaveAttribute('aria-disabled', 'true');

    // the defaults of the server, in the zone of the project
    await expect(select('Period')).toHaveValue('day');
    await expect(select('Number of periods')).toHaveValue('8');
    await expect(select('Retention type')).toHaveValue('first_time');
    await expect(select('Time zone')).toHaveValue('UTC');
    await expect(page.getByLabel('Date range preset')).toHaveValue('last_30_days');

    // the plain-language help, including what first_time cannot know
    await expect(select('Retention type')).toHaveAccessibleDescription(/look new in the first period they appear/);

    await page.goto(`/projects/${projectId}`);
    await page.getByRole('navigation').getByRole('link', { name: 'Retention' }).click();
    await expect(page).toHaveURL(new RegExp(`${retentionUrl}$`));
});

test('a refused Run names what is missing and moves focus there; then the table is built and run by keyboard', async () => {
    await page.goto(retentionUrl);

    // nothing is reported for fields nobody has left, then a Run that is refused reports them all and focuses the first
    await expect(page.locator('[role="alert"]')).toHaveCount(0);
    await runButton().click({ force: true }); // aria-disabled: the click is still delivered (it is what reveals the messages)
    await expect(page.getByText('Choose the cohort event.')).toBeVisible();
    await expect(page.getByText('Choose the return event.')).toBeVisible();
    await expect(page.getByTestId('run-hint')).toContainText('Fix the highlighted fields');
    await expect(cohortEvent()).toBeFocused();
    await expect(cohortEvent()).toHaveAttribute('aria-invalid', 'true');
    await expect(cohortEvent()).toHaveAccessibleDescription('Choose the cohort event.');

    // the suggestions come from the project's events and work by arrow keys alone
    await page.keyboard.type(marker);
    const options = page.getByRole('listbox').getByRole('option');
    await expect(options.first()).toContainText(marker);
    await expect(cohortEvent()).toHaveAttribute('aria-expanded', 'true');
    await cohortEvent().fill(`${marker}_sign`);
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('listbox').getByRole('option', { name: new RegExp(signup) })).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(cohortEvent()).toHaveValue(signup);
    await expect(page.getByText('Choose the cohort event.')).toHaveCount(0);

    await returnEvent().focus();
    await page.keyboard.type(`${marker}_vis`);
    // the list is the answer to the text typed so far: wait for it, or the arrow keys walk the answer to the text before
    await expect(page.getByRole('listbox').getByRole('option')).toHaveText([new RegExp(visit)]);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(returnEvent()).toHaveValue(visit);
    await setRange();

    await runButton().focus();
    await page.keyboard.press('Enter');

    await expect(table()).toBeVisible();
    expect(await rows()).toEqual(FIRST_TIME);
    expect(await averages()).toEqual([['100%', '3 cohorts'], ['42.86%', '3 cohorts'], ['33.33%', '2 cohorts'], ['0%', '1 cohort']]);
    await expect(table().getByRole('columnheader')).toHaveText(['Cohort', 'People', 'Day 0', 'Day 1', 'Day 2', 'Day 3']);

    // the curve is drawn from the same averages, with the numbers in its accessible name
    await expect(curve()).toBeVisible();
    await expect(page.getByTestId('chart')).toHaveAttribute('aria-label', /Line chart of the average retention, from the cohort day to 3 days later\. Day 0: 100%; Day 1: 42\.86%; Day 2: 33\.33%; Day 3: 0%\./);
    await expect(curve().locator('path[fill], rect[fill]').first()).toBeAttached();

    const summary = page.getByTestId('retention-summary');
    await expect(summary).toContainText('Cohorts3');
    await expect(summary).toContainText('People in cohorts7');
    await expect(summary).toContainText('Average, day 142.86%');
    await expect(summary).toContainText('Retention typeFirst time');
    await expect(page.getByTestId('retention-note')).toHaveText('Periods are whole days in UTC.');
    await expect(page.getByTestId('status')).toHaveText('Retention updated: 3 cohorts, 7 people in them. Average retention in day 1: 42.86%.');

    // a cell says its numbers in words, and a period that has not started yet says so
    const cells = table().getByRole('row', { name: new RegExp(labels[1]) }).getByRole('cell');
    // (cell 0 is the size, then the days 0 to 3)
    await expect(cells.nth(2)).toContainText('1 person of 2, 50%');
    await expect(cells.nth(4)).toContainText('Not yet: this day has not started');
    await expect(cells.nth(4)).toHaveClass(/heat-future/);
    await expect(cells.nth(2)).toHaveClass(/heat-3/);
    await expect(cells.nth(1)).toHaveClass(/heat-6/);
    await expect(cells.nth(3)).not.toHaveClass(/heat-\d/);

    // the URL carries the table
    await expect(page).toHaveURL(new RegExp(`t=%5B%22${signup}`));
    await expect(page).toHaveURL(/n=3/);
    await expect(page).toHaveURL(new RegExp(`from=${day(-3)}&to=${day(-1)}`));
});

test('a filter on the cohort event narrows who is in the cohorts, and a changed form marks the result as out of date', async () => {
    await page.goto(retentionUrl);
    await fillForm();
    await page.getByRole('button', { name: 'Add filter to cohort event' }).click();
    await page.getByLabel('Cohort event filter 1 property key').fill('plan');
    await page.getByLabel('Cohort event filter 1 value').fill('pro');
    await runButton().click();
    await expect(table()).toBeVisible();

    // only u1 u2 (D-3), u5 (D-2) and u7 (D-1) signed up on the pro plan
    expect(await rows()).toEqual([
        [labels[0], '2', [['2', '100%', 'value'], ['2', '100%', 'value'], ['1', '50%', 'value'], ['0', '0%', 'value']]],
        [labels[1], '1', [['1', '100%', 'value'], ['1', '100%', 'value'], ['0', '0%', 'value'], ['—', '', 'future']]],
        [labels[2], '1', [['1', '100%', 'value'], ['0', '0%', 'value'], ['—', '', 'future'], ['—', '', 'future']]],
    ]);
    expect(await averages()).toEqual([['100%', '3 cohorts'], ['75%', '3 cohorts'], ['33.33%', '2 cohorts'], ['0%', '1 cohort']]);
    await expect(page.getByTestId('status-stale')).toHaveCount(0);

    await select('Period').selectOption('week');
    await expect(page.getByTestId('status-stale')).toContainText('Run the retention table again');
    await expect(table()).toBeVisible();
    await select('Period').selectOption('day');
    await expect(page.getByTestId('status-stale')).toHaveCount(0);

    // a filter on the return event
    await page.getByRole('button', { name: 'Remove cohort event filter 1' }).click();
    await page.getByRole('button', { name: 'Add filter to return event' }).click();
    await page.getByLabel('Return event filter 1 property key').fill('plan');
    await page.getByLabel('Return event filter 1 value').fill('nobody');
    await runButton().click();
    await expect.poll(async () => (await rows())[0]?.[2][1][0]).toBe('0');
    expect((await rows())[0][2].map((cell) => cell[0])).toEqual(['4', '0', '0', '0']);
});

test('recurring retention counts a person in every period they did the cohort event, and says so', async () => {
    await page.goto(retentionUrl);
    await fillForm();
    await select('Retention type').selectOption('recurring');
    await expect(select('Retention type')).toHaveAccessibleDescription(/more than the number of people/);
    await runButton().click();
    await expect(table()).toBeVisible();

    // u1 signed up on D-3 and again on D-2: two cohorts
    expect(await rows()).toEqual([
        [labels[0], '4', [['4', '100%', 'value'], ['2', '50%', 'value'], ['2', '50%', 'value'], ['0', '0%', 'value']]],
        [labels[1], '3', [['3', '100%', 'value'], ['2', '66.67%', 'value'], ['0', '0%', 'value'], ['—', '', 'future']]],
        [labels[2], '1', [['1', '100%', 'value'], ['0', '0%', 'value'], ['—', '', 'future'], ['—', '', 'future']]],
    ]);
    expect(await averages()).toEqual([['100%', '3 cohorts'], ['50%', '3 cohorts'], ['28.57%', '2 cohorts'], ['0%', '1 cohort']]);
    await expect(page.getByTestId('retention-summary')).toContainText('Cohort memberships8');
    await expect(page.getByTestId('retention-summary')).toContainText('Retention typeRecurring');
    await expect(page).toHaveURL(/rt=recurring/);
});

test('the same event shortcut mirrors the cohort event, shows an empty cohort as a dash, and copies the event back when it is turned off', async () => {
    await page.goto(retentionUrl);
    await setEvent(cohortEvent(), visit);
    await setRange();
    await same().check();

    // there is no second event field to fill in, and the form says why
    await expect(returnEvent()).toHaveCount(0);
    await expect(page.getByTestId('same-note')).toContainText('The same event as the cohort event');
    await expect(same()).toHaveAccessibleDescription('People come back by doing the cohort event again.');
    await runButton().click();
    await expect(table()).toBeVisible();

    // visits: u1 u2 on D-2, u1 u3 u5 on D-1; first_time puts u1 in D-2 only. D-3 has nobody: counts of 0 and no share.
    expect(await rows()).toEqual([
        [labels[0], '0', [['0', '—', 'empty'], ['0', '—', 'empty'], ['0', '—', 'empty'], ['0', '—', 'empty']]],
        [labels[1], '2', [['2', '100%', 'value'], ['1', '50%', 'value'], ['0', '0%', 'value'], ['—', '', 'future']]],
        [labels[2], '2', [['2', '100%', 'value'], ['0', '0%', 'value'], ['—', '', 'future'], ['—', '', 'future']]],
    ]);
    expect(await averages()).toEqual([['100%', '2 cohorts'], ['25%', '2 cohorts'], ['0%', '1 cohort'], ['—', '']]);
    await expect(table().getByRole('row', { name: new RegExp(labels[0]) }).getByRole('cell').nth(2)).toContainText('0 people, no rate: nobody is in this cohort');
    await expect(table().getByTestId('retention-average').getByRole('cell').nth(4)).toContainText('No average: no cohort with people has reached this period');
    await expect(page).toHaveURL(/same=1/);
    await expect(page).not.toHaveURL(/[?&]r=/);

    // turned off, the return event starts as a copy of the cohort event, ready to be changed
    await same().uncheck();
    await expect(returnEvent()).toHaveValue(visit);
    await expect(page.getByTestId('status-stale')).toHaveCount(0);
    await setEvent(returnEvent(), signup);
    await expect(page.getByTestId('status-stale')).toContainText('Run the retention table again');
});

test('says what is wrong with the number of periods and the dates after the field is left, and never runs an invalid form', async () => {
    await page.goto(retentionUrl);
    // the number of periods is left alone until now: a message waits for the field to be left once
    await setEvent(cohortEvent(), signup);
    await setEvent(returnEvent(), visit);
    await setDates();
    let posts = 0;
    await page.route('**/api/projects/*/insights/retention', (route) => {
        posts++;

        return route.continue();
    });

    const periods = select('Number of periods');
    const message = 'Enter the number of periods as a whole number from 1 to 30.';
    await periods.fill('0');
    await expect(page.getByText(message)).toHaveCount(0);
    await page.keyboard.press('Tab');
    await expect(page.getByText(message)).toBeVisible();
    await expect(periods).toHaveAttribute('aria-invalid', 'true');
    await expect(periods).toHaveAccessibleDescription(new RegExp(message.replace('.', '\\.')));
    await expect(runButton()).toHaveAttribute('aria-disabled', 'true');

    await periods.fill('31');
    await select('Retention type').focus();
    await expect(page.getByText(message)).toBeVisible();
    await runButton().click({ force: true });
    await expect(periods).toBeFocused();
    expect(posts, 'the form is invalid: nothing is sent').toBe(0);

    for (const bad of ['1.5', 'abc', '']) {
        await periods.fill(bad);
        await expect(page.getByText(message)).toBeVisible();
    }
    await periods.fill('30');
    await expect(page.getByText(message)).toHaveCount(0);
    await expect(runButton()).not.toHaveAttribute('aria-disabled', 'true');

    // dates
    await from().fill('2026-03-08');
    await to().fill('2026-03-01');
    await to().blur();
    await expect(page.getByText('The end date must not be before the start date.')).toBeVisible();
    await expect(runButton()).toHaveAttribute('aria-disabled', 'true');
    await from().fill('2024-01-01');
    await to().fill('2026-03-01');
    await expect(page.getByText('The range can be at most 366 days.')).toBeVisible();
    await page.getByLabel('Date range preset').selectOption('last_30_days');
    await expect(page.getByText('The range can be at most 366 days.')).toHaveCount(0);

    // an event name that is too long
    await cohortEvent().fill('x'.repeat(201));
    await page.keyboard.press('Escape');
    await select('Number of periods').focus();
    await expect(page.getByText('Event names are 1 to 200 characters.')).toBeVisible();
    await setEvent(cohortEvent(), signup);
    await expect(page.getByText('Event names are 1 to 200 characters.')).toHaveCount(0);

    await page.unroute('**/api/projects/*/insights/retention');
});

test('shows the server messages of a 422 on the controls they belong to, and accepts a fixed form afterwards', async () => {
    provoking = true;
    try {
        await page.goto(retentionUrl);
        await fillForm();
        await page.getByRole('button', { name: 'Add filter to return event' }).click();
        await page.getByLabel('Return event filter 1 property key').fill('plan');
        await page.route('**/api/projects/*/insights/retention', (route) => route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({
                message: 'The given data was invalid.',
                error: { kind: 'validation', message: 'The given data was invalid.', retryable: false, retry_after_seconds: null },
                errors: {
                    'target.event': ['The target event needs a name of 1 to 200 characters of text.'],
                    'returning.filters.0.value': ['A filter value must be text of at most 1000 characters, or null.'],
                    periods: ['The number of periods must be a whole number from 1 to 30.'],
                    timezone: ['Unknown time zone.'],
                    definition: ['This combination is not allowed.'],
                },
            }),
        }));

        await runButton().click();
        await expect(page.getByText('The target event needs a name of 1 to 200 characters of text.')).toBeVisible();
        await expect(cohortEvent()).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByText('A filter value must be text of at most 1000 characters, or null.')).toBeVisible();
        await expect(page.getByLabel('Return event filter 1 value')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByText('The number of periods must be a whole number from 1 to 30.')).toBeVisible();
        await expect(page.getByText('Unknown time zone.')).toBeVisible();
        await expect(select('Time zone')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByTestId('general-error')).toContainText('This combination is not allowed.');
        expect(await seriousViolations()).toEqual([]);

        await page.unroute('**/api/projects/*/insights/retention');
    } finally {
        provoking = false;
    }
    await page.getByRole('button', { name: 'Remove return event filter 1' }).click();
    await runButton().click();
    await expect(table()).toBeVisible();
    await expect(page.getByTestId('general-error')).toHaveCount(0);
});

test('cancels a run that is queued or running: the run is deleted, nothing is left on screen, focus returns to Run', async () => {
    await page.goto(retentionUrl);
    await fillForm();

    const runId = 'c'.repeat(32);
    const deleted = [];
    await page.route('**/api/projects/*/insights/retention', (route) => route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ status: 'queued', run_id: runId, poll_url: `/api/projects/${projectId}/insights/runs/${runId}` }) }));
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
    await expect(page.getByTestId('status-visible')).toHaveText('Running retention…');
    await expect(page.getByTestId('status')).toHaveText('Running retention…');

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

    await page.unroute('**/api/projects/*/insights/retention');
    await page.unroute('**/api/projects/*/insights/runs/*');
    await page.getByRole('button', { name: 'Run again' }).click();
    await expect(table()).toBeVisible();
});

test('shows a failed run with the server sentence and what to try, and a query that was too large', async () => {
    await page.goto(retentionUrl);
    await fillForm();

    const runId = 'd'.repeat(32);
    await page.route('**/api/projects/*/insights/retention', (route) => route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ status: 'queued', run_id: runId, poll_url: `/api/projects/${projectId}/insights/runs/${runId}` }) }));
    await page.route('**/api/projects/*/insights/runs/*', (route) => route.request().method() === 'DELETE'
        ? route.fulfill({ status: 204 })
        : route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'failed', run_id: runId, error: { kind: 'memory_limit', message: 'The query is too large. Try a shorter date range, fewer series or a simpler breakdown.', retryable: false, retry_after_seconds: null } }) }));

    await runButton().click();
    const failed = page.getByTestId('state-failed');
    await expect(failed).toContainText('The query is too large.');
    await expect(page.getByTestId('failure-help')).toContainText('Try weekly or monthly periods, fewer periods or a shorter date range');
    await expect(failed.getByRole('button', { name: 'Try again' })).toHaveCount(0);
    expect(await seriousViolations()).toEqual([]);

    await page.unroute('**/api/projects/*/insights/retention');
    await page.unroute('**/api/projects/*/insights/runs/*');
    await runButton().click();
    await expect(table()).toBeVisible();
});

test('says so when nobody did the cohort event, and refuses an answer that is not a table', async () => {
    await page.goto(retentionUrl);
    await fillForm({ target: never, returning: visit });
    await runButton().click();

    const empty = page.getByTestId('state-empty');
    await expect(empty).toContainText('Nobody did the cohort event in this range');
    await expect(empty).toContainText(never);
    await expect(page.getByTestId('retention-table')).toHaveCount(0);
    await expect(page.getByTestId('status')).toHaveText('Nobody did the cohort event in this range.');
    expect(await seriousViolations()).toEqual([]);

    // an answer of another shape is an error, never numbers
    await page.route('**/api/projects/*/insights/retention', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'done', run_id: null, result: { period: 'day', periods: 2, retention_type: 'first_time', timezone: 'UTC', cohorts: [{ start: 0, size: 1, values: [1], rates: [1] }], averages: { rates: [1], cohorts: [1] } } }),
    }));
    await setEvent(cohortEvent(), signup);
    await runButton().click();
    await expect(page.getByTestId('state-failed')).toContainText('The server sent an answer this page cannot read.');
    await expect(page.getByTestId('retention-table')).toHaveCount(0);
    await page.unroute('**/api/projects/*/insights/retention');
});

/** An answer made up in the browser: `cohorts` as [start ISO, size, values] with the shares worked out here. */
function made({ period, periods, type = 'first_time', timezone, cohorts }) {
    const rows = cohorts.map(([start, size, values]) => ({
        start: Date.parse(start) / 1000,
        label: start,
        size,
        values,
        rates: values.map((value) => (value === null || size === 0 ? null : Math.round((value / size) * 10_000) / 10_000)),
    }));
    const rates = [];
    const counts = [];
    for (let k = 0; k <= periods; k++) {
        const usable = rows.filter((row) => row.size > 0 && row.values[k] !== null);
        const base = usable.reduce((sum, row) => sum + row.size, 0);
        rates.push(base === 0 ? null : Math.round((usable.reduce((sum, row) => sum + row.values[k], 0) / base) * 10_000) / 10_000);
        counts.push(usable.length);
    }

    return { status: 'done', run_id: null, result: { period, periods, retention_type: type, timezone, cohorts: rows, averages: { rates, cohorts: counts } } };
}

test('labels weeks and months in the zone of the answer, and a wide table scrolls inside its own region with the cohort in view', async () => {
    await page.goto(retentionUrl);
    await fillForm();

    // 30 periods of 12 weekly cohorts: Monday 00:00 in Kolkata is Sunday 18:30 UTC, so the label must follow the zone
    const mondays = Array.from({ length: 12 }, (_, i) => new Date(Date.UTC(2026, 2, 8, 18, 30) + i * 7 * DAY).toISOString());
    const wide = made({ period: 'week', periods: 30, timezone: 'Asia/Kolkata', cohorts: mondays.map((start, i) => [start, 10 + i, Array.from({ length: 31 }, (_, k) => (i + k > 20 ? null : Math.max(0, 10 + i - k)))]) });
    await page.route('**/api/projects/*/insights/retention', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(wide) }));
    await runButton().click();
    await expect(table()).toBeVisible();

    const result = await rows();
    expect(result.map((row) => row[0]).slice(0, 3)).toEqual(['Week of Mar 9', 'Week of Mar 16', 'Week of Mar 23']);
    expect(result[0][1]).toBe('10');
    expect(result[0][2]).toHaveLength(31);
    await expect(page.getByTestId('retention-note')).toHaveText('Periods are whole ISO weeks (Monday first) in Asia/Kolkata.');
    await expect(table().getByRole('columnheader').nth(2 + 30)).toHaveText('Week 30');

    // the region is a keyboard stop with a name; the table is wider than it, the page is not
    const region = page.getByRole('group', { name: /table$/ });
    await expect(region).toHaveAttribute('tabindex', '0');
    const sizes = await region.evaluate((element) => ({ client: element.clientWidth, scroll: element.scrollWidth, page: document.documentElement.scrollWidth - document.documentElement.clientWidth }));
    expect(sizes.scroll).toBeGreaterThan(sizes.client);
    expect(sizes.page).toBeLessThanOrEqual(0);

    await region.focus();
    await expect(region).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);

    // scrolled, the cohort label and the size are still where they were; the head too
    const label = table().getByRole('row').nth(2).getByRole('rowheader');
    const box = await region.boundingBox();
    const at = await label.boundingBox();
    expect(Math.abs(at.x - box.x)).toBeLessThan(3);
    await region.evaluate((element) => element.scrollTo({ left: element.scrollWidth }));
    expect(Math.abs((await label.boundingBox()).x - box.x)).toBeLessThan(3);
    const size = table().getByRole('row').nth(2).locator('[data-size]');
    expect(Math.abs((await size.boundingBox()).x - (box.x + 160))).toBeLessThan(3);
    expect(await seriousViolations()).toEqual([]);

    // months, in a zone that is ahead of UTC by a day at the turn of the year
    const months = made({ period: 'month', periods: 2, timezone: 'Pacific/Auckland', cohorts: [['2025-11-30T11:00:00Z', 5, [5, 3, 1]], ['2025-12-31T11:00:00Z', 4, [4, 2, null]], ['2026-01-31T11:00:00Z', 0, [0, null, null]]] });
    await page.unroute('**/api/projects/*/insights/retention');
    await page.route('**/api/projects/*/insights/retention', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(months) }));
    await page.getByLabel('Date range preset').selectOption('last_7_days');
    await runButton().click();
    await expect.poll(async () => (await rows())[0]?.[0]).toBe('Dec 2025');
    expect((await rows()).map((row) => row[0])).toEqual(['Dec 2025', 'Jan 2026', 'Feb 2026']);
    await expect(table().getByRole('columnheader')).toHaveText(['Cohort', 'People', 'Month 0', 'Month 1', 'Month 2']);
    await page.unroute('**/api/projects/*/insights/retention');
});

test('keeps the table in the URL: a link runs it, reload and back restore it, and a hostile link degrades safely', async () => {
    const t = encodeURIComponent(JSON.stringify([signup, [['plan', 'equals', 'pro']]]));
    const r = encodeURIComponent(JSON.stringify([visit, []]));
    await page.goto(`${retentionUrl}?t=${t}&r=${r}&n=3&pd=day&rt=recurring&tz=Asia%2FKolkata&from=${day(-3)}&to=${day(-1)}`);

    await expect(table()).toBeVisible();
    await expect(cohortEvent()).toHaveValue(signup);
    await expect(returnEvent()).toHaveValue(visit);
    await expect(page.getByLabel('Cohort event filter 1 property key')).toHaveValue('plan');
    await expect(select('Number of periods')).toHaveValue('3');
    await expect(select('Retention type')).toHaveValue('recurring');
    await expect(select('Time zone')).toHaveValue('Asia/Kolkata');
    await expect(page.getByLabel('Date range preset')).toHaveValue('custom');
    await expect(page.getByTestId('retention-note')).toContainText('in Asia/Kolkata');
    await expect(page.getByTestId('retention-summary')).toContainText('Retention typeRecurring');

    await page.reload();
    await expect(returnEvent()).toHaveValue(visit);
    await expect(table()).toBeVisible();

    await page.getByRole('link', { name: projectName }).first().click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}$`));
    await page.goBack();
    await expect(page).toHaveURL(/rt=recurring/);
    await expect(select('Time zone')).toHaveValue('Asia/Kolkata');
    await expect(table()).toBeVisible();

    // a link with the same-event shortcut is complete without a return event
    await page.goto(`${retentionUrl}?t=${encodeURIComponent(JSON.stringify([visit, []]))}&same=1&n=3&from=${day(-3)}&to=${day(-1)}`);
    await expect(same()).toBeChecked();
    await expect(table()).toBeVisible();

    // a link made of nonsense gives the defaults, no error and nothing is run
    await page.goto(`${retentionUrl}?t=garbage&r=%5B1%5D&n=abc&pd=century&rt=sideways&tz=Mars%2FBase&p=forever&from=2026-02-30&to=x&junk=1`);
    await expect(page.getByTestId('state-idle')).toBeVisible();
    await expect(cohortEvent()).toHaveValue('');
    await expect(select('Period')).toHaveValue('day');
    await expect(select('Number of periods')).toHaveValue('8');
    await expect(select('Retention type')).toHaveValue('first_time');
    await expect(select('Time zone')).toHaveValue('UTC');
    await expect(page.getByLabel('Date range preset')).toHaveValue('last_30_days');
    await expect(page.locator('[role="alert"]')).toHaveCount(0);

    // an incomplete table in a link is filled in, not run
    await page.goto(`${retentionUrl}?t=${encodeURIComponent(JSON.stringify([signup, []]))}`);
    await expect(cohortEvent()).toHaveValue(signup);
    await expect(returnEvent()).toHaveValue('');
    await expect(page.getByTestId('state-idle')).toBeVisible();
});

test('is accessible: the form, the result, with a suggestion list open, in dark mode by media query and by data-theme', async () => {
    await page.goto(retentionUrl);
    expect(await seriousViolations()).toEqual([]);

    await fillForm();
    await page.getByRole('button', { name: 'Add filter to cohort event' }).click();
    await runButton().click();
    await expect(table()).toBeVisible();
    await expect(curve()).toBeVisible();
    expect(await seriousViolations()).toEqual([]);

    await returnEvent().focus();
    await expect(returnEvent()).toHaveAttribute('aria-expanded', 'false');
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('listbox').getByRole('option').first()).toBeVisible();
    expect(await seriousViolations()).toEqual([]);
    await page.keyboard.press('Escape');

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark');
    await expect(curve()).toBeVisible();
    expect(await seriousViolations()).toEqual([]);

    // the curve takes its colour from the tokens of the scheme: --chart-1 differs between light and dark
    const dark = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--chart-1').trim());
    await expect.poll(() => curve().evaluate((svg) => [...svg.querySelectorAll('path, rect, circle')].flatMap((node) => [node.getAttribute('fill'), node.getAttribute('stroke')]).filter(Boolean))).toContain(dark);

    await page.emulateMedia({ colorScheme: 'light' });
    await page.evaluate(() => (document.documentElement.dataset.theme = 'dark'));
    expect(await seriousViolations()).toEqual([]);
    await page.evaluate(() => delete document.documentElement.dataset.theme);
});

/** WCAG contrast of every cell that has a number in it, measured on what the browser really paints (color-mix included). */
async function lowestContrast() {
    return table().evaluate((element) => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        const paint = (css) => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = '#000';
            context.fillStyle = css;
            context.fillRect(0, 0, 1, 1);

            return [...context.getImageData(0, 0, 1, 1).data.slice(0, 3)];
        };
        const luminance = (rgb) => rgb.map((c) => ((c /= 255) <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
        const ratio = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);
        const background = (node) => {
            for (let el = node; el !== null; el = el.parentElement) {
                const colour = getComputedStyle(el).backgroundColor;
                if (colour !== 'rgba(0, 0, 0, 0)' && colour !== 'transparent') {
                    return colour;
                }
            }

            return 'rgb(255, 255, 255)';
        };

        const seen = new Map();
        for (const td of element.querySelectorAll('td[data-cell]')) {
            const value = ratio(paint(getComputedStyle(td).color), paint(background(td)));
            const key = [...td.classList].find((name) => name.startsWith('heat-')) ?? 'plain';
            seen.set(key, Math.min(seen.get(key) ?? 99, value));
        }

        return Object.fromEntries(seen);
    });
}

test('the text of every heat step is readable as painted, in light and dark, by media query and by data-theme', async () => {
    await page.goto(retentionUrl);
    // enough cohorts and shares to use every step: made up
    const wide = made({
        period: 'day',
        periods: 8,
        timezone: 'UTC',
        cohorts: [['2026-03-02T00:00:00Z', 60, [60, 50, 40, 30, 20, 10, 5, 0, null]], ['2026-03-03T00:00:00Z', 60, [60, 55, 35, 25, 15, 8, 3, null, null]], ['2026-03-04T00:00:00Z', 0, [0, 0, 0, null, null, null, null, null, null]]],
    });
    await page.route('**/api/projects/*/insights/retention', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(wide) }));
    await fillForm();
    await runButton().click();
    await expect(table()).toBeVisible();

    const check = async (name) => {
        const found = await lowestContrast();
        expect(Object.keys(found).sort(), `${name}: every heat step, a share of 0 and a period that has not started`).toEqual(['heat-1', 'heat-2', 'heat-3', 'heat-4', 'heat-5', 'heat-6', 'heat-future', 'plain']);
        for (const [step, ratio] of Object.entries(found)) {
            expect(ratio, `${name}: ${step}`).toBeGreaterThanOrEqual(4.5);
        }
    };

    await check('light');
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark');
    await check('dark by media query');
    await page.emulateMedia({ colorScheme: 'light' });
    await page.evaluate(() => (document.documentElement.dataset.theme = 'dark'));
    await check('dark by data-theme');
    await page.evaluate(() => (document.documentElement.dataset.theme = 'light'));
    await check('light by data-theme');
    await page.evaluate(() => delete document.documentElement.dataset.theme);
    await page.unroute('**/api/projects/*/insights/retention');
});

test('offers a retry when the chart code cannot be loaded, and the table still has the numbers', async () => {
    provoking = true;
    try {
        await page.route('**/build/assets/RetentionCanvas-*.js', (route) => route.abort());
        await page.goto(retentionUrl);
        await fillForm();
        await runButton().click();

        const alert = page.getByTestId('chart-error');
        await expect(alert).toBeVisible();
        await expect(alert).toHaveAttribute('role', 'alert');
        expect(await rows()).toEqual(FIRST_TIME);

        await page.unroute('**/build/assets/RetentionCanvas-*.js');
        await alert.getByRole('button', { name: 'Try again' }).click();
        await expect(curve()).toBeVisible();
    } finally {
        provoking = false;
    }
});

test('works on a narrow screen: the events stack, the table scrolls inside its region and nothing scrolls sideways', async () => {
    await page.setViewportSize({ width: 375, height: 800 });
    try {
        await page.goto(retentionUrl);
        await fillForm();
        await runButton().click();
        await expect(table()).toBeVisible();
        await expect(curve()).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(0);
        expect(await seriousViolations()).toEqual([]);

        // the form fits too, with a long answer in the table and the custom dates open
        await page.getByLabel('Number of periods').fill('30');
        await page.getByLabel('Number of periods').blur();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    } finally {
        await page.setViewportSize({ width: 1280, height: 720 });
    }
});

test('had no console errors and no failed requests', async () => {
    expect(problems).toEqual([]);
});
