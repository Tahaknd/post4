<?php

namespace App\Actions\Dashboards;

use App\Models\Dashboard;

/**
 * Notes that a dashboard was opened (`last_viewed_at`), the signal that keeps it in the scheduled refresh. Throttled to
 * one write per dashboard per `dashboards.view_touch_seconds`: the loaded model is checked first (no query when the
 * stamp is fresh), and the UPDATE repeats the condition, so concurrent views write once. `updated_at` is not touched
 * (a view is not an edit).
 */
final class RecordDashboardView
{
    /** @return bool true when this call wrote the stamp */
    public function handle(Dashboard $dashboard): bool
    {
        $window = max(0, (int) config('dashboards.view_touch_seconds'));
        $now = now();

        if ($dashboard->last_viewed_at !== null && $dashboard->last_viewed_at->gt($now->copy()->subSeconds($window))) {
            return false;
        }

        $written = Dashboard::query()
            ->whereKey($dashboard->getKey())
            ->where(fn ($query) => $query->whereNull('last_viewed_at')->orWhere('last_viewed_at', '<=', $now->copy()->subSeconds($window)))
            ->toBase()
            ->update(['last_viewed_at' => $now]) > 0;

        if ($written) {
            $dashboard->last_viewed_at = $now;
            $dashboard->syncOriginalAttribute('last_viewed_at');
        }

        return $written;
    }
}
