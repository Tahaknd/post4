<?php

namespace App\Console\Commands;

use App\Actions\Projects\QueueEventsPurge;
use App\Console\Concerns\ResolvesProjects;
use App\Models\Project;
use Illuminate\Console\Command;

class ProjectPurgeEvents extends Command
{
    use ResolvesProjects;

    protected $signature = 'project:purge-events
        {id : Id of a project that no longer exists}
        {--force : Skip the confirmation prompt (non-interactive runs never prompt)}';

    protected $description = 'Queues the removal of a DELETED project\'s ClickHouse events. Exit codes: 0 queued, 1 failure, aborted or the project still exists, 2 invalid id, 4 could not be queued';

    protected $help = <<<'HELP'
Queues the purge of every event of a project id that no longer exists in Postgres (the job on the
`maintenance` queue, then a second pass ten minutes later). It REFUSES a project that still exists: delete the
project first (project:delete), this command only cleans up after it.

Exit codes: 0 queued, 1 the project still exists / declined / failure, 2 invalid id, 4 could not be queued.
HELP;

    public function handle(QueueEventsPurge $queueEventsPurge): int
    {
        $id = (string) $this->argument('id');
        if (! ctype_digit($id) || strlen($id) > 18 || (int) $id < 1) {
            return $this->failWith('Invalid project id.', self::INVALID);
        }

        if (Project::query()->whereKey($id)->exists()) {
            return $this->failWith("Project #{$id} still exists. Delete it first (project:delete); this command only removes the events of deleted projects.", self::FAILURE);
        }

        if (! $this->option('force')
            && $this->input->isInteractive()
            && ! $this->confirm("Delete every ClickHouse event of the deleted project #{$id}?", false)) {
            return $this->failWith('Aborted.', self::FAILURE);
        }

        if (! $queueEventsPurge->handle((int) $id)) {
            return $this->failWith("The purge of project #{$id} could not be queued (is Redis reachable?).", self::PURGE_NOT_QUEUED);
        }

        $this->info("Events purge queued for project #{$id} (a second pass follows in ".intdiv(QueueEventsPurge::SECOND_PASS_DELAY_SECONDS, 60).' minutes). The Horizon worker must be running.');

        return self::SUCCESS;
    }
}
