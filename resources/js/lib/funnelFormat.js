// Display helpers for a funnel RESULT (`{ steps: [{index, event, count, dropped_off, conversion_from_previous,
// conversion_from_first}], entrants, converted, conversion_rate, window_seconds, order }`, see FunnelResult::toArray()).
// Pure functions; nothing here touches the DOM or ECharts.

import { formatDuration } from './funnelDefinition.js';
import { DEFAULT_LOCALE, formatNumber, truncate } from './trendsFormat.js';

/** What a rate shows when there is none (nobody started: the denominator is 0, which is not a rate of 0). */
export const NO_RATE = '—';

const percent = new Intl.NumberFormat(DEFAULT_LOCALE, { style: 'percent', maximumFractionDigits: 2 });

/** A ratio from 0 to 1 as a percentage (0.6667 is "66.67%", 1 is "100%"); null is an em dash. */
export function formatRate(value) {
    return typeof value === 'number' && Number.isFinite(value) ? percent.format(value) : NO_RATE;
}

const isRate = (value) => value === null || (typeof value === 'number' && Number.isFinite(value));
const isCount = (value) => Number.isInteger(value) && value >= 0;

/**
 * Whether `result` has the shape the page draws. A result that does not (a server of another version, a damaged cache
 * entry) is shown as an error and never as numbers.
 */
export function isFunnelResult(result) {
    return typeof result === 'object' && result !== null
        && Array.isArray(result.steps) && result.steps.length > 0
        && isCount(result.entrants) && isCount(result.converted) && isRate(result.conversion_rate)
        && Number.isInteger(result.window_seconds) && result.window_seconds > 0
        && typeof result.order === 'string'
        && result.steps.every((step, i) => typeof step === 'object' && step !== null && step.index === i + 1 && typeof step.event === 'string'
            && isCount(step.count) && isCount(step.dropped_off) && isRate(step.conversion_from_previous) && isRate(step.conversion_from_first));
}

/** Nobody started the funnel in the range: there is nothing to draw. */
export function isEmptyFunnel(result) {
    return result.entrants === 0;
}

/** "1. signup": the label of a step on the chart (cut when long; the table has the whole name). */
export function stepLabel(step, max = 32) {
    return `${step.index}. ${truncate(step.event, max)}`;
}

export function orderLabel(order) {
    return order === 'strict_order' ? 'Strict order' : 'Ordered';
}

/** The headline numbers, as label/value pairs for a list. */
export function summaryItems(result) {
    return [
        { label: 'Entrants', value: formatNumber(result.entrants) },
        { label: 'Converted', value: formatNumber(result.converted) },
        { label: 'Overall conversion', value: formatRate(result.conversion_rate) },
        { label: 'Window', value: formatDuration(result.window_seconds) },
    ];
}

/** One sentence for the live region once a funnel has been drawn. */
export function describeSummary(result) {
    const people = (n) => `${formatNumber(n)} ${n === 1 ? 'person' : 'people'}`;

    return `Funnel updated: ${people(result.entrants)} started, ${people(result.converted)} completed all ${result.steps.length} steps, overall conversion ${formatRate(result.conversion_rate)}.`;
}

/** The accessible name of the chart: every step with its count, then the overall rate. */
export function describeFunnel(result) {
    const steps = result.steps.map((step) => `${step.index}. ${step.event}: ${formatNumber(step.count)}`).join('; ');

    return `Horizontal bar chart of a funnel with ${result.steps.length} steps. ${steps}. Overall conversion ${formatRate(result.conversion_rate)} within ${formatDuration(result.window_seconds)}.`;
}

/** The text of a data label on a bar: the count and the share of everyone who started. */
export function barLabel(step) {
    return `${formatNumber(step.count)} · ${formatRate(step.conversion_from_first)}`;
}

/**
 * Extra help for the failures a funnel can run into, shown under the server's own sentence (which is generic: it names
 * series and breakdowns, which a funnel has none of).
 */
export function failureHelp(error) {
    switch (error?.kind) {
        case 'memory_limit':
        case 'too_many_rows':
            return 'A funnel follows every person through every step, so a long date range, a long window or a first step that almost everyone does can be too much. Try a shorter date range or window, add a filter to the first step, or use an ordered funnel instead of a strict one.';
        case 'timeout':
            return 'Try a shorter date range or window, add a filter to the first step, or use an ordered funnel instead of a strict one.';
        default:
            return null;
    }
}
