// What a dashboard tile does with the result of a saved insight run, by insight type: whether the result has the shape the
// tile draws, whether it is empty, and the sentence for assistive technology. Pure; the shapes are those of the pages
// (isFunnelResult, isRetentionResult), so a tile never draws a result its page would refuse.

import { isEmptyFunnel, isFunnelResult } from './funnelFormat.js';
import { isEmptyRetention, isRetentionResult } from './retentionFormat.js';
import { isEmptyResult } from './trendsFormat.js';

const isTrendsResult = (result) => typeof result === 'object' && result !== null && Array.isArray(result.series) && Array.isArray(result.buckets) && typeof result.timezone === 'string';

const SHAPES = { trends: isTrendsResult, funnel: isFunnelResult, retention: isRetentionResult };
const EMPTY = { trends: isEmptyResult, funnel: isEmptyFunnel, retention: isEmptyRetention };

export const isDrawable = (type, result) => (SHAPES[type]?.(result) ?? false) === true;

export const isEmptyFor = (type, result) => (EMPTY[type]?.(result) ?? false) === true;

/** What a tile of an empty result says (one sentence, per type). */
export const EMPTY_TEXT = {
    trends: 'No events in the date range of this insight.',
    funnel: 'Nobody started this funnel in the date range.',
    retention: 'Nobody did the cohort event in the date range.',
};

/** Trends labels need the interval and metric of the definition, which a result does not carry: they come from the saved definition. */
export function trendsMeta(detail) {
    const query = detail?.query;

    return typeof query?.interval === 'string' && typeof query?.metric === 'string' ? { interval: query.interval, metric: query.metric } : null;
}
