<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A row of project_user: a user's membership of a project.
 */
class ProjectUser extends Pivot
{
    // The table has its own id column.
    public $incrementing = true;

    protected $table = 'project_user';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => ProjectRole::class];
    }
}
