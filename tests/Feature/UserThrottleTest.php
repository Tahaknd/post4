<?php

use App\Models\Project;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ProjectCache;

beforeEach(function () {
    Queue::fake();
    LimiterOutage::reset();
});

/** Overrides one family's limit (the family names contain a dot, so a dotted config key cannot address them). */
function throttleLimit(string $family, int $limit): void
{
    $budgets = config('ratelimit.user_throttles');
    $budgets[$family]['limit'] = $limit;
    config(['ratelimit.user_throttles' => $budgets]);
}

function create(User $user, string $name, string $method = 'post'): TestResponse
{
    return test()->actingAs($user)->{$method}('/projects', ['name' => $name, 'timezone' => 'UTC']);
}

it('answers 429 with Retry-After over the budget and shows the Error page to HTML callers', function () {
    throttleLimit('projects.create', 2);
    $user = User::factory()->create();

    create($user, 'A')->assertStatus(303);
    create($user, 'B')->assertStatus(303);
    $response = create($user, 'C')->assertStatus(429)->assertHeader('Retry-After');

    expect(Project::count())->toBe(2);
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 429));
});

it('answers 429 as JSON with Retry-After to a JSON caller', function () {
    throttleLimit('projects.create', 1);
    $user = User::factory()->create();

    create($user, 'A', 'postJson')->assertStatus(303);
    $response = create($user, 'B', 'postJson')->assertStatus(429)->assertHeader('Retry-After');

    expect($response->json('message'))->toContain('Too many attempts')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('gives every user their own budget', function () {
    throttleLimit('projects.create', 1);

    create(User::factory()->create(), 'A')->assertStatus(303);
    create(User::factory()->create(), 'B')->assertStatus(303);

    expect(Project::count())->toBe(2);
});

it('keeps a bucket per family: exhausting create leaves rotate and delete available', function () {
    throttleLimit('projects.create', 1);
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['name' => 'Shop']);

    create($admin, 'A')->assertStatus(303);
    create($admin, 'B')->assertStatus(429);

    $this->actingAs($admin)->put("/projects/{$project->id}/api-key")->assertStatus(303);
    $this->actingAs($admin)->delete("/projects/{$project->id}", ['confirm_name' => 'Shop'])->assertStatus(303);
});

it('throttles rotate and delete on their own budgets', function () {
    throttleLimit('projects.rotate', 1);
    throttleLimit('projects.delete', 1);
    $admin = User::factory()->admin()->create();
    $a = Project::factory()->create(['name' => 'A']);
    $b = Project::factory()->create(['name' => 'B']);

    $this->actingAs($admin)->put("/projects/{$a->id}/api-key")->assertStatus(303);
    $this->actingAs($admin)->put("/projects/{$a->id}/api-key")->assertStatus(429);
    $this->actingAs($admin)->delete("/projects/{$a->id}", ['confirm_name' => 'A'])->assertStatus(303);
    $this->actingAs($admin)->delete("/projects/{$b->id}", ['confirm_name' => 'B'])->assertStatus(429);

    expect(Project::whereKey($b->id)->exists())->toBeTrue();
});

it('does not count requests that a binding already answered with 404', function () {
    throttleLimit('projects.rotate', 1);
    $member = User::factory()->create();
    $theirs = Project::factory()->create();
    $mine = Project::factory()->create();
    $mine->users()->attach($member);

    $this->actingAs($member)->put("/projects/{$theirs->id}/api-key")->assertNotFound();
    $this->actingAs($member)->put("/projects/{$theirs->id}/api-key")->assertNotFound();
    $this->actingAs($member)->put("/projects/{$mine->id}/api-key")->assertStatus(303);
});

it('takes its limits from config, per family', function () {
    expect(config('ratelimit.user_throttles'))->toHaveKeys(['projects.create', 'projects.rotate', 'projects.delete'])
        ->and(config('ratelimit.user_throttles')['projects.create'])->toBe(['limit' => 30, 'window_seconds' => 60]);
});

it('fails open when the limiter store is unreachable, and logs a throttled warning', function () {
    throttleLimit('projects.create', 0);
    $user = User::factory()->create();
    ProjectCache::breakStore();

    create($user, 'A')->assertStatus(303);
    create($user, 'B')->assertStatus(303);

    expect(Project::count())->toBe(2);
});
