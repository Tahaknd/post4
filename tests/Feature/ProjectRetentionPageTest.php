<?php

use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\Query\DatePreset;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\EventCondition;
use App\Domain\Insights\Query\EventName;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Retention\RetentionPeriod;
use App\Domain\Insights\Retention\RetentionQuery;
use App\Domain\Insights\Retention\RetentionType;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/** The Retention page: authorisation, pinned props (allowed values and limits from the domain), server-built URLs, no key. */
beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Shop', 'timezone' => 'Europe/Istanbul']);
    $this->member = User::factory()->create();
    $this->project->users()->attach($this->member);
    $this->url = "/projects/{$this->project->id}/insights/retention";
});

it('redirects a guest to the login page', function () {
    $this->get($this->url)->assertRedirect("/login?next=%2Fprojects%2F{$this->project->id}%2Finsights%2Fretention");
});

it('shows a non-member, a missing and a deleted project the same 404', function () {
    $outsider = User::factory()->create();
    $gone = Project::factory()->create();
    $goneId = $gone->id;
    $gone->delete();

    $forbidden = $this->actingAs($outsider)->get($this->url)->assertNotFound();
    $missing = $this->actingAs($outsider)->get('/projects/999999/insights/retention')->assertNotFound();
    $deleted = $this->actingAs($this->member)->get("/projects/{$goneId}/insights/retention")->assertNotFound();

    foreach ([$forbidden, $missing, $deleted] as $response) {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404));
    }
    expect($forbidden->getContent())->not->toContain('Shop')->and($missing->getContent())->not->toContain('Shop');
});

it('rejects a malformed project segment with a 404, not a server error', function (string $segment) {
    $this->actingAs($this->member)->get("/projects/{$segment}/insights/retention")->assertNotFound();
})->with(['abc', '1.5', '0x1', '99999999999999999999999', '-1']);

it('opens for a member and for an administrator who is not a member', function () {
    $this->actingAs($this->member)->get($this->url)
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Insights/Retention'));

    $this->actingAs(User::factory()->admin()->create())->get($this->url)
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Insights/Retention'));
});

it('carries exactly the pinned props, with the project reduced to id, name and timezone', function () {
    $props = $this->actingAs($this->member)->get($this->url)->viewData('page')['props'];

    expect($props['project'])->toBe(['id' => $this->project->id, 'name' => 'Shop', 'timezone' => 'Europe/Istanbul'])
        ->and(array_keys($props['options']))->toBe(['presets', 'operators', 'periods', 'retention_types', 'timezones'])
        ->and(array_keys($props['defaults']))->toBe(['date_preset', 'period', 'periods', 'retention_type', 'timezone'])
        ->and(array_keys($props['limits']))->toBe([
            'min_periods', 'max_periods', 'max_event_length', 'max_filters_per_event', 'max_filter_key_length', 'max_filter_value_length',
            'max_range_days', 'max_search_length', 'event_window_days',
        ])
        ->and(array_keys($props['urls']))->toBe(['retention', 'runs', 'events', 'project']);
});

