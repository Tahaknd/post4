<?php

use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\Funnel\ConversionWindow;
use App\Domain\Insights\Funnel\FunnelOrder;
use App\Domain\Insights\Funnel\FunnelQuery;
use App\Domain\Insights\Funnel\FunnelStep;
use App\Domain\Insights\Funnel\WindowUnit;
use App\Domain\Insights\Query\DatePreset;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\EventName;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\PropertyFilter;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/** The Funnel page: authorisation, pinned props (allowed values and limits from the domain), server-built URLs, no key. */
beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Shop', 'timezone' => 'Europe/Istanbul']);
    $this->member = User::factory()->create();
    $this->project->users()->attach($this->member);
    $this->url = "/projects/{$this->project->id}/insights/funnel";
});

it('redirects a guest to the login page', function () {
    $this->get($this->url)->assertRedirect("/login?next=%2Fprojects%2F{$this->project->id}%2Finsights%2Ffunnel");
});

it('shows a non-member, a missing and a deleted project the same 404', function () {
    $outsider = User::factory()->create();
    $gone = Project::factory()->create();
    $goneId = $gone->id;
    $gone->delete();

    $forbidden = $this->actingAs($outsider)->get($this->url)->assertNotFound();
    $missing = $this->actingAs($outsider)->get('/projects/999999/insights/funnel')->assertNotFound();
    $deleted = $this->actingAs($this->member)->get("/projects/{$goneId}/insights/funnel")->assertNotFound();

    foreach ([$forbidden, $missing, $deleted] as $response) {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404));
    }
    expect($forbidden->getContent())->not->toContain('Shop')->and($missing->getContent())->not->toContain('Shop');
});

it('rejects a malformed project segment with a 404, not a server error', function (string $segment) {
    $this->actingAs($this->member)->get("/projects/{$segment}/insights/funnel")->assertNotFound();
})->with(['abc', '1.5', '0x1', '99999999999999999999999', '-1']);

it('opens for a member and for an administrator who is not a member', function () {
    $this->actingAs($this->member)->get($this->url)
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Insights/Funnel'));

    $this->actingAs(User::factory()->admin()->create())->get($this->url)
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Insights/Funnel'));
});

it('carries exactly the pinned props, with the project reduced to id, name and timezone', function () {
    $props = $this->actingAs($this->member)->get($this->url)->viewData('page')['props'];

    expect($props['project'])->toBe(['id' => $this->project->id, 'name' => 'Shop', 'timezone' => 'Europe/Istanbul'])
        ->and(array_keys($props['options']))->toBe(['presets', 'operators', 'window_units', 'orders'])
        ->and(array_keys($props['defaults']))->toBe(['date_preset', 'window', 'order'])
        ->and(array_keys($props['limits']))->toBe([
            'min_steps', 'max_steps', 'max_event_length', 'max_filters_per_step', 'max_filters', 'max_filter_key_length', 'max_filter_value_length',
            'max_range_days', 'min_window_seconds', 'max_window_seconds', 'max_window_value', 'max_search_length', 'event_window_days',
        ])
        ->and(array_keys($props['urls']))->toBe(['funnel', 'runs', 'events', 'project']);
});

it('builds the API urls on the server and never carries a key', function () {
    $response = $this->actingAs($this->member)->get($this->url);
    $props = $response->viewData('page')['props'];

    expect($props['urls'])->toBe([
        'funnel' => "/api/projects/{$this->project->id}/insights/funnel",
        'runs' => "/api/projects/{$this->project->id}/insights/runs/",
        'events' => "/api/projects/{$this->project->id}/insights/events",
        'project' => "/projects/{$this->project->id}",
    ])
        ->and(json_encode($props))->not->toContain('phc_')->not->toContain('api_key')->not->toContain($this->project->api_key)
        ->and($response->getContent())->not->toContain($this->project->api_key)
        ->and(json_encode($props['urls']))->not->toContain('http');
});

