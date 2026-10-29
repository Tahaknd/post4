<?php

namespace App\Domain\Insights\Query;

/** The date part of a definition input is unusable (unknown preset, impossible calendar day, end before start, longer than the maximum). */
final class InvalidDateSelection extends InvalidInsightQuery {}
