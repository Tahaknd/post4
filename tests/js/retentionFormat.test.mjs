import assert from 'node:assert/strict';
import test from 'node:test';

import {
    HEAT_STEPS,
    NO_RATE,
    averageCell,
    cohortCell,
    cohortLabels,
    curveLabels,
    describeCurve,
    describeSummary,
    failureHelp,
    heatStep,
    isEmptyRetention,
    isRetentionResult,
    periodHeader,
    periodNoun,
    periodsNote,
    summaryItems,
    totalPeople,
    typeHelp,
    typeLabel,
} from '../../resources/js/lib/retentionFormat.js';

const DAY = 86400;
const utc = (iso) => Date.parse(iso) / 1000;

/** A result of 3 daily cohorts and 2 follow-up periods: the last cohort is only one period old. */
const result = () => ({
    period: 'day',
    periods: 2,
    retention_type: 'first_time',
    timezone: 'UTC',
    cohorts: [
        { start: utc('2026-03-09T00:00:00Z'), label: '2026-03-09T00:00:00+00:00', size: 4, values: [4, 2, 1], rates: [1, 0.5, 0.25] },
        { start: utc('2026-03-10T00:00:00Z'), label: '2026-03-10T00:00:00+00:00', size: 0, values: [0, 0, 0], rates: [null, null, null] },
        { start: utc('2026-03-11T00:00:00Z'), label: '2026-03-11T00:00:00+00:00', size: 2, values: [2, 1, null], rates: [1, 0.5, null] },
    ],
    averages: { rates: [1, 0.5, 0.25], cohorts: [2, 2, 1] },
});

// ---------------------------------------------------------------- shape

test('accepts the result the server sends', () => {
    assert.equal(isRetentionResult(result()), true);
});

test('refuses anything that is not the shape of a result', () => {
    const break_ = (change) => {
        const r = result();
        change(r);

        return r;
    };
    const bad = [
        null, 'x', 42, [], {},
        break_((r) => (r.period = 'hour')),
        break_((r) => (r.period = undefined)),
        break_((r) => (r.periods = 0)),
        break_((r) => (r.periods = 2.5)),
        break_((r) => (r.retention_type = 'constructor')),
        break_((r) => (r.timezone = 7)),
        break_((r) => (r.cohorts = [])),
        break_((r) => (r.cohorts = {})),
        break_((r) => (r.cohorts[0].start = 1.5)),
        break_((r) => (r.cohorts[0].size = -1)),
        break_((r) => (r.cohorts[0].values = [4, 2])),
        break_((r) => (r.cohorts[0].values[1] = 'x')),
        break_((r) => (r.cohorts[0].values[1] = 1.5)),
        break_((r) => (r.cohorts[0].rates[1] = 1.5)),
        break_((r) => (r.cohorts[0].rates[1] = NaN)),
        break_((r) => (r.cohorts[0].rates = null)),
        break_((r) => (r.averages = null)),
        break_((r) => (r.averages.rates = [1, 0.5])),
        break_((r) => (r.averages.cohorts[0] = null)),
        break_((r) => delete r.averages),
    ];

    for (const value of bad) {
        assert.equal(isRetentionResult(value), false, JSON.stringify(value));
    }
});

test('a table of cohorts nobody is in is empty', () => {
    assert.equal(isEmptyRetention(result()), false);

    const nobody = result();
    nobody.cohorts.forEach((cohort) => (cohort.size = 0));
    assert.equal(isEmptyRetention(nobody), true);
});

// ---------------------------------------------------------------- words

test('names the columns after the period', () => {
    assert.equal(periodHeader('day', 0), 'Day 0');
    assert.equal(periodHeader('week', 3), 'Week 3');
    assert.equal(periodHeader('month', 12), 'Month 12');
    assert.equal(periodNoun('week'), 'week');
    assert.equal(periodNoun('century'), 'period');
    assert.deepEqual(curveLabels(result()), ['Day 0', 'Day 1', 'Day 2']);
});

