<?php

use App\Models\Dashboard;
use App\Models\Project;
use App\Models\SavedInsight;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/** The pages of dashboards and saved insights: authorisation, pinned props, server-built paths, no key, no data. */
beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Shop', 'timezone' => 'Europe/Istanbul']);
    $this->member = User::factory()->create();
    $this->project->users()->attach($this->member);
    $this->dashboard = Dashboard::factory()->create(['project_id' => $this->project->id, 'name' => 'Weekly review']);
    $this->base = "/projects/{$this->project->id}";
    $this->urls = [
        'list' => "{$this->base}/dashboards",
        'show' => "{$this->base}/dashboards/{$this->dashboard->uuid}",
        'insights' => "{$this->base}/saved-insights",
    ];
});

it('redirects a guest to the login page on every page', function (string $key) {
    $this->get($this->urls[$key])->assertRedirect('/login?next='.rawurlencode(parse_url($this->urls[$key], PHP_URL_PATH)));
})->with(['list', 'show', 'insights']);

it('opens for a member and for an administrator who is not a member, with the page component of each', function () {
    $components = ['list' => 'Projects/Dashboards/Index', 'show' => 'Projects/Dashboards/Show', 'insights' => 'Projects/SavedInsights/Index'];

    foreach ([$this->member, User::factory()->admin()->create()] as $user) {
        foreach ($components as $key => $component) {
            $this->actingAs($user)->get($this->urls[$key])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component($component));
        }
    }
});

it('answers a non-member, a missing project and a malformed segment with the same 404, naming nothing', function (string $key) {
    $outsider = User::factory()->create();
    $path = fn (string $project) => str_replace("/projects/{$this->project->id}/", "/projects/{$project}/", $this->urls[$key]);

    $forbidden = $this->actingAs($outsider)->get($this->urls[$key])->assertNotFound();
    $missing = $this->actingAs($outsider)->get($path('999999'))->assertNotFound();
    $this->actingAs($this->member)->get($path('abc'))->assertNotFound();

    foreach ([$forbidden, $missing] as $response) {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404));
    }
    expect($forbidden->getContent())->not->toContain('Shop')->not->toContain('Weekly review');
})->with(['list', 'show', 'insights']);

it('answers a dashboard of another project, an unknown uuid and a malformed one with a 404', function () {
    $other = Project::factory()->create();
    $foreign = Dashboard::factory()->create(['project_id' => $other->id, 'name' => 'Secret board']);

    foreach ([$foreign->uuid, '0197a3b2-1c4d-7e5f-8a6b-7c8d9e0f1a2b', 'not-a-uuid', '1'] as $segment) {
        $response = $this->actingAs($this->member)->get("{$this->base}/dashboards/{$segment}")->assertNotFound();
        expect($response->getContent())->not->toContain('Secret board');
    }
});

it('carries exactly the pinned props of the list page, with the project reduced to id, name and timezone', function () {
    $props = $this->actingAs($this->member)->get($this->urls['list'])->viewData('page')['props'];

    expect(array_keys($props['project']))->toBe(['id', 'name', 'timezone'])
        ->and($props['project']['timezone'])->toBe('Europe/Istanbul')
        ->and($props['limits'])->toBe(['max_dashboards' => 50, 'page_size' => 12])
        ->and(array_keys($props['links']))->toBe(['api', 'pages', 'limits']);
});

it('carries the id and name of the dashboard, the run base and the concurrency limit on the dashboard page', function () {
    $props = $this->actingAs($this->member)->get($this->urls['show'])->viewData('page')['props'];

    expect($props['dashboard'])->toBe(['id' => $this->dashboard->uuid, 'name' => 'Weekly review'])
        ->and($props['urls'])->toBe(['runs' => "/api/projects/{$this->project->id}/insights/runs/"])
        ->and($props['limits'])->toBe(['max_tiles' => 30, 'concurrent_runs' => 3]);
});

it('carries the saved insight page limits', function () {
    $props = $this->actingAs($this->member)->get($this->urls['insights'])->viewData('page')['props'];

    expect($props['limits'])->toBe(['max_saved_insights' => 200, 'page_size' => 20]);
});

it('builds every path on the server, with no host and no key', function () {
    $response = $this->actingAs($this->member)->get($this->urls['show']);
    $props = $response->viewData('page')['props'];
    $id = $this->project->id;

    expect($props['links'])->toBe([
        'api' => ['saved_insights' => "/api/projects/{$id}/saved-insights", 'dashboards' => "/api/projects/{$id}/dashboards"],
        'pages' => [
            'saved_insights' => "/projects/{$id}/saved-insights",
            'dashboards' => "/projects/{$id}/dashboards",
            'trends' => "/projects/{$id}/insights/trends",
            'funnel' => "/projects/{$id}/insights/funnel",
            'retention' => "/projects/{$id}/insights/retention",
            'project' => "/projects/{$id}",
        ],
        'limits' => ['name_max' => 120, 'description_max' => 500],
    ])
        ->and(json_encode($props))->not->toContain('http')->not->toContain('phc_')->not->toContain('api_key')->not->toContain($this->project->api_key)
        ->and($response->getContent())->not->toContain($this->project->api_key);
});

it('carries no saved insight, dashboard or tile data besides the id and name of the dashboard on view', function () {
    SavedInsight::factory()->create(['project_id' => $this->project->id, 'name' => 'Hidden insight']);

    foreach (['list', 'insights', 'show'] as $key) {
        $response = $this->actingAs($this->member)->get($this->urls[$key]);
        expect($response->getContent())->not->toContain('Hidden insight');
    }
    expect($this->actingAs($this->member)->get($this->urls['list'])->getContent())->not->toContain('Weekly review');
});

it('does not stamp last_viewed_at: the page reads the dashboard through the API, which does', function () {
    $this->actingAs($this->member)->get($this->urls['show'])->assertOk();

    expect($this->dashboard->fresh()->last_viewed_at)->toBeNull();
});

it('shares the dashboards url of the project on screen and null elsewhere', function () {
    $onProject = $this->actingAs($this->member)->get($this->base)->viewData('page')['props'];
    $onList = $this->actingAs($this->member)->get($this->urls['list'])->viewData('page')['props'];
    $elsewhere = $this->actingAs($this->member)->get('/projects')->viewData('page')['props'];

    expect($onProject['dashboardsUrl'])->toBe($this->urls['list'])->and($onList['dashboardsUrl'])->toBe($this->urls['list'])->and($elsewhere['dashboardsUrl'])->toBeNull();
});

it('puts the dashboard and saved insight pages on the project page', function () {
    $urls = $this->actingAs($this->member)->get($this->base)->viewData('page')['props']['urls'];

    expect($urls['dashboards'])->toBe($this->urls['list'])->and($urls['saved_insights'])->toBe($this->urls['insights']);
});

it('gives the three insight pages the same saving links', function (string $path) {
    $props = $this->actingAs($this->member)->get("{$this->base}/insights/{$path}")->viewData('page')['props'];

    expect($props['saving']['api']['saved_insights'])->toBe("/api/projects/{$this->project->id}/saved-insights")
        ->and($props['saving']['pages']['dashboards'])->toBe($this->urls['list'])
        ->and($props['saving']['limits'])->toBe(['name_max' => 120, 'description_max' => 500]);
})->with(['trends', 'funnel', 'retention']);
