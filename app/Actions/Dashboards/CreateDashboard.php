<?php

namespace App\Actions\Dashboards;

use App\Domain\Dashboards\DashboardAudit;
use App\Domain\Dashboards\Labels;
use App\Domain\Dashboards\Limits;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only place that inserts a dashboard (tests/Unit/DashboardWritesArchTest.php keeps it so). The limit per project is
 * counted under a lock on the project row.
 */
final class CreateDashboard
{
    /** @throws ValidationException */
    public function handle(Project $project, string $name, ?string $description = null, ?User $actor = null): Dashboard
    {
        $data = Validator::make(
            ['name' => Labels::normaliseName($name), 'description' => Labels::normaliseDescription($description)],
            ['name' => Labels::name(), 'description' => Labels::description()],
        )->validate();

        return DB::transaction(function () use ($project, $data, $actor): Dashboard {
            Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            if (Dashboard::query()->where('project_id', $project->getKey())->count() >= Limits::dashboards()) {
                throw Limits::reached('dashboards', Limits::dashboards());
            }

            $dashboard = Dashboard::query()->create([
                'uuid' => (string) Str::uuid7(),
                'project_id' => $project->getKey(),
                'name' => $data['name'],
                'description' => $data['description'],
                'created_by' => $actor?->getKey(),
            ]);

            DashboardAudit::record('dashboard.create', $actor, (int) $project->getKey(), ['dashboard' => $dashboard->uuid]);

            return $dashboard;
        });
    }
}
