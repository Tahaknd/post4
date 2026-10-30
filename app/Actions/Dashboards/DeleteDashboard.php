<?php

namespace App\Actions\Dashboards;

use App\Domain\Dashboards\DashboardAudit;
use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Deletes a dashboard and its tiles (foreign key); the saved insights stay. Deleting twice is not an error. */
final class DeleteDashboard
{
    public function handle(Dashboard $dashboard, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($dashboard, $actor): bool {
            $deleted = Dashboard::query()->whereKey($dashboard->getKey())->delete() > 0;

            if ($deleted) {
                DashboardAudit::record('dashboard.delete', $actor, (int) $dashboard->project_id, ['dashboard' => $dashboard->uuid]);
            }

            return $deleted;
        });
    }
}