test('says what the periods are, in the zone of the result', () => {
    assert.equal(periodsNote({ period: 'day', timezone: 'Asia/Kolkata' }), 'Periods are whole days in Asia/Kolkata.');
    assert.equal(periodsNote({ period: 'week', timezone: 'UTC' }), 'Periods are whole ISO weeks (Monday first) in UTC.');
    assert.equal(periodsNote({ period: 'month', timezone: 'UTC' }), 'Periods are whole months in UTC.');
    assert.equal(typeLabel('first_time'), 'First time');
    assert.equal(typeLabel('recurring'), 'Recurring');
    assert.match(typeHelp('first_time'), /look new in the first period they appear/);
    assert.match(typeHelp('recurring'), /more than the number of people/);
});

// ---------------------------------------------------------------- labels

test('labels days, weeks and months in the zone of the result', () => {
    const days = [{ start: utc('2026-03-09T00:00:00Z') }, { start: utc('2026-03-10T00:00:00Z') }];
    const weeks = [{ start: utc('2026-03-09T00:00:00Z') }, { start: utc('2026-03-16T00:00:00Z') }];
    const months = [{ start: utc('2026-02-01T00:00:00Z') }, { start: utc('2026-03-01T00:00:00Z') }];

    assert.deepEqual(cohortLabels(days, 'day', 'UTC'), ['Mon, Mar 9', 'Tue, Mar 10']);
    assert.deepEqual(cohortLabels(weeks, 'week', 'UTC'), ['Week of Mar 9', 'Week of Mar 16']);
    assert.deepEqual(cohortLabels(months, 'month', 'UTC'), ['Feb 2026', 'Mar 2026']);
});

test('puts the local calendar day of a zone on the label, not the UTC one', () => {
    // 2026-03-08 21:00 UTC is already March 9 in Istanbul (UTC+3), and March 9 midnight local is 21:00 UTC the day before
    const days = [{ start: utc('2026-03-08T21:00:00Z') }, { start: utc('2026-03-09T21:00:00Z') }];

    assert.deepEqual(cohortLabels(days, 'day', 'Europe/Istanbul'), ['Mon, Mar 9', 'Tue, Mar 10']);
    assert.deepEqual(cohortLabels(days, 'day', 'UTC'), ['Sun, Mar 8', 'Mon, Mar 9']);
    // a zone behind UTC: local midnight is later than UTC midnight
    assert.deepEqual(cohortLabels([{ start: utc('2026-03-09T05:00:00Z') }], 'day', 'America/New_York'), ['Mon, Mar 9']);
    assert.deepEqual(cohortLabels([{ start: utc('2026-03-09T00:00:00Z') }], 'day', 'America/New_York'), ['Sun, Mar 8']);
});

test('follows daylight saving time: the day of a 23 hour night still has its own label', () => {
    // Europe/Berlin springs forward on 2026-03-29: that local day starts at 2026-03-28T23:00Z and the next one at 22:00Z
    const days = [{ start: utc('2026-03-27T23:00:00Z') }, { start: utc('2026-03-28T23:00:00Z') }, { start: utc('2026-03-29T22:00:00Z') }];

    assert.deepEqual(cohortLabels(days, 'day', 'Europe/Berlin'), ['Sat, Mar 28', 'Sun, Mar 29', 'Mon, Mar 30']);
    assert.equal(new Set(cohortLabels(days, 'day', 'Europe/Berlin')).size, 3);
});

test('adds the year to days and weeks only when the table spans years', () => {
    const across = [{ start: utc('2025-12-29T00:00:00Z') }, { start: utc('2026-01-05T00:00:00Z') }];
    const days = [{ start: utc('2025-12-31T00:00:00Z') }, { start: utc('2026-01-01T00:00:00Z') }];

    assert.deepEqual(cohortLabels(across, 'week', 'UTC'), ['Week of Dec 29, 2025', 'Week of Jan 5, 2026']);
    assert.deepEqual(cohortLabels(days, 'day', 'UTC'), ['Wed, Dec 31, 2025', 'Thu, Jan 1, 2026']);
});

