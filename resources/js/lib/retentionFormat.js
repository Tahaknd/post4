// Display helpers for a retention RESULT (`{ period, periods, retention_type, timezone, cohorts: [{start, label, size, values,
// rates}], averages: {rates, cohorts} }`, see RetentionResult::toArray()): cohort labels in the zone of the result, the cells
// of the table, the heat step of a rate, the average curve and the sentences. Pure functions; nothing here touches the DOM or
// ECharts.
//
// A cell is a count of people and a share of the cohort. NULL is not 0: a period that has not started yet has no count
// (an em dash, with a sentence for a screen reader), while a cohort nobody belongs to has counts of 0 and no share.

import { NO_RATE, formatRate } from './funnelFormat.js';
import { DEFAULT_LOCALE, formatNumber, formatterFor } from './trendsFormat.js';

export { NO_RATE };

/** The heat of a share is one of 0 (nothing came back) and 1..HEAT_STEPS (more and more). The colours of the steps are in app.css. */
export const HEAT_STEPS = 6;

const PERIODS = ['day', 'week', 'month'];
const TYPE_LABELS = { first_time: 'First time', recurring: 'Recurring' };

const isRate = (value) => value === null || (typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 1);
const isCount = (value) => Number.isInteger(value) && value >= 0;
const isCountOrNull = (value) => value === null || isCount(value);
const isList = (value, length, item) => Array.isArray(value) && value.length === length && value.every(item);

/**
 * Whether `result` has the shape the page draws. A result that does not (a server of another version, a damaged cache entry)
 * is shown as an error and never as numbers.
 */
export function isRetentionResult(result) {
    if (typeof result !== 'object' || result === null || !PERIODS.includes(result.period) || !Number.isInteger(result.periods) || result.periods < 1
        || !Object.hasOwn(TYPE_LABELS, result.retention_type) || typeof result.timezone !== 'string' || !Array.isArray(result.cohorts) || result.cohorts.length === 0) {
        return false;
    }

    const width = result.periods + 1;
    const { averages } = result;

    return result.cohorts.every((cohort) => typeof cohort === 'object' && cohort !== null && Number.isInteger(cohort.start) && isCount(cohort.size)
        && isList(cohort.values, width, isCountOrNull) && isList(cohort.rates, width, isRate))
        && typeof averages === 'object' && averages !== null && isList(averages.rates, width, isRate) && isList(averages.cohorts, width, isCount);
}

/** Nobody did the cohort event in any period of the table: there is nothing to draw. */
export function isEmptyRetention(result) {
    return result.cohorts.every((cohort) => cohort.size === 0);
}

// ---------------------------------------------------------------- words

export function periodNoun(period) {
    return PERIODS.includes(period) ? period : 'period';
}

/** The header of the column k periods after the cohort period: "Week 3" (column 0 is the cohort period itself). */
export function periodHeader(period, k) {
    const noun = periodNoun(period);

    return `${noun[0].toUpperCase()}${noun.slice(1)} ${k}`;
}

export function typeLabel(type) {
    return TYPE_LABELS[type] ?? type;
}

/** The sentence that says what the rows are: weeks start on Monday, the zone decides where periods begin. */
export function periodsNote(result) {
    const noun = periodNoun(result.period);
    const unit = noun === 'week' ? 'ISO weeks (Monday first)' : `${noun}s`;

    return `Periods are whole ${unit} in ${result.timezone}.`;
}

// ---------------------------------------------------------------- cohort labels

/**
 * One label per cohort (the UTC epoch seconds of the period start), in the zone of the result: "Mon, Mar 9" for a day, "Week of
 * Mar 9" for a week, "Mar 2026" for a month. The year is added to a day or a week when the table spans more than one year.
 * Labels are unique: periods have distinct starts, and the year only ever makes them longer.
 *
 * @param {{start: number}[]} cohorts
 * @param {'day'|'week'|'month'} period
 * @param {string} timeZone
 * @returns {string[]}
 */
export function cohortLabels(cohorts, period, timeZone, locale = DEFAULT_LOCALE) {
    const yearOf = formatterFor(timeZone, { year: 'numeric' }, locale);
    const years = new Set(cohorts.map((cohort) => yearOf.format(new Date(cohort.start * 1000))));
    const year = years.size > 1 ? { year: 'numeric' } : {};
    const options = period === 'month' ? { month: 'short', year: 'numeric' } : period === 'week' ? { month: 'short', day: 'numeric', ...year } : { weekday: 'short', month: 'short', day: 'numeric', ...year };
    const formatter = formatterFor(timeZone, options, locale);

    return cohorts.map((cohort) => {
        const text = formatter.format(new Date(cohort.start * 1000));

        return period === 'week' ? `Week of ${text}` : text;
    });
}

// ---------------------------------------------------------------- heat

/**
 * The heat step of a share (0..1): 0 for nothing, then 1..HEAT_STEPS in equal slices, so a share of 100% is the strongest and
 * any share above 0 is at least step 1. A missing share (null) has no step.
 */
