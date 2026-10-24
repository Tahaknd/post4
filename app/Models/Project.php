<?php

namespace App\Models;

use App\Observers\ProjectObserver;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[ObservedBy(ProjectObserver::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    public const API_KEY_PREFIX = 'phc_';

    public const API_KEY_RANDOM_LENGTH = 43;

    public const API_KEY_PATTERN = '/^'.self::API_KEY_PREFIX.'[A-Za-z0-9]{'.self::API_KEY_RANDOM_LENGTH.'}\z/';

    protected $fillable = ['name', 'api_key', 'timezone'];

    public static function generateApiKey(): string
    {
        return self::API_KEY_PREFIX.Str::random(self::API_KEY_RANDOM_LENGTH);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(ProjectUser::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Projects the user may open: every project for an admin, otherwise the ones they are a member of.
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->is_admin) {
            $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->getKey()));
        }
    }
}
