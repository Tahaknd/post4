<?php

namespace App\Console\Commands;

use App\Domain\Dashboards\Refresh\DashboardRefresh;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DashboardsRefresh extends Command
{
    protected $signature = 'dashboards:refresh
        {--log : Also write ONE summary line to the application log; the schedule uses this}';

    protected $description = 'Re-runs the saved insights of recently viewed dashboards so that opening one is answered from the cache';

    protected $help = <<<'HELP'
Queues runs for the saved insights of dashboards that were opened within dashboards.refresh.viewed_within_days,
on the low priority `insights-refresh` queue, through the same gate, caps and single flight as every other insight
run. It never queries ClickHouse itself. Results land in the dashboard result cache (config/dashboards.php).

A tick is skipped (and says why) when DASHBOARDS_REFRESH_ENABLED is off, ClickHouse cannot answer, or the insight
queues are backed up. Insights that need review are never run. At most max_insights_per_tick runs (and
max_insights_per_project_per_tick per project) are queued; a result younger than stale_after_seconds is left alone.

The scheduler runs it every dashboards.refresh.interval_minutes (15) with --log: `docker compose logs scheduler`.
Exit code 0 (also when the tick is skipped on purpose), 1 when the run store or queue is down.
HELP;

    public function handle(DashboardRefresh $refresh): int
    {
        $summary = $refresh->handle();

        $this->line($summary->line());

        if ($this->option('log')) {
            Log::log($summary->aborted ? 'warning' : 'info', 'dashboards:refresh', $summary->toArray());
        }

        return $summary->aborted ? self::FAILURE : self::SUCCESS;
    }
}