export function heatStep(rate) {
    if (rate === null || rate === undefined || !Number.isFinite(rate)) {
        return null;
    }

    return rate <= 0 ? 0 : Math.min(HEAT_STEPS, Math.max(1, Math.ceil(rate * HEAT_STEPS - 1e-9)));
}

// ---------------------------------------------------------------- the cells of the table

const people = (n) => `${formatNumber(n)} ${n === 1 ? 'person' : 'people'}`;

/**
 * The cell of a cohort in the column k: `kind` is `future` (the period has not started yet: no count), `empty` (a cohort of
 * nobody: counts of 0, no share) or `value`. `count` and `rate` are display text, `step` the heat step and `sentence` what a
 * screen reader hears instead of the two numbers.
 */
export function cohortCell(cohort, k, noun = 'period') {
    const count = cohort.values[k];

    if (count === null) {
        return { kind: 'future', count: NO_RATE, rate: '', step: null, sentence: `Not yet: this ${noun} has not started` };
    }
    if (cohort.rates[k] === null) {
        return { kind: 'empty', count: formatNumber(count), rate: NO_RATE, step: 0, sentence: `${people(count)}, no rate: nobody is in this cohort` };
    }

    const rate = formatRate(cohort.rates[k]);

    return {
        kind: 'value',
        count: formatNumber(count),
        rate,
        step: heatStep(cohort.rates[k]),
        sentence: k === 0 ? `${people(count)}, the size of the cohort` : `${people(count)} of ${formatNumber(cohort.size)}, ${rate}`,
    };
}

/** The cell of the average row in the column k: the pooled share and how many cohorts it is made of. */
export function averageCell(averages, k) {
    const rate = averages.rates[k];
    const cohorts = averages.cohorts[k];

    if (rate === null) {
        return { kind: 'none', rate: NO_RATE, cohorts: '', step: null, sentence: 'No average: no cohort with people has reached this period' };
    }

    const count = `${formatNumber(cohorts)} ${cohorts === 1 ? 'cohort' : 'cohorts'}`;

    return { kind: 'value', rate: formatRate(rate), cohorts: count, step: heatStep(rate), sentence: `Average ${formatRate(rate)} over ${count}` };
}

// ---------------------------------------------------------------- summary, chart, help

/** Everyone who is in a cohort, summed over the cohorts (with `recurring` a person is counted once per cohort they are in). */
export function totalPeople(result) {
    return result.cohorts.reduce((sum, cohort) => sum + cohort.size, 0);
}

/** The headline numbers, as label/value pairs for a list. */
export function summaryItems(result) {
    const noun = periodNoun(result.period);

    return [
        { label: 'Cohorts', value: formatNumber(result.cohorts.length) },
        { label: result.retention_type === 'recurring' ? 'Cohort memberships' : 'People in cohorts', value: formatNumber(totalPeople(result)) },
        { label: `Average, ${noun} 1`, value: formatRate(result.averages.rates[1]) },
        { label: 'Retention type', value: typeLabel(result.retention_type) },
    ];
}

/** One sentence for the live region once a table has been drawn. */
export function describeSummary(result) {
    const noun = periodNoun(result.period);
    const n = result.cohorts.length;
    const first = result.averages.rates[1];

    return `Retention updated: ${n} ${n === 1 ? 'cohort' : 'cohorts'}, ${people(totalPeople(result))} in them. Average retention in ${noun} 1: ${first === null ? 'not available yet' : formatRate(first)}.`;
}

/** The categories of the average curve: the headers of the periods 0..N. */
export function curveLabels(result) {
    return Array.from({ length: result.periods + 1 }, (_, k) => periodHeader(result.period, k));
}

/** The accessible name of the chart: every period with its average share. */
export function describeCurve(result) {
    const labels = curveLabels(result);
    const noun = periodNoun(result.period);
    const points = result.averages.rates.map((rate, k) => `${labels[k]}: ${rate === null ? 'no data' : formatRate(rate)}`).join('; ');

    return `Line chart of the average retention, from the cohort ${noun} to ${result.periods} ${noun}${result.periods === 1 ? '' : 's'} later. ${points}.`;
}

const TYPE_HELP = {
    first_time: 'Each person is counted once, in the period they first did the cohort event within this date range and its follow-up periods. People who started long before the range look new in the first period they appear.',
    recurring: 'A person is counted in every period in which they did the cohort event, so the cohort sizes add up to more than the number of people.',
};

export function typeHelp(type) {
    return TYPE_HELP[type] ?? TYPE_HELP.first_time;
}

/**
 * Extra help for the failures a retention table can run into, shown under the server's own sentence (which is generic: it
 * names series and breakdowns, which a retention table has none of).
 */
export function failureHelp(error) {
    switch (error?.kind) {
        case 'memory_limit':
        case 'too_many_rows':
            return 'A retention table follows every person through every period, so a long date range with daily periods, many periods or a cohort event that almost everyone does can be too much. Try weekly or monthly periods, fewer periods or a shorter date range, add a filter to the cohort event, or use first-time instead of recurring retention.';
        case 'timeout':
            return 'Try weekly or monthly periods, fewer periods or a shorter date range, or add a filter to the cohort event.';
        default:
            return null;
    }
}
