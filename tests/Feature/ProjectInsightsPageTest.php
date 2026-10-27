<?php

use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\DatePreset;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\FilterOperator;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\PropertyFilter;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/** The Trends page: authorisation, pinned props (allowed values and limits from the domain), server-built URLs, no key. */
beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Shop', 'timezone' => 'Europe/Istanbul']);
    $this->member = User::factory()->create();
    $this->project->users()->attach($this->member);
});

it('redirects a guest to the login page', function () {
    $this->get("/projects/{$this->project->id}/insights/trends")->assertRedirect("/login?next=%2Fprojects%2F{$this->project->id}%2Finsights%2Ftrends");
});

it('shows a non-member, a missing and a deleted project the same 404', function () {
    $outsider = User::factory()->create();
    $gone = Project::factory()->create();
    $goneId = $gone->id;
    $gone->delete();

    $forbidden = $this->actingAs($outsider)->get("/projects/{$this->project->id}/insights/trends")->assertNotFound();
    $missing = $this->actingAs($outsider)->get('/projects/999999/insights/trends')->assertNotFound();
    $deleted = $this->actingAs($this->member)->get("/projects/{$goneId}/insights/trends")->assertNotFound();

    foreach ([$forbidden, $missing, $deleted] as $response) {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404));
    }
    // the outsider's pages say nothing about the project (the member's own nav lists it, as it should)
    expect($forbidden->getContent())->not->toContain('Shop')->and($missing->getContent())->not->toContain('Shop');
});

it('rejects a malformed project segment with a 404, not a server error', function (string $segment) {
    $this->actingAs($this->member)->get("/projects/{$segment}/insights/trends")->assertNotFound();
})->with(['abc', '1.5', '0x1', '99999999999999999999999', '-1']);

it('opens for a member and for an administrator who is not a member', function () {
    $this->actingAs($this->member)->get("/projects/{$this->project->id}/insights/trends")
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Insights/Trends'));

    $this->actingAs(User::factory()->admin()->create())->get("/projects/{$this->project->id}/insights/trends")
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Insights/Trends'));
});

it('carries exactly the pinned props, with the project reduced to id, name and timezone', function () {
    $this->actingAs($this->member)->get("/projects/{$this->project->id}/insights/trends")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Projects/Insights/Trends')
            ->has('project', fn ($project) => $project->where('id', $this->project->id)->where('name', 'Shop')->where('timezone', 'Europe/Istanbul'))
            ->has('options')->has('defaults')->has('limits')->has('urls'));

    $props = $this->actingAs($this->member)->get("/projects/{$this->project->id}/insights/trends")->viewData('page')['props'];

    expect(array_keys($props['project']))->toBe(['id', 'name', 'timezone'])
        ->and(array_keys($props['options']))->toBe(['presets', 'intervals', 'metrics', 'breakdown_columns', 'operators'])
        ->and(array_keys($props['defaults']))->toBe(['date_preset', 'interval', 'metric', 'top_n'])
        ->and(array_keys($props['limits']))->toBe([
            'max_events', 'max_event_length', 'max_filters', 'max_filter_key_length', 'max_filter_value_length', 'max_breakdown_key_length',
            'max_top_n', 'max_range_days', 'max_rows', 'max_search_length', 'event_window_days',
        ])
        ->and(array_keys($props['urls']))->toBe(['trends', 'runs', 'events', 'project']);
});

it('builds the API urls on the server and never carries a key', function () {
    $response = $this->actingAs($this->member)->get("/projects/{$this->project->id}/insights/trends");
    $props = $response->viewData('page')['props'];

    expect($props['urls'])->toBe([
        'trends' => "/api/projects/{$this->project->id}/insights/trends",
        'runs' => "/api/projects/{$this->project->id}/insights/runs/",
        'events' => "/api/projects/{$this->project->id}/insights/events",
        'project' => "/projects/{$this->project->id}",
    ])
        ->and(json_encode($props))->not->toContain('phc_')->not->toContain('api_key')->not->toContain($this->project->api_key)
        ->and($response->getContent())->not->toContain($this->project->api_key);
});

