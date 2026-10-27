<?php

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesQuery;
use App\Domain\Insights\Runs\InsightGate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * The event-name list against a real ClickHouse (25.8) through the real chain: HTTP request, FormRequest, the
 * single slot, EventNames, the scoped reader, the read-only user (with the shortened max_execution_time and
 * max_result_rows the profile constraints allow), the scoped view. Opt-in (group "clickhouse"):
 *
 *   docker compose exec -T app composer test:clickhouse
 *
 * Fixed rows and hand-computed numbers (see ClickHouseIntegration::seedEventNames()), clock 2026-03-20 08:00 UTC, so
 * the window is [2026-02-19 00:00, 2026-03-21 00:00).
 */

pest()->group('clickhouse');

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.events_cache_ttl_seconds' => 120,
        'insights.events_max_execution_time' => 5,
    ]);
    Cache::store('array')->flush();
    Carbon::setTestNow('2026-03-20 08:00:00 UTC');

    $this->ch = CH::connect();
    $this->ch->seedEventNamesOnce();
    app()->instance(EventNames::class, new EventNames($this->ch->reader, new EventNamesQuery, app(InsightGate::class), Cache::store('array')));

    $this->projects = [];
    foreach (['a' => CH::EVENTS_A, 'b' => CH::EVENTS_B, 'c' => CH::EVENTS_C, 'd' => CH::EVENTS_D] as $key => $id) {
        $this->projects[$key] = Project::factory()->create(['id' => $id, 'timezone' => 'UTC']);
        $this->{'member'.strtoupper($key)} = User::factory()->create();
        $this->projects[$key]->users()->attach($this->{'member'.strtoupper($key)});
    }
});

afterEach(fn () => Carbon::setTestNow());

afterAll(function () {
    CH::cleanupEventNamesAfterAll();
});

function chNames(User $user, Project $project, string $q = ''): TestResponse
{
    return test()->actingAs($user)->getJson("/api/projects/{$project->id}/insights/events".($q === '' ? '' : '?q='.rawurlencode($q)));
}

/** name => count of an answer. */
function chMap(TestResponse $response): array
{
    $response->assertOk();

    return array_column($response->json('events'), 'count', 'name');
}

it('lists the names of each tenant with exact counts, most frequent first, then by name', function () {
    $a = chNames($this->memberA, $this->projects['a'])->assertOk();

    expect($a->json('truncated'))->toBeFalse()
        ->and($a->json('events'))->toBe([
            ['name' => 'signup', 'count' => 5],
            ['name' => '$pageview', 'count' => 3],
            ['name' => 'a%b_c', 'count' => 2],
            ['name' => 'purchase', 'count' => 2],
            // count 1, by name ascending (byte order: upper case, lower case, then non-ASCII)
            ['name' => 'Sign Out', 'count' => 1],
            ['name' => 'back\\slash', 'count' => 1],
            ['name' => 'edge_before_end', 'count' => 1],
            ['name' => 'edge_start', 'count' => 1],
            ['name' => "it's", 'count' => 1],
            ['name' => str_repeat('x', 200), 'count' => 1],
            ['name' => 'ünï日本', 'count' => 1],
        ]);

    expect(chMap(chNames($this->memberB, $this->projects['b'])))->toBe(['signup' => 7, 'b_only' => 2]);
});

it('keeps tenants apart: a member never sees another project, and the other project is a 404', function () {
    $names = array_keys(chMap(chNames($this->memberA, $this->projects['a'])));

    expect($names)->not->toContain('b_only')->not->toContain('z_top');
    expect(array_keys(chMap(chNames($this->memberB, $this->projects['b']))))->not->toContain('purchase');

    chNames($this->memberA, $this->projects['b'])->assertNotFound();
    chNames($this->memberB, $this->projects['a'])->assertNotFound();
});

it('counts 30 whole UTC days: the start instant is inside, the instant before it is outside, so is the end instant', function () {
    $names = chMap(chNames($this->memberA, $this->projects['a']));

    expect($names)->toHaveKey('edge_start')
        ->and($names)->toHaveKey('edge_before_end')
        ->and($names)->toHaveKey('signup')
        ->and($names['signup'])->toBe(5) // the 12:00 one is later today, still today's UTC day
        ->and($names)->not->toHaveKey('old_event')
        ->and($names)->not->toHaveKey('edge_end');
});

it('moves the window with the clock', function () {
    Carbon::setTestNow('2026-03-21 00:00:00 UTC');
    $next = chMap(chNames($this->memberA, $this->projects['a']));

    // window [2026-02-20, 2026-03-22): edge_start (02-19) falls out, edge_end (03-21 00:00) comes in
    expect($next)->not->toHaveKey('edge_start')->and($next)->toHaveKey('edge_end');
});

