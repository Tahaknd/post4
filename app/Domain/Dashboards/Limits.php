<?php

namespace App\Domain\Dashboards;

use Illuminate\Validation\ValidationException;

/**
 * The per-project limits (config/dashboards.php) and the one answer for hitting them: a 422 on the field `limit`
 * (a limit is a rule about the request in the state of the project, like any other). The actions check them under a
 * row lock, so the number is exact under concurrency.
 */
final class Limits
{
    public static function savedInsights(): int
    {
        return max(1, (int) config('dashboards.max_saved_insights_per_project'));
    }

    public static function dashboards(): int
    {
        return max(1, (int) config('dashboards.max_dashboards_per_project'));
    }

    public static function tiles(): int
    {
        return max(1, (int) config('dashboards.max_tiles_per_dashboard'));
    }

    public static function reached(string $what, int $max): ValidationException
    {
        return ValidationException::withMessages(['limit' => "A project can have at most {$max} {$what}. Delete one first."]);
    }

    private function __construct() {}
}