it('takes every allowed value from the domain enums', function () {
    $options = $this->actingAs($this->member)->get("/projects/{$this->project->id}/insights/trends")->viewData('page')['props']['options'];

    expect(array_column($options['presets'], 'value'))->toBe(array_column(DatePreset::cases(), 'value'))
        ->and(array_column($options['intervals'], 'value'))->toBe(array_column(Interval::cases(), 'value'))
        ->and(array_column($options['metrics'], 'value'))->toBe(array_column(Metric::cases(), 'value'))
        ->and(array_column($options['breakdown_columns'], 'value'))->toBe(array_column(BreakdownColumn::cases(), 'value'))
        ->and(array_column($options['operators'], 'value'))->toBe(array_column(FilterOperator::cases(), 'value'))
        ->and(array_column($options['operators'], 'needs_value', 'value'))->toBe(['equals' => true, 'not_equals' => true, 'contains' => true, 'is_set' => false]);

    foreach ($options as $list) {
        foreach ($list as $option) {
            expect($option['label'])->toBeString()->not->toBe('');
        }
    }
});

it('takes the limits from the domain constants and the defaults from the API defaults', function () {
    $props = $this->actingAs($this->member)->get("/projects/{$this->project->id}/insights/trends")->viewData('page')['props'];

    expect($props['limits'])->toBe([
        'max_events' => TrendsQuery::MAX_SERIES,
        'max_event_length' => TrendsQuery::MAX_EVENT_LENGTH,
        'max_filters' => TrendsQuery::MAX_FILTERS,
        'max_filter_key_length' => PropertyFilter::MAX_KEY_LENGTH,
        'max_filter_value_length' => PropertyFilter::MAX_VALUE_LENGTH,
        'max_breakdown_key_length' => Breakdown::MAX_KEY_LENGTH,
        'max_top_n' => Breakdown::MAX_TOP_N,
        'max_range_days' => DateRange::MAX_DAYS,
        'max_rows' => TrendsQuery::MAX_ROWS,
        'max_search_length' => EventNamesSqlBuilder::MAX_SEARCH_LENGTH,
        'event_window_days' => EventNamesSqlBuilder::WINDOW_DAYS,
    ])->and($props['limits'])->toMatchArray(['max_events' => 10, 'max_filters' => 10, 'max_top_n' => 25])
        ->and($props['defaults'])->toBe(['date_preset' => 'last_30_days', 'interval' => 'day', 'metric' => 'total_events', 'top_n' => 10]);
});

it('is served with encrypted history like every authenticated page and its props point nowhere outside the app', function () {
    $response = $this->actingAs($this->member)->get("/projects/{$this->project->id}/insights/trends");

    expect($response->viewData('page')['encryptHistory'])->toBeTrue()
        ->and(json_encode($response->viewData('page')['props']['urls']))->not->toContain('http');
});

it('shares the Trends link of the project on screen and null elsewhere', function () {
    $onProject = $this->actingAs($this->member)->get("/projects/{$this->project->id}")->viewData('page')['props'];
    $elsewhere = $this->actingAs($this->member)->get('/projects')->viewData('page')['props'];

    expect($onProject['trendsUrl'])->toBe("/projects/{$this->project->id}/insights/trends")->and($elsewhere['trendsUrl'])->toBeNull();
});

it('is a GET only route and cannot be written to', function () {
    $url = "/projects/{$this->project->id}/insights/trends";

    $this->actingAs($this->member)->post($url)->assertStatus(405);
    $this->actingAs($this->member)->put($url)->assertStatus(405);
    $this->actingAs($this->member)->delete($url)->assertStatus(405);
});

it('names the page route projects.insights.trends under /projects', function () {
    expect(route('projects.insights.trends', ['project' => 7], absolute: false))->toBe('/projects/7/insights/trends');
});

it('offers the enrichment columns as breakdown options with labels, taken from the server', function () {
    $options = $this->actingAs($this->member)->get("/projects/{$this->project->id}/insights/trends")->viewData('page')['props']['options'];

    expect(array_column($options['breakdown_columns'], 'label', 'value'))->toMatchArray([
        'browser' => 'Browser',
        'country_code' => 'Country',
        'os' => 'Operating system',
        'device_type' => 'Device type',
        'utm_source' => 'UTM source',
        'utm_medium' => 'UTM medium',
        'utm_campaign' => 'UTM campaign',
    ]);
});