test('the year of a label is the year of the zone, not of UTC', () => {
    // New Year's day in Auckland starts on December 31, 11:00 UTC
    const days = [{ start: utc('2025-12-30T11:00:00Z') }, { start: utc('2025-12-31T11:00:00Z') }];

    assert.deepEqual(cohortLabels(days, 'day', 'Pacific/Auckland'), ['Wed, Dec 31, 2025', 'Thu, Jan 1, 2026']);
});

test('every label of a table is unique, also over a long run of weeks and a year of days', () => {
    const weeks = Array.from({ length: 54 }, (_, i) => ({ start: utc('2026-03-09T00:00:00Z') + i * 7 * DAY }));
    const days = Array.from({ length: 367 }, (_, i) => ({ start: utc('2026-01-01T00:00:00Z') + i * DAY }));

    assert.equal(new Set(cohortLabels(weeks, 'week', 'UTC')).size, 54);
    assert.equal(new Set(cohortLabels(days, 'day', 'UTC')).size, 367);
});

// ---------------------------------------------------------------- heat

test('steps the heat from 0 to the last step in equal slices of the share', () => {
    assert.equal(HEAT_STEPS, 6);
    assert.equal(heatStep(0), 0);
    assert.equal(heatStep(0.0001), 1, 'anything above 0 is at least step 1');
    assert.equal(heatStep(0.1666), 1);
    assert.equal(heatStep(0.5), 3);
    assert.equal(heatStep(0.5001), 4);
    assert.equal(heatStep(0.8333), 5);
    assert.equal(heatStep(0.9999), 6);
    assert.equal(heatStep(1), HEAT_STEPS);
});

test('never gives a step outside 0..6, and none for a share that is not there', () => {
    assert.equal(heatStep(7), HEAT_STEPS);
    assert.equal(heatStep(-0.5), 0);
    for (const none of [null, undefined, NaN, Infinity]) {
        assert.equal(heatStep(none), null, String(none));
    }

    let last = 0;
    for (let i = 0; i <= 1000; i++) {
        const step = heatStep(i / 1000);
        assert.ok(Number.isInteger(step) && step >= last && step <= HEAT_STEPS, `${i / 1000}`);
        last = step;
    }
});

// ---------------------------------------------------------------- cells

test('a cell is a count, a share, a heat step and a sentence', () => {
    const [full, empty, young] = result().cohorts;

    assert.deepEqual(cohortCell(full, 0), { kind: 'value', count: '4', rate: '100%', step: 6, sentence: '4 people, the size of the cohort' });
    assert.deepEqual(cohortCell(full, 1), { kind: 'value', count: '2', rate: '50%', step: 3, sentence: '2 people of 4, 50%' });
    assert.deepEqual(cohortCell(full, 2), { kind: 'value', count: '1', rate: '25%', step: 2, sentence: '1 person of 4, 25%' });
    assert.deepEqual(cohortCell(young, 1), { kind: 'value', count: '1', rate: '50%', step: 3, sentence: '1 person of 2, 50%' });
    assert.equal(empty.size, 0);
});

test('a period that has not started is a dash with a sentence, not a zero and not a step', () => {
    const young = result().cohorts[2];

    assert.deepEqual(cohortCell(young, 2, 'day'), { kind: 'future', count: NO_RATE, rate: '', step: null, sentence: 'Not yet: this day has not started' });
    assert.equal(cohortCell(young, 2, 'week').sentence, 'Not yet: this week has not started');
    assert.equal(cohortCell(young, 2).count, '—');
});