it('takes every allowed value from the domain enums, with labels', function () {
    $options = $this->actingAs($this->member)->get($this->url)->viewData('page')['props']['options'];

    expect(array_column($options['presets'], 'value'))->toBe(array_column(DatePreset::cases(), 'value'))
        ->and(array_column($options['operators'], 'value'))->toBe(array_column(FilterOperator::cases(), 'value'))
        ->and(array_column($options['operators'], 'needs_value', 'value'))->toBe(['equals' => true, 'not_equals' => true, 'contains' => true, 'is_set' => false])
        ->and(array_column($options['window_units'], 'value'))->toBe(array_column(WindowUnit::cases(), 'value'))
        ->and(array_column($options['window_units'], 'seconds', 'value'))->toBe(['minute' => 60, 'hour' => 3600, 'day' => 86400, 'week' => 604800])
        ->and(array_column($options['orders'], 'value'))->toBe(array_column(FunnelOrder::cases(), 'value'))
        ->and(array_column($options['orders'], 'label', 'value'))->toBe(['ordered' => 'Ordered', 'strict_order' => 'Strict order']);

    foreach (['presets', 'operators', 'window_units', 'orders'] as $key) {
        foreach ($options[$key] as $option) {
            expect($option['label'])->toBeString()->not->toBe('');
        }
    }
});

it('takes the limits from the domain constants and the defaults from the API defaults', function () {
    $props = $this->actingAs($this->member)->get($this->url)->viewData('page')['props'];

    expect($props['limits'])->toBe([
        'min_steps' => FunnelQuery::MIN_STEPS,
        'max_steps' => FunnelQuery::MAX_STEPS,
        'max_event_length' => EventName::MAX_LENGTH,
        'max_filters_per_step' => FunnelStep::MAX_FILTERS,
        'max_filters' => FunnelQuery::MAX_FILTERS,
        'max_filter_key_length' => PropertyFilter::MAX_KEY_LENGTH,
        'max_filter_value_length' => PropertyFilter::MAX_VALUE_LENGTH,
        'max_range_days' => DateRange::MAX_DAYS,
        'min_window_seconds' => ConversionWindow::MIN_SECONDS,
        'max_window_seconds' => ConversionWindow::MAX_SECONDS,
        'max_window_value' => ConversionWindow::MAX_VALUE,
        'max_search_length' => EventNamesSqlBuilder::MAX_SEARCH_LENGTH,
        'event_window_days' => EventNamesSqlBuilder::WINDOW_DAYS,
    ])->and($props['limits'])->toMatchArray(['min_steps' => 2, 'max_steps' => 8, 'max_filters_per_step' => 5, 'max_filters' => 20])
        ->and($props['defaults'])->toBe(['date_preset' => 'last_30_days', 'window' => ['value' => 2, 'unit' => 'week'], 'order' => 'ordered']);
});

it('is served with encrypted history like every authenticated page', function () {
    expect($this->actingAs($this->member)->get($this->url)->viewData('page')['encryptHistory'])->toBeTrue();
});

it('shares the Funnel and Trends links of the project on screen and null elsewhere', function () {
    $onProject = $this->actingAs($this->member)->get("/projects/{$this->project->id}")->viewData('page')['props'];
    $onFunnel = $this->actingAs($this->member)->get($this->url)->viewData('page')['props'];
    $elsewhere = $this->actingAs($this->member)->get('/projects')->viewData('page')['props'];

    expect($onProject['funnelUrl'])->toBe($this->url)->and($onFunnel['funnelUrl'])->toBe($this->url)
        ->and($onFunnel['trendsUrl'])->toBe("/projects/{$this->project->id}/insights/trends")
        ->and($elsewhere['funnelUrl'])->toBeNull();
});

it('is a GET only route and cannot be written to', function () {
    $this->actingAs($this->member)->post($this->url)->assertStatus(405);
    $this->actingAs($this->member)->put($this->url)->assertStatus(405);
    $this->actingAs($this->member)->delete($this->url)->assertStatus(405);
});

it('names the page route projects.insights.funnel under /projects', function () {
    expect(route('projects.insights.funnel', ['project' => 7], absolute: false))->toBe('/projects/7/insights/funnel');
});
