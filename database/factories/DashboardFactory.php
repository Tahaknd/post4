<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fixtures only: the application writes through App\Actions\Dashboards.
 *
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'project_id' => Project::factory(),
            'name' => fake()->words(2, true),
            'description' => null,
            'last_viewed_at' => null,
            'created_by' => null,
        ];
    }
}