it('builds the API urls on the server and never carries a key', function () {
    $response = $this->actingAs($this->member)->get($this->url);
    $props = $response->viewData('page')['props'];

    expect($props['urls'])->toBe([
        'retention' => "/api/projects/{$this->project->id}/insights/retention",
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
        ->and(array_column($options['periods'], 'value'))->toBe(array_column(RetentionPeriod::cases(), 'value'))
        ->and(array_column($options['periods'], 'label', 'value'))->toBe(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'])
        ->and(array_column($options['retention_types'], 'value'))->toBe(array_column(RetentionType::cases(), 'value'))
        ->and(array_column($options['retention_types'], 'label', 'value'))->toBe(['first_time' => 'First time', 'recurring' => 'Recurring']);

    foreach (['presets', 'operators', 'periods', 'retention_types'] as $key) {
        foreach ($options[$key] as $option) {
            expect($option['label'])->toBeString()->not->toBe('');
        }
    }
});

it('offers exactly the time zones the API accepts, among them the zone of the project', function () {
    $zones = $this->actingAs($this->member)->get($this->url)->viewData('page')['props']['options']['timezones'];

    expect($zones)->toBe(DateTimeZone::listIdentifiers())
        ->and($zones)->toContain('Europe/Istanbul')->toContain('UTC')->toContain('America/New_York')
        ->and(array_is_list($zones))->toBeTrue()
        ->and(count($zones))->toBe(count(array_unique($zones)));

    // every one of them is accepted by the retention definition the page will send
    foreach (['Europe/Istanbul', 'UTC', end($zones)] as $zone) {
        expect(fn () => RetentionQuery::fromInput(
            ['target' => ['event' => 'a'], 'returning' => ['event' => 'b'], 'timezone' => $zone, 'date_preset' => 'last_7_days'],
            new DateTimeZone('UTC'),
            new DateTimeImmutable,
        ))->not->toThrow(Throwable::class);
    }
});

it('takes the limits from the domain constants and the defaults from the API defaults, with the zone of the project', function () {
    $props = $this->actingAs($this->member)->get($this->url)->viewData('page')['props'];

    expect($props['limits'])->toBe([
        'min_periods' => RetentionQuery::MIN_PERIODS,
        'max_periods' => RetentionQuery::MAX_PERIODS,
        'max_event_length' => EventName::MAX_LENGTH,
        'max_filters_per_event' => EventCondition::MAX_FILTERS,
        'max_filter_key_length' => PropertyFilter::MAX_KEY_LENGTH,
        'max_filter_value_length' => PropertyFilter::MAX_VALUE_LENGTH,
        'max_range_days' => DateRange::MAX_DAYS,
        'max_search_length' => EventNamesSqlBuilder::MAX_SEARCH_LENGTH,
        'event_window_days' => EventNamesSqlBuilder::WINDOW_DAYS,
    ])->and($props['limits'])->toMatchArray(['min_periods' => 1, 'max_periods' => 30, 'max_filters_per_event' => 5])
        ->and($props['defaults'])->toBe(['date_preset' => 'last_30_days', 'period' => 'day', 'periods' => 8, 'retention_type' => 'first_time', 'timezone' => 'Europe/Istanbul']);
});

it('defaults to the zone of each project', function () {
    $utc = Project::factory()->create(['timezone' => 'UTC']);
    $utc->users()->attach($this->member);

    expect($this->actingAs($this->member)->get("/projects/{$utc->id}/insights/retention")->viewData('page')['props']['defaults']['timezone'])->toBe('UTC');
});

it('is served with encrypted history like every authenticated page', function () {
    expect($this->actingAs($this->member)->get($this->url)->viewData('page')['encryptHistory'])->toBeTrue();
});

it('shares the Retention, Funnel and Trends links of the project on screen and null elsewhere', function () {
    $onProject = $this->actingAs($this->member)->get("/projects/{$this->project->id}")->viewData('page')['props'];
    $onRetention = $this->actingAs($this->member)->get($this->url)->viewData('page')['props'];
    $elsewhere = $this->actingAs($this->member)->get('/projects')->viewData('page')['props'];

    expect($onProject['retentionUrl'])->toBe($this->url)->and($onRetention['retentionUrl'])->toBe($this->url)
        ->and($onRetention['funnelUrl'])->toBe("/projects/{$this->project->id}/insights/funnel")
        ->and($onRetention['trendsUrl'])->toBe("/projects/{$this->project->id}/insights/trends")
        ->and($elsewhere['retentionUrl'])->toBeNull();
});

it('is a GET only route and cannot be written to', function () {
    $this->actingAs($this->member)->post($this->url)->assertStatus(405);
    $this->actingAs($this->member)->put($this->url)->assertStatus(405);
    $this->actingAs($this->member)->delete($this->url)->assertStatus(405);
});

it('names the page route projects.insights.retention under /projects', function () {
    expect(route('projects.insights.retention', ['project' => 7], absolute: false))->toBe('/projects/7/insights/retention');
});
