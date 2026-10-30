<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved insight on one dashboard, at a position. Written only through App\Actions\Dashboards.
 */
class DashboardTile extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Dashboard, $this> */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /** @return BelongsTo<SavedInsight, $this> */
    public function savedInsight(): BelongsTo
    {
        return $this->belongsTo(SavedInsight::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
