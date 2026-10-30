<?php

namespace App\Actions\Dashboards;

use App\Domain\Dashboards\DashboardAudit;
use App\Domain\Dashboards\Labels;
use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Renames or re-describes a dashboard (the tiles have their own actions). */
final class UpdateDashboard
{
    /**
     * @param  array{name?: string, description?: ?string}  $changes  only the keys present are changed
     *
     * @throws ValidationException
     * @throws ModelNotFoundException the dashboard was deleted meanwhile
     */
    public function handle(Dashboard $dashboard, array $changes, ?User $actor = null): Dashboard
    {
        $rules = [];
        $values = [];
        if (array_key_exists('name', $changes)) {
            $values['name'] = Labels::normaliseName((string) $changes['name']);
            $rules['name'] = Labels::name();
        }
        if (array_key_exists('description', $changes)) {
            $values['description'] = Labels::normaliseDescription($changes['description']);
            $rules['description'] = Labels::description();
        }
        $data = $rules === [] ? [] : Validator::make($values, $rules)->validate();

        return DB::transaction(function () use ($dashboard, $data, $actor): Dashboard {
            $locked = Dashboard::query()->whereKey($dashboard->getKey())->lockForUpdate()->firstOrFail();

            if ($data !== []) {
                $locked->update($data);
                DashboardAudit::record('dashboard.update', $actor, (int) $locked->project_id, ['dashboard' => $locked->uuid, 'fields' => implode(',', array_keys($data))]);
            }

            return $locked;
        });
    }
}
