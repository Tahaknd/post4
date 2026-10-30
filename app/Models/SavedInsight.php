<?php

namespace App\Models;

use Database\Factories\SavedInsightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, stored insight definition of a project. `query` is the canonical array of its type as saved; whether it can
 * still be run is decided on read (App\Domain\Dashboards\SavedQueryResolver), never assumed. Written only through
 * App\Actions\SavedInsights.
 */
class SavedInsight extends Model
{
    /** @use HasFactory<SavedInsightFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DashboardTile, $this> */
    public function tiles(): HasMany
    {
        return $this->hasMany(DashboardTile::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['query' => 'array', 'query_version' => 'integer'];
    }
}
