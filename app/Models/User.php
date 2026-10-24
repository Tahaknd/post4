<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

// is_admin is deliberately not fillable: no request field can ever promote a user.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * Projects the user is an explicit member of. Admins can reach every project regardless, so use
     * canAccessProject() / Project::visibleTo() for authorisation, not this relation.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->using(ProjectUser::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Admins implicitly access every project; everyone else needs a project_user row.
     */
    public function canAccessProject(Project $project): bool
    {
        return $this->is_admin || $this->projects()->whereKey($project->getKey())->exists();
    }

    /**
     * Emails are stored trimmed and lowercased, so the unique index (case-sensitive in Postgres) and
     * the login lookup agree on what "the same address" means.
     */
    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => self::normaliseEmail($value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}
