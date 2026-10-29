<?php

namespace App\Domain\Insights\Retention;

/**
 * Which people belong to the cohort of a period, and so what "the same people" means in the table. Both count a person as
 * retained in period k when they did the RETURN event in period `cohort + k` (not necessarily in the periods between).
 */
enum RetentionType: string
{
    /**
     * A person starts a cohort ONCE: in the period of their first occurrence of the target event inside the queried window
     * (the periods of the date range plus the follow-up periods; nothing before the range is read, so a person who did it
     * long before the range is "new" in the first period they did it inside it). The usual "new users" table; the cohort
     * sizes add up to the number of people who did the target event.
     */
    case FirstTime = 'first_time';

    /**
     * A person is in the cohort of EVERY period in which they did the target event (and every such period is in the date
     * range): active in week 1 and week 3 means they are in two cohorts. The cohort sizes add up to more than the people.
     */
    case Recurring = 'recurring';
}
