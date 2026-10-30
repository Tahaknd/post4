<?php

namespace App\Domain\Dashboards\Refresh;

use App\Domain\Dashboards\SavedQueryResolver;
use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightRunStarter;
use App\Domain\Insights\Runs\RunStart;
use App\Domain\Insights\Runs\StartOptions;
use App\Models\Dashboard;
use App\Models\SavedInsight;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One tick of the scheduled refresh (`dashboards:refresh`): keeps the saved insights of RECENTLY VIEWED dashboards in the
 * dashboard result cache, so that opening a dashboard is answered from Redis. It never queries ClickHouse itself and has no
 * execution path of its own: it asks {@see InsightRunStarter} for runs, as the owner "scheduler" ({@see InsightRunStarter::SYSTEM_USER_ID}),
 * which means the same project caps, single flight and running-query cap as any run, on the lower priority queue
 * (`insights-refresh`, served after `insights`).
 *
 * A tick, in order:
 *  1. Skipped as a whole when it is disabled, ClickHouse cannot answer a trivial query, or the insight queues already hold
 *     more than `max_queue_backlog` jobs (a refresh never adds to a backlog), or the queue cannot be asked.
 *  2. Candidates: the saved insights on the `max_dashboards` most recently viewed dashboards of the last
 *     `viewed_within_days` days. An insight on several dashboards counts once; two insights with the same canonical query
 *     in the SAME project run once (across projects the data differs, so nothing is shared); an insight in the
 *     needs_review state is skipped (it is never run, see SavedQueryResolver).
 *  3. A relative range is resolved to today, so the first tick after local midnight fills the new day's cache key.
 *  4. Cache-hit-aware: a result in the dashboard cache younger than `stale_after_seconds` is left alone.
 *  5. The rest is ordered by the age of its cached result, never computed first, so a tick that cannot do everything
 *     serves the neediest first and every insight gets its turn; then cut to `max_insights_per_tick` and
 *     `max_insights_per_project_per_tick`. Each queued run starts a random 0..`jitter_seconds` later.
 *  6. A result that the interactive cache (60 s) already has is copied to the dashboard cache without a query.
 *     A `busy` answer (the project's own cap) leaves the project's remaining insights for the next tick; an `unavailable`
 *     one (run store or queue down) ends the tick.
 */
final class DashboardRefresh
{
    public function __construct(
        private readonly InsightRunStarter $starter,
        private readonly InsightRunner $runner,
        private readonly SavedQueryResolver $resolver,
        private readonly ClickHouseHealth $clickHouse,
        private readonly QueueBacklog $backlog,
    ) {}

    public function handle(?DateTimeInterface $now = null): RefreshSummary
    {
        $now ??= now();
        $config = (array) config('dashboards.refresh');

        if (($config['enabled'] ?? false) !== true) {
            return new RefreshSummary(skipped: 'disabled');
        }
        if (! $this->clickHouse->isHealthy()) {
            return new RefreshSummary(skipped: 'clickhouse_unhealthy');
        }
        try {
            $backlog = $this->backlog->size();
        } catch (Throwable) {
            return new RefreshSummary(skipped: 'queue_unavailable');
        }
        if ($backlog > (int) $config['max_queue_backlog']) {
            return new RefreshSummary(skipped: 'queue_backed_up');
        }

        $summary = new RefreshSummary;
        $candidates = $this->candidates($now, $config, $summary);

        // Oldest result first (never computed first), the order of discovery among equals.
        usort($candidates, fn (array $a, array $b) => [$a['cachedAt'], $a['order']] <=> [$b['cachedAt'], $b['order']]);

        $perProject = [];
        $blocked = [];
        foreach ($candidates as $candidate) {
            $projectId = $candidate['project'];

            if ($summary->queued >= (int) $config['max_insights_per_tick'] || ($perProject[$projectId] ?? 0) >= (int) $config['max_insights_per_project_per_tick']) {
                $summary->capped++;

                continue;
            }
            if (isset($blocked[$projectId])) {
                $summary->busy++;

                continue;
            }

            $jitter = max(0, (int) $config['jitter_seconds']);
            $start = $this->starter->start($projectId, InsightRunStarter::SYSTEM_USER_ID, $candidate['query'], new StartOptions(
                forDashboard: true,
                queue: (string) $config['queue'],
                delaySeconds: $jitter > 0 ? random_int(0, $jitter) : 0,
            ));

            switch ($start->outcome) {
                case RunStart::QUEUED:
                    $summary->queued++;
                    $perProject[$projectId] = ($perProject[$projectId] ?? 0) + 1;
                    break;
                case RunStart::CACHED:
                    // Costs nothing, so it counts against no budget.
                    if ($start->result !== null) {
                        $this->runner->rememberForDashboard($projectId, $candidate['query'], $start->result);
                    }
                    $summary->fromCache++;
                    break;
                case RunStart::BUSY:
                    $summary->busy++;
                    $blocked[$projectId] = true;
                    break;
                default:
                    $summary->aborted = true;

                    return $summary;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{project: int, query: InsightQuery, cachedAt: int, order: int}>
     */
    private function candidates(DateTimeInterface $now, array $config, RefreshSummary $summary): array
    {
        $since = Carbon::instance($now)->subDays(max(1, (int) $config['viewed_within_days']));
        $dashboards = Dashboard::query()
            ->where('last_viewed_at', '>=', $since)
            ->orderByDesc('last_viewed_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) $config['max_dashboards']))
            ->with('tiles.savedInsight')
            ->get();
        $summary->dashboards = $dashboards->count();

        $staleAfter = max(0, (int) $config['stale_after_seconds']);
        $seenInsights = [];
        $seenQueries = [];
        $candidates = [];

        foreach ($dashboards as $dashboard) {
            foreach ($dashboard->tiles as $tile) {
                $insight = $tile->savedInsight;
                if (! $insight instanceof SavedInsight || isset($seenInsights[$insight->id])) {
                    continue;
                }
                $seenInsights[$insight->id] = true;
                $summary->insights++;

                $query = $this->resolver->resolve($insight, $now)->query;
                if ($query === null) {
                    $summary->invalid++;

                    continue;
                }

                $key = $insight->project_id.'|'.$query->type().'|'.$query->hash();
                if (isset($seenQueries[$key])) {
                    $summary->deduped++;

                    continue;
                }
                $seenQueries[$key] = true;

                $cached = $this->runner->cachedForDashboard((int) $insight->project_id, $query);
                if ($cached !== null && $cached->ageSeconds($now->getTimestamp()) < $staleAfter) {
                    $summary->fresh++;

                    continue;
                }

                $candidates[] = ['project' => (int) $insight->project_id, 'query' => $query, 'cachedAt' => $cached?->computedAt ?? 0, 'order' => count($candidates)];
            }
        }

        return $candidates;
    }
}
