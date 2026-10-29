<?php

namespace App\Domain\Insights\Funnel;

/**
 * How strictly the steps must follow each other. The value is a ClickHouse windowFunnel mode only through
 * {@see self::windowFunnelMode()}, a fixed fragment: the order is never interpolated from input.
 */
enum FunnelOrder: string
{
    /**
     * The steps happen in this order, other events may happen in between (the usual funnel). A person who does a, x, b
     * has done a then b.
     */
    case Ordered = 'ordered';

    /**
     * No event may interrupt the sequence: between two steps of the chain the person has no other event at all, whatever
     * it is (ClickHouse windowFunnel mode `strict_order`). The first interruption ends the evaluation for that person: a
     * person who does a, x, a, b reaches step 1 only, because the x after the first a stops the chain, although the
     * second a is followed directly by b. Reads EVERY event of the person in the window, not only the steps' events,
     * which makes it the heavier of the two.
     */
    case StrictOrder = 'strict_order';

    /** The windowFunnel mode parameter, or null when the plain mode is used. */
    public function windowFunnelMode(): ?string
    {
        return match ($this) {
            self::Ordered => null,
            self::StrictOrder => 'strict_order',
        };
    }

    /** Whether events that are no step still take part in the aggregation (they interrupt the chain). */
    public function readsEveryEvent(): bool
    {
        return $this === self::StrictOrder;
    }
}
