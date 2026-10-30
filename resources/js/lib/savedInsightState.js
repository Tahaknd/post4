// What a saved insight's `state` (`{status: ok|needs_review, reason}`) means on screen, and the words for types.

export const TYPE_LABELS = { trends: 'Trends', funnel: 'Funnel', retention: 'Retention' };

export const typeLabel = (type) => TYPE_LABELS[type] ?? String(type);

const REASONS = {
    unknown_type: 'This kind of insight is no longer supported. It cannot be run or repaired; remove it.',
    outdated_version: 'It was saved by an older version of the insight and has not been checked since. Open it, check the definition and save it again.',
    invalid_definition: 'Its stored definition is no longer valid. Open it, fix the definition and save it again.',
    invalid_date: 'Its date range cannot be resolved any more. Open it, choose a date range and save it again.',
};

const FALLBACK_REASON = 'It cannot run as it is stored. Open it, check the definition and save it again.';

export const needsReview = (insight) => insight?.state?.status === 'needs_review';

/** The explanation of a needs_review state (a fixed sentence per reason code, never server text). */
export function reasonText(state) {
    return REASONS[state?.reason] ?? FALLBACK_REASON;
}

/** Whether the insight can be opened in an editor page to be repaired (a type the app no longer has cannot). */
export const canRepair = (insight) => Object.hasOwn(TYPE_LABELS, insight?.type);

/** `{ label, tone }` of the state badge of a list row. */
export function stateBadge(insight) {
    return needsReview(insight) ? { label: 'Needs review', tone: 'warning' } : { label: 'OK', tone: 'success' };
}

/**
 * Which body a tile shows, from the phase of its run and the insight it belongs to: needs_review (never run), then the
 * run's own state: waiting (queued here, waiting for a slot), loading, result, empty, failed, expired, cancelled.
 */
export function tileView({ insight, phase, waiting, hasResult, empty }) {
    if (needsReview(insight)) {
        return 'needs_review';
    }
    if (waiting) {
        return 'waiting';
    }

    switch (phase) {
        case 'loading':
            return 'loading';
        case 'done':
            return hasResult ? (empty ? 'empty' : 'result') : 'failed';
        case 'failed':
        case 'invalid':
            return 'failed';
        case 'expired':
        case 'cancelled':
            return phase;
        default:
            return 'loading';
    }
}
