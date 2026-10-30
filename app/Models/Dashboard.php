<?php

namespace App\Models;

use Database\Factories\DashboardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, ordered set of saved insights of a project. Written only through App\Actions\Dashboards.
 */
class Dashboard extends Model
{
    /** @use HasFactory<DashboardFactory> */
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
        return $this->hasMany(DashboardTile::class)->orderBy('position')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_viewed_at' => 'datetime'];
    }
}
