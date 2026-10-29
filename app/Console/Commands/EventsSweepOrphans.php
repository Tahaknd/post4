<?php

namespace App\Console\Commands;

use App\Infrastructure\ClickHouse\EventProjectIds;
use App\Infrastructure\ClickHouse\PersonDistinctIdProjectIds;
use App\Jobs\PurgeProjectEvents;
use App\Jobs\PurgeProjectPersonDistinctIds;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EventsSweepOrphans extends Command
{
    protected $signature = 'events:sweep-orphans {--dry-run : Only list the orphaned project ids, queue nothing}';

    protected $description = 'Queues a purge for every project id that has ClickHouse events or person_distinct_ids rows but no project row (scheduled daily)';

    protected $help = <<<'HELP'
Reconciles ClickHouse with Postgres: it lists the distinct project ids of posthog.events and of
posthog.person_distinct_ids, subtracts the ids that exist in Postgres and queues PurgeProjectEvents and
PurgeProjectPersonDistinctIds (maintenance queue) for the rest of each table. It catches what a deletion
leaves behind: rows that were still in Kafka after both purge passes, a purge that failed for good, a project
deleted with --keep-events, or one deleted while Redis was down. Only ids are printed and logged. The purge jobs
themselves refuse a project that exists, so this command cannot delete a live project's rows.

WARNING: restoring or resetting Postgres next to a persistent ClickHouse is UNSAFE. Project ids restart from the
backup's sequence, ClickHouse still holds the events of ids that backup does not know, and this sweeper would
purge them as orphans (the new projects that reuse such ids would inherit foreign events until then). Back up and
restore the two databases together.
HELP;

    public function handle(EventProjectIds $eventProjects, PersonDistinctIdProjectIds $personProjects): int
    {
        $events = $this->orphansAmong($eventProjects->all());
        $persons = $this->orphansAmong($personProjects->all());

        if ($events === [] && $persons === []) {
            $this->line('No orphaned project ids in ClickHouse.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('Orphaned project ids (dry run, nothing queued): '.implode(', ', array_values(array_unique([...$events, ...$persons]))));
            $this->describeTables($events, $persons);

            return self::SUCCESS;
        }

        foreach ($events as $id) {
            PurgeProjectEvents::dispatch($id, pass: 1);
        }
        foreach ($persons as $id) {
            PurgeProjectPersonDistinctIds::dispatch($id, pass: 1);
        }

        Log::info('events.sweep_orphans', ['project_ids' => $events, 'person_distinct_ids_project_ids' => $persons]);
        $this->line('Queued a purge for orphaned project ids: '.implode(', ', array_values(array_unique([...$events, ...$persons]))));
        $this->describeTables($events, $persons);

        return self::SUCCESS;
    }

    /**
     * The ids (without 0) that have no project row in Postgres, ascending.
     *
     * @param  list<int>  $inClickHouse
     * @return list<int>
     */
    private function orphansAmong(array $inClickHouse): array
    {
        $inClickHouse = array_values(array_filter($inClickHouse, fn (int $id) => $id > 0));

        $existing = [];
        foreach (array_chunk($inClickHouse, 1000) as $chunk) {
            array_push($existing, ...Project::query()->whereIn('id', $chunk)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_diff($inClickHouse, $existing));
    }

    /**
     * @param  list<int>  $events
     * @param  list<int>  $persons
     */
    private function describeTables(array $events, array $persons): void
    {
        $this->line('  events: '.($events === [] ? 'none' : implode(', ', $events)));
        $this->line('  person_distinct_ids: '.($persons === [] ? 'none' : implode(', ', $persons)));
    }
}
