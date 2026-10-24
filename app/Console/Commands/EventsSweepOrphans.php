<?php

namespace App\Console\Commands;

use App\Infrastructure\ClickHouse\EventProjectIds;
use App\Jobs\PurgeProjectEvents;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EventsSweepOrphans extends Command
{
    protected $signature = 'events:sweep-orphans {--dry-run : Only list the orphaned project ids, queue nothing}';

    protected $description = 'Queues a purge for every project id that has ClickHouse events but no project row (scheduled daily)';

    protected $help = <<<'HELP'
Reconciles ClickHouse with Postgres: it lists the distinct project ids of posthog.events, subtracts the ids that
exist in Postgres and queues PurgeProjectEvents (maintenance queue) for the rest. It catches what a deletion
leaves behind: events that were still in Kafka after both purge passes, a purge that failed for good, a project
deleted with --keep-events, or one deleted while Redis was down. Only ids are printed and logged. The purge job
itself refuses a project that exists, so this command cannot delete a live project's events.

WARNING: restoring or resetting Postgres next to a persistent ClickHouse is UNSAFE. Project ids restart from the
backup's sequence, ClickHouse still holds the events of ids that backup does not know, and this sweeper would
purge them as orphans (the new projects that reuse such ids would inherit foreign events until then). Back up and
restore the two databases together.
HELP;

    public function handle(EventProjectIds $eventProjects): int
    {
        $inClickHouse = array_values(array_filter($eventProjects->all(), fn (int $id) => $id > 0));

        $existing = [];
        foreach (array_chunk($inClickHouse, 1000) as $chunk) {
            array_push($existing, ...Project::query()->whereIn('id', $chunk)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        $orphans = array_values(array_diff($inClickHouse, $existing));

        if ($orphans === []) {
            $this->line('No orphaned project ids in ClickHouse.');

            return self::SUCCESS;
        }

        $list = implode(', ', $orphans);

        if ($this->option('dry-run')) {
            $this->line('Orphaned project ids (dry run, nothing queued): '.$list);

            return self::SUCCESS;
        }

        foreach ($orphans as $id) {
            PurgeProjectEvents::dispatch($id, pass: 1);
        }

        Log::info('events.sweep_orphans', ['project_ids' => $orphans]);
        $this->line('Queued a purge for orphaned project ids: '.$list);

        return self::SUCCESS;
    }
}