test('a cohort of nobody has counts of 0 and no share, which is not a share of 0', () => {
    const empty = result().cohorts[1];

    assert.deepEqual(cohortCell(empty, 1), { kind: 'empty', count: '0', rate: '—', step: 0, sentence: '0 people, no rate: nobody is in this cohort' });
    assert.notEqual(cohortCell(empty, 1).kind, cohortCell({ ...result().cohorts[0], values: [4, 0, 0], rates: [1, 0, 0] }, 1).kind);
    assert.deepEqual(cohortCell({ ...result().cohorts[0], values: [4, 0, 0], rates: [1, 0, 0] }, 1), { kind: 'value', count: '0', rate: '0%', step: 0, sentence: '0 people of 4, 0%' });
});

test('formats big counts with separators and shares with at most two decimals', () => {
    const cohort = { size: 12345, values: [12345, 4115], rates: [1, 0.3333] };

    assert.equal(cohortCell(cohort, 0).count, '12,345');
    assert.equal(cohortCell(cohort, 1).count, '4,115');
    assert.equal(cohortCell(cohort, 1).rate, '33.33%');
    assert.equal(cohortCell(cohort, 1).sentence, '4,115 people of 12,345, 33.33%');
});

test('the average row has the pooled share and the number of cohorts it is made of', () => {
    const { averages } = result();

    assert.deepEqual(averageCell(averages, 0), { kind: 'value', rate: '100%', cohorts: '2 cohorts', step: 6, sentence: 'Average 100% over 2 cohorts' });
    assert.deepEqual(averageCell(averages, 2), { kind: 'value', rate: '25%', cohorts: '1 cohort', step: 2, sentence: 'Average 25% over 1 cohort' });
    assert.deepEqual(averageCell({ rates: [null], cohorts: [0] }, 0), { kind: 'none', rate: '—', cohorts: '', step: null, sentence: 'No average: no cohort with people has reached this period' });
});

// ---------------------------------------------------------------- summary and chart

test('sums the cohorts and writes the headline numbers', () => {
    assert.equal(totalPeople(result()), 6);
    assert.deepEqual(summaryItems(result()), [
        { label: 'Cohorts', value: '3' },
        { label: 'People in cohorts', value: '6' },
        { label: 'Average, day 1', value: '50%' },
        { label: 'Retention type', value: 'First time' },
    ]);

    const recurring = { ...result(), period: 'week', retention_type: 'recurring' };
    assert.equal(summaryItems(recurring)[1].label, 'Cohort memberships');
    assert.equal(summaryItems(recurring)[2].label, 'Average, week 1');
    assert.equal(summaryItems({ ...result(), averages: { rates: [1, null, null], cohorts: [1, 0, 0] } })[2].value, '—');
});

test('says in one sentence what was drawn', () => {
    assert.equal(describeSummary(result()), 'Retention updated: 3 cohorts, 6 people in them. Average retention in day 1: 50%.');
    assert.equal(describeSummary({ ...result(), cohorts: [result().cohorts[0]], averages: { rates: [1, null, null], cohorts: [1, 0, 0] } }), 'Retention updated: 1 cohort, 4 people in them. Average retention in day 1: not available yet.');
});

test('names the curve with every period and says where there is no data', () => {
    assert.equal(
        describeCurve({ ...result(), averages: { rates: [1, 0.5, null], cohorts: [2, 2, 0] } }),
        'Line chart of the average retention, from the cohort day to 2 days later. Day 0: 100%; Day 1: 50%; Day 2: no data.',
    );
    assert.match(describeCurve({ ...result(), periods: 1, averages: { rates: [1, 0.5], cohorts: [1, 1] } }), /to 1 day later/);
});

test('helps after the failures a retention table can run into, and only those', () => {
    assert.match(failureHelp({ kind: 'memory_limit' }), /weekly or monthly periods/);
    assert.equal(failureHelp({ kind: 'too_many_rows' }), failureHelp({ kind: 'memory_limit' }));
    assert.match(failureHelp({ kind: 'timeout' }), /shorter date range/);
    assert.equal(failureHelp({ kind: 'network' }), null);
    assert.equal(failureHelp(null), null);
});