it('leaves out names the trends definition could not take (more than 200 characters) and keeps one of exactly 200', function () {
    $names = chMap(chNames($this->memberA, $this->projects['a']));

    expect($names)->toHaveKey(str_repeat('x', 200))->and($names)->not->toHaveKey(str_repeat('y', 201));
});

it('returns 100 names and truncated true when there are more, ties by name', function () {
    $c = chNames($this->memberC, $this->projects['c'])->assertOk();

    expect($c->json('events'))->toHaveCount(100)
        ->and($c->json('truncated'))->toBeTrue()
        ->and($c->json('events.0'))->toBe(['name' => 'z_top', 'count' => 3])
        ->and($c->json('events.1.name'))->toBe('n000')
        ->and($c->json('events.99.name'))->toBe('n098');
});

it('does not say truncated for exactly 100 names', function () {
    $d = chNames($this->memberD, $this->projects['d'])->assertOk();

    expect($d->json('events'))->toHaveCount(100)->and($d->json('truncated'))->toBeFalse()->and($d->json('events.99.name'))->toBe('d099');
});

it('filters by a case-insensitive substring and counts only what matches', function (string $q, array $expected) {
    expect(chMap(chNames($this->memberA, $this->projects['a'], $q)))->toBe($expected);
})->with([
    'prefix' => ['sign', ['signup' => 5, 'Sign Out' => 1]],
    'upper case' => ['SIGN', ['signup' => 5, 'Sign Out' => 1]],
    'inside the name' => ['view', ['$pageview' => 3]],
    'with spaces around' => ['  purchase  ', ['purchase' => 2]],
    'no match' => ['zzzz', []],
    'unicode' => ['ünï', ['ünï日本' => 1]],
    'unicode upper case' => ['ÜNÏ', ['ünï日本' => 1]],
    'cjk' => ['日本', ['ünï日本' => 1]],
]);

it('treats % _ quotes and backslashes in q as plain characters', function (string $q, array $expected) {
    expect(chMap(chNames($this->memberA, $this->projects['a'], $q)))->toBe($expected);
})->with([
    'percent is not a wildcard' => ['%', ['a%b_c' => 2]],
    'underscore is not a wildcard' => ['_', ['a%b_c' => 2, 'edge_before_end' => 1, 'edge_start' => 1]],
    'percent and underscore together' => ['%b_', ['a%b_c' => 2]],
    'a wildcard that would match everything matches nothing' => ['%%', []],
    'single quote' => ["'", ["it's" => 1]],
    'quote and text' => ["it's", ["it's" => 1]],
    'sql injection text' => ["' OR '1'='1", []],
    'backslash' => ['\\', ['back\\slash' => 1]],
    'backslash then text' => ['k\\s', ['back\\slash' => 1]],
    'dollar sign' => ['$page', ['$pageview' => 3]],
    'placeholder text' => ['{q:String}', []],
]);

it('answers the same list from the cache without a second ClickHouse query', function () {
    $first = chNames($this->memberA, $this->projects['a'])->json();
    // the rows would answer differently now, but the entry is cached
    $this->ch->admin->execute("INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) SELECT generateUUIDv4(), {p:UInt32}, 'late', 'u', '{}', parseDateTime64BestEffort('2026-03-10 10:00:00', 3, 'UTC')", ['p' => CH::EVENTS_A]);

    try {
        $second = chNames($this->memberA, $this->projects['a'])->json();
        expect($second)->toBe($first);

        Cache::store('array')->flush();
        expect(chMap(chNames($this->memberA, $this->projects['a'])))->toHaveKey('late');
    } finally {
        $this->ch->admin->execute('DELETE FROM posthog.events WHERE project_id = {p:UInt32} AND event = {e:String}', ['p' => CH::EVENTS_A, 'e' => 'late'], settings: ['lightweight_deletes_sync' => 2]);
    }
});

it('answers 429 busy while the one slot is held and works again after it is released', function () {
    $held = app(InsightGate::class)->acquireEventNames();

    chNames($this->memberA, $this->projects['a'])->assertStatus(429)->assertHeader('Retry-After', '2');

    $held->release();
    chNames($this->memberA, $this->projects['a'])->assertOk();
});

it('runs with the short time limit under the real read-only profile (the constraint allows it)', function () {
    config(['insights.events_max_execution_time' => 1]);

    chNames($this->memberA, $this->projects['a'])->assertOk();
});
