<?php

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\Runs\InsightGate;
use App\Http\ApiErrors;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Models\Project;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FailingCacheStore;
use Tests\Support\ProjectCache;
use Tests\Support\ScriptedReads;
use Tests\Support\SlowReads;

/*
 * The event-name list of the picker (GET /api/projects/{project}/insights/events): authorisation matrix,
 * validation, cache and its TTL, the single slot (busy, fail-open), error mapping. ClickHouse is scripted; the real
 * thing is InsightEventNamesClickHouseTest.
 */

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.events_cache_ttl_seconds' => 120,
        'insights.events_empty_search_cache_ttl_seconds' => 300,
        'insights.events_max_execution_seconds' => 5,
        'insights.events_lock_ttl_seconds' => 15,
        'insights.events_wait_ms' => 0,
        'insights.events_project_budget_ms' => 10000,
    ]);
    Cache::store('array')->flush();
    LimiterOutage::reset();
    Carbon::setTestNow('2026-03-15 10:00:00 UTC');
    $this->project = Project::factory()->create(['timezone' => 'UTC']);
    $this->member = User::factory()->create();
    $this->project->users()->attach($this->member);
});

afterEach(fn () => Carbon::setTestNow());

/** Rebinds the service over a scripted ClickHouse (the array store is the insight store). */
function scriptEvents(array $answers): ScriptedReads
{
    $reads = new ScriptedReads($answers);
    app()->instance(EventNames::class, new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, app(InsightGate::class), Cache::store('array')));

    return $reads;
}

function namesRows(array $counts): array
{
    $rows = [];
    foreach ($counts as $name => $count) {
        $rows[] = ['name' => $name, 'total' => $count];
    }

    return $rows;
}

function eventsGet(User $user, Project|int $project, string $query = ''): TestResponse
{
    $id = $project instanceof Project ? $project->id : $project;

    return test()->actingAs($user)->getJson("/api/projects/{$id}/insights/events".($query === '' ? '' : '?'.$query));
}

describe('authorisation', function () {
    it('answers a guest with 401 JSON and never a redirect or a session', function () {
        $response = $this->call('GET', "/api/projects/{$this->project->id}/insights/events");

        expect($response->getStatusCode())->toBe(401)
            ->and($response->headers->has('Location'))->toBeFalse()
            ->and($response->headers->getCookies())->toBe([]);
    });

    it('shows a non-member exactly what a missing or deleted project looks like', function () {
        $outsider = User::factory()->create();
        $deleted = Project::factory()->create();
        $deletedId = $deleted->id;
        $deleted->delete();
        $reads = scriptEvents([]);

        $forbidden = eventsGet($outsider, $this->project)->assertNotFound();
        $missing = eventsGet($outsider, 999999)->assertNotFound();
        $gone = eventsGet($this->member, $deletedId)->assertNotFound();

        expect($forbidden->getContent())->toBe($missing->getContent())->toBe($gone->getContent())
            ->and($forbidden->json())->toBe(ApiErrors::body('not_found', 'Not found.'))
            ->and($reads->calls)->toBe([]);
    });

    it('lets a member and an administrator who is not a member read the list', function () {
        scriptEvents([namesRows(['a' => 2]), namesRows(['b' => 1])]);
        $admin = User::factory()->admin()->create();

        eventsGet($this->member, $this->project)->assertOk()->assertJsonPath('events.0.name', 'a');
        Cache::store('array')->flush();
        eventsGet($admin, $this->project)->assertOk()->assertJsonPath('events.0.name', 'b');
    });

    it('answers every other verb on the route with a fixed JSON 404 and nothing else', function (string $method) {
        $reads = scriptEvents([]);

        $response = $this->actingAs($this->member)->json($method, "/api/projects/{$this->project->id}/insights/events");

        expect($response->getStatusCode())->toBeIn([404, 405])->and($reads->calls)->toBe([]);
    })->with(['POST', 'PUT', 'DELETE']);

    it('does not start a run or touch the queue', function () {
        Queue::fake();
        scriptEvents([namesRows(['a' => 1])]);

        eventsGet($this->member, $this->project)->assertOk();

        Queue::assertNothingPushed();
    });
});

describe('answer', function () {
    it('returns the names with their counts, in the order ClickHouse gave, not truncated', function () {
        scriptEvents([namesRows(['signup' => 40, '$pageview' => 12, 'purchase' => 3])]);

        $response = eventsGet($this->member, $this->project)->assertOk();

        expect($response->json())->toBe([
            'events' => [['name' => 'signup', 'count' => 40], ['name' => '$pageview', 'count' => 12], ['name' => 'purchase', 'count' => 3]],
            'truncated' => false,
        ])->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('answers an empty list for a project without events', function () {
        scriptEvents([[]]);

        expect(eventsGet($this->member, $this->project)->assertOk()->json())->toBe(['events' => [], 'truncated' => false]);
    });

    it('cuts the 101st row off and says the list is truncated', function () {
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = ['name' => sprintf('e%03d', $i), 'total' => 200 - $i];
        }
        scriptEvents([$rows]);

        $json = eventsGet($this->member, $this->project)->assertOk()->json();

        expect($json['events'])->toHaveCount(100)->and($json['truncated'])->toBeTrue()->and($json['events'][99]['name'])->toBe('e099');
    });

    it('does not say truncated for exactly 100 names', function () {
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = ['name' => sprintf('e%03d', $i), 'total' => 5];
        }
        scriptEvents([$rows]);

        $json = eventsGet($this->member, $this->project)->assertOk()->json();

        expect($json['events'])->toHaveCount(100)->and($json['truncated'])->toBeFalse();
    });

    it('asks ClickHouse with the short time limit, a small row cap, the project and typed parameters only', function () {
        $reads = scriptEvents([[]]);

        eventsGet($this->member, $this->project, 'q='.rawurlencode("Sign'Up"))->assertOk();

        $call = $reads->calls[0];
        expect($call['settings'])->toMatchArray(['max_execution_time' => 5, 'max_result_rows' => 101, 'SQL_project_id' => $this->project->id])
            ->and($call['timeout'])->toBe(8)
            ->and($call['params'])->toMatchArray(['q' => "sign'up"])
            ->and($call['sql'])->not->toContain('sign')->not->toContain("'up")
            ->and($call['params']['from']->format('Y-m-d'))->toBe('2026-02-14')
            ->and($call['params']['to']->format('Y-m-d'))->toBe('2026-03-16');
    });

    it('takes the limits of a config override and never a value from the request', function () {
        config(['insights.events_max_execution_seconds' => 2]);
        $reads = scriptEvents([[]]);

        eventsGet($this->member, $this->project, 'max_execution_time=99&settings=1&limit=5000')->assertOk();

        expect($reads->calls[0]['settings']['max_execution_time'])->toBe(2)->and($reads->calls[0]['params'])->not->toHaveKey('limit');
    });
});

describe('search text', function () {
    it('treats a missing, empty and whitespace-only q as no search', function (string $query) {
        $reads = scriptEvents([[]]);

        eventsGet($this->member, $this->project, $query)->assertOk();

        expect($reads->calls[0]['params'])->not->toHaveKey('q');
    })->with(['', 'q=', 'q=%20%20', 'q=%09', 'q=%C2%A0']);

    it('normalises q: trimmed and lower case', function () {
        $reads = scriptEvents([[]]);

        eventsGet($this->member, $this->project, 'q='.rawurlencode("  SignUp \t"))->assertOk();

        expect($reads->calls[0]['params']['q'])->toBe('signup');
    });

    it('passes hostile text through as a parameter and answers normally', function (string $q) {
        $reads = scriptEvents([namesRows(['x' => 1])]);

        eventsGet($this->member, $this->project, 'q='.rawurlencode($q))->assertOk();

        expect($reads->calls[0]['params']['q'])->toBe(mb_strtolower($q))
            ->and($reads->calls[0]['sql'])->toContain('{q:String}');
    })->with([
        "'; DROP TABLE posthog.events; --",
        '100%_wild',
        'back\\slash',
        '{evil:String}',
        '$$x$$',
        'ünïcödé 日本語 😀',
        str_repeat('a', 100),
    ]);

    it('rejects too long text, control characters and a non-string q with the fixed 422 shape, asking nothing', function (string $query) {
        $reads = scriptEvents([]);

        $response = eventsGet($this->member, $this->project, $query)->assertStatus(422);

        expect($response->json('error.kind'))->toBe('validation')
            ->and($response->json('errors.q.0'))->toBe('The search text must be at most 100 characters of plain text.')
            ->and($response->json('message'))->toBe('The search text must be at most 100 characters of plain text.')
            ->and($response->getContent())->not->toContain('aaaa')->not->toContain('DROP')
            ->and($reads->calls)->toBe([]);
    })->with([
        'too long' => ['q='.'aaaaaaaaaa'.'aaaaaaaaaa'.'aaaaaaaaaa'.'aaaaaaaaaa'.'aaaaaaaaaa'.'aaaaaaaaaa'.'aaaaaaaaaa'.'aaaaaaaaaa'.'aaaaaaaaaa'.'aaaaaaaaaa'.'a'],
        'newline inside' => ['q=a%0Ab'],
        'nul byte' => ['q=a%00b'],
        'escape character' => ['q=a%1Bb'],
        'array' => ['q[]=a&q[]=b'],
        'map' => ['q[x]=DROP'],
    ]);

    it('answers malformed UTF-8 in the query string with a fixed 400 JSON before the controller (RejectMalformedUtf8)', function () {
        $reads = scriptEvents([]);

        $response = eventsGet($this->member, $this->project, 'q=%FF%FE');

        expect($response->getStatusCode())->toBe(400)->and($response->json('error.kind'))->toBe('bad_request')->and($reads->calls)->toBe([]);
    });

    it('counts characters, not bytes, for the length limit', function () {
        scriptEvents([[]]);

        eventsGet($this->member, $this->project, 'q='.rawurlencode(str_repeat('日', 100)))->assertOk();
        eventsGet($this->member, $this->project, 'q='.rawurlencode(str_repeat('日', 101)))->assertStatus(422);
    });
});

describe('cache', function () {
    it('serves the second identical request from the cache, per project and normalised text', function () {
        $reads = scriptEvents([namesRows(['a' => 1]), namesRows(['b' => 2])]);

        $first = eventsGet($this->member, $this->project)->assertOk()->json();
        $second = eventsGet($this->member, $this->project)->assertOk()->json();
        eventsGet($this->member, $this->project, 'q=A')->assertOk();
        eventsGet($this->member, $this->project, 'q=%20a%20')->assertOk();

        expect($second)->toBe($first)->and($reads->calls)->toHaveCount(2);
    });

    it('keeps the entries of two projects and two search texts apart', function () {
        $other = Project::factory()->create(['timezone' => 'UTC']);
        $other->users()->attach($this->member);
        $reads = scriptEvents([namesRows(['mine' => 1]), namesRows(['theirs' => 9]), namesRows(['mine-filtered' => 1])]);

        $mine = eventsGet($this->member, $this->project)->assertOk()->json('events.0.name');
        $theirs = eventsGet($this->member, $other)->assertOk()->json('events.0.name');
        $filtered = eventsGet($this->member, $this->project, 'q=mine')->assertOk()->json('events.0.name');

        expect([$mine, $theirs, $filtered])->toBe(['mine', 'theirs', 'mine-filtered'])->and($reads->calls)->toHaveCount(3);
        // and each is now a hit for its own key only
        expect(eventsGet($this->member, $this->project)->json('events.0.name'))->toBe('mine')
            ->and(eventsGet($this->member, $other)->json('events.0.name'))->toBe('theirs')
            ->and($reads->calls)->toHaveCount(3);
    });

    it('stores the entry with the configured TTL and it expires', function () {
        $reads = scriptEvents([namesRows(['a' => 1]), namesRows(['a' => 2])]);
        $key = EventNames::cacheKey($this->project->id, 'a');

        eventsGet($this->member, $this->project, 'q=a')->assertOk();
        expect(Cache::store('array')->has($key))->toBeTrue();

        Carbon::setTestNow(now()->addSeconds(119));
        eventsGet($this->member, $this->project, 'q=a')->assertOk();
        expect($reads->calls)->toHaveCount(1);

        Carbon::setTestNow(now()->addSeconds(2));
        eventsGet($this->member, $this->project, 'q=a')->assertOk()->assertJsonPath('events.0.count', 2);
        expect($reads->calls)->toHaveCount(2);
    });

    it('keeps the list without a search text longer (300 s), since every picker asks for it first', function () {
        $reads = scriptEvents([namesRows(['a' => 1]), namesRows(['a' => 2])]);

        eventsGet($this->member, $this->project)->assertOk();
        Carbon::setTestNow(now()->addSeconds(299));
        eventsGet($this->member, $this->project)->assertOk();
        expect($reads->calls)->toHaveCount(1);

        Carbon::setTestNow(now()->addSeconds(2));
        eventsGet($this->member, $this->project)->assertOk()->assertJsonPath('events.0.count', 2);
        expect($reads->calls)->toHaveCount(2);
    });

    it('caches only successful answers: a failure is asked again, never replayed', function () {
        $reads = scriptEvents([new ClickHouseException('Code: 159. raw', 159, ClickHouseErrorKind::Timeout), namesRows(['a' => 1])]);

        eventsGet($this->member, $this->project)->assertStatus(503);
        expect(Cache::store('array')->has(EventNames::cacheKey($this->project->id, null)))->toBeFalse();

        eventsGet($this->member, $this->project)->assertOk()->assertJsonPath('events.0.name', 'a');
        expect($reads->calls)->toHaveCount(2);
    });

    it('does not cache at all with a TTL of 0', function () {
        config(['insights.events_cache_ttl_seconds' => 0, 'insights.events_empty_search_cache_ttl_seconds' => 0]);
        $reads = scriptEvents([[], []]);

        eventsGet($this->member, $this->project)->assertOk();
        eventsGet($this->member, $this->project)->assertOk();

        expect($reads->calls)->toHaveCount(2);
    });

    it('ignores a cache entry of the wrong shape instead of answering it', function () {
        $reads = scriptEvents([namesRows(['fresh' => 1])]);
        Cache::store('array')->put(EventNames::cacheKey($this->project->id, null), ['events' => 'oops'], 60);

        eventsGet($this->member, $this->project)->assertOk()->assertJsonPath('events.0.name', 'fresh');
        expect($reads->calls)->toHaveCount(1);
    });

    it('has sensible defaults: a 60-300 s cache, a 3 s ClickHouse limit below the profile maximum, locks that outlive the query', function () {
        $config = require base_path('config/insights.php');

        expect($config['events_cache_ttl_seconds'])->toBeBetween(60, 300)
            ->and($config['events_empty_search_cache_ttl_seconds'])->toBe(300)
            ->and($config['events_max_execution_seconds'])->toBe(3)->toBeBetween(1, 22)
            ->and($config['events_lock_ttl_seconds'])->toBeGreaterThan($config['events_max_execution_seconds'] + 3)
            ->and($config['events_wait_ms'])->toBe(1200)
            ->and($config['events_project_budget_ms'])->toBe(10000);

        $source = file_get_contents(base_path('config/insights.php'));
        foreach (["'INSIGHTS_EVENTS_CACHE_TTL_SECONDS', 120", "'INSIGHTS_EVENTS_EMPTY_SEARCH_CACHE_TTL_SECONDS', 300", "'INSIGHTS_EVENTS_MAX_EXECUTION_SECONDS', 3", "'INSIGHTS_EVENTS_LOCK_TTL_SECONDS', 15", "'INSIGHTS_EVENTS_WAIT_MS', 1200", "'INSIGHTS_EVENTS_PROJECT_BUDGET_MS', 10000"] as $needle) {
            expect($source)->toContain($needle);
        }
        $env = file_get_contents(base_path('.env.local.example'));
        foreach (['INSIGHTS_EVENTS_CACHE_TTL_SECONDS', 'INSIGHTS_EVENTS_EMPTY_SEARCH_CACHE_TTL_SECONDS', 'INSIGHTS_EVENTS_MAX_EXECUTION_SECONDS', 'INSIGHTS_EVENTS_LOCK_TTL_SECONDS', 'INSIGHTS_EVENTS_WAIT_MS', 'INSIGHTS_EVENTS_PROJECT_BUDGET_MS'] as $name) {
            expect($env)->toContain($name);
        }
        // one variable per line: no second variable hidden in a comment
        expect($env)->not->toContain('INSIGHTS_EVENTS_MAX_EXECUTION_SECONDS=3  # ClickHouse seconds for that list (the profile allows 1-22); INSIGHTS_');
    });

    it('raises the lock TTL above the query limit when it is configured too low', function () {
        config(['insights.events_max_execution_seconds' => 10, 'insights.events_lock_ttl_seconds' => 5]);

        expect(InsightGate::eventLockSeconds())->toBe(15);

        config(['insights.events_max_execution_seconds' => 3, 'insights.events_lock_ttl_seconds' => 15]);
        expect(InsightGate::eventLockSeconds())->toBe(15);
    });
});

describe('the single slot', function () {
    it('answers 429 busy with Retry-After 2 when the slot is taken, without asking ClickHouse', function () {
        $reads = scriptEvents([namesRows(['a' => 1])]);
        $held = app(InsightGate::class)->acquireEventNames();
        expect($held)->not->toBeNull();

        $response = eventsGet($this->member, $this->project)->assertStatus(429)->assertHeader('Retry-After', '2');

        expect($response->json())->toBe(ApiErrors::body('busy', 'The event list is busy right now. Try again in a moment.', true, 2))
            ->and($reads->calls)->toBe([]);

        // the slot is free again once the holder lets go
        $held->release();
        eventsGet($this->member, $this->project)->assertOk();
    });

    it('serves a cached answer even while the slot is taken', function () {
        scriptEvents([namesRows(['a' => 1])]);
        eventsGet($this->member, $this->project)->assertOk();
        $held = app(InsightGate::class)->acquireEventNames();

        eventsGet($this->member, $this->project)->assertOk()->assertJsonPath('events.0.name', 'a');
        $held->release();
    });

    it('frees the slot after a success and after a failure', function () {
        scriptEvents([namesRows(['a' => 1]), new ClickHouseException('Code: 241. raw', 241, ClickHouseErrorKind::MemoryLimit)]);

        eventsGet($this->member, $this->project, 'q=a')->assertOk();
        eventsGet($this->member, $this->project, 'q=b')->assertStatus(503);

        expect(app(InsightGate::class)->acquireEventNames())->not->toBeNull();
    });

    it('is one GLOBAL slot: another project is busy too', function () {
        $other = Project::factory()->create(['timezone' => 'UTC']);
        $other->users()->attach($this->member);
        scriptEvents([]);
        $held = app(InsightGate::class)->acquireEventNames();

        eventsGet($this->member, $other)->assertStatus(429);
        $held->release();
    });

    it('fails OPEN when the slot store is down: the list is still answered', function () {
        app()->instance(EventNames::class, new EventNames(
            new ProjectScopedReader(new ScriptedReads([namesRows(['a' => 1])])),
            new EventNamesSqlBuilder,
            // the real gate over a store that throws on every call
            new InsightGate(new Repository(new FailingCacheStore)),
            Cache::store('array'),
        ));

        expect(eventsGet($this->member, $this->project)->assertOk()->json('events.0.name'))->toBe('a');
    });

    it('uses the lock key and TTL of the config and an owner-checked lock', function () {
        $gate = app(InsightGate::class);
        $permit = $gate->acquireEventNames();

        expect(InsightGate::EVENT_NAMES_KEY)->toBe('insights:events:slot')
            ->and($gate->acquireEventNames())->toBeNull();

        $permit->release();
        expect($gate->acquireEventNames())->not->toBeNull();
    });
});

describe('fairness: one tenant cannot hold the shared slot', function () {
    /** Rebinds the service over reads that take `$ms` each and always answer one name. */
    function slowEvents(int $ms): SlowReads
    {
        $reads = new SlowReads($ms);
        app()->instance(EventNames::class, new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, app(InsightGate::class), Cache::store('array')));

        return $reads;
    }

    beforeEach(function () {
        $this->other = Project::factory()->create(['timezone' => 'UTC']);
        $this->otherMember = User::factory()->create();
        $this->other->users()->attach($this->otherMember);
    });

    it('stops a tenant that hammers distinct search texts once its minute budget is spent, and never touches the other tenant', function () {
        config(['insights.events_project_budget_ms' => 150]);
        $reads = slowEvents(60);

        $codes = [];
        foreach (range(1, 8) as $i) {
            $codes[] = eventsGet($this->member, $this->project, "q=hammer{$i}")->getStatusCode();
        }

        // 60 ms per query: the budget of 150 ms is spent after the third, the rest never reach ClickHouse
        expect($codes)->toBe([200, 200, 200, 429, 429, 429, 429, 429])->and($reads->calls)->toBe(3);

        // the other tenant is served at once, as often as it likes within ITS budget
        eventsGet($this->otherMember, $this->other, 'q=mine1')->assertOk();
        eventsGet($this->otherMember, $this->other, 'q=mine2')->assertOk();
        expect($reads->calls)->toBe(5);

        // answers the hammering tenant already has stay available from the cache
        eventsGet($this->member, $this->project, 'q=hammer1')->assertOk();
        expect($reads->calls)->toBe(5);
    });

    it('answers the exhausted tenant with 429 busy and a Retry-After up to the next minute (at most 10 s), and serves it again then', function () {
        config(['insights.events_project_budget_ms' => 50]);
        Carbon::setTestNow('2026-03-15 10:00:41 UTC');
        slowEvents(60);

        eventsGet($this->member, $this->project, 'q=a')->assertOk();
        $response = eventsGet($this->member, $this->project, 'q=b')->assertStatus(429)->assertHeader('Retry-After', '10');
        expect($response->json('error.kind'))->toBe('busy')->and($response->json('error.retry_after_seconds'))->toBe(10);

        Carbon::setTestNow('2026-03-15 10:00:55 UTC');
        eventsGet($this->member, $this->project, 'q=b')->assertStatus(429)->assertHeader('Retry-After', '5');

        Carbon::setTestNow('2026-03-15 10:01:00 UTC');
        eventsGet($this->member, $this->project, 'q=b')->assertOk();
    });

    it('keeps the minute budget with a TTL and charges at least a millisecond per query', function () {
        slowEvents(0);
        eventsGet($this->member, $this->project, 'q=a')->assertOk();

        $key = InsightGate::eventBudgetKey($this->project->id, intdiv(now()->timestamp, 60));
        expect((int) Cache::store('array')->get($key))->toBeGreaterThanOrEqual(1)
            ->and(app(InsightGate::class)->eventNamesUsedMs($this->project->id))->toBeGreaterThanOrEqual(1);
    });

    it('lets one project have only one event query in flight: a second search text is answered 429, another project is not affected', function () {
        $reads = slowEvents(0);
        $held = app(InsightGate::class)->acquireEventNamesProject($this->project->id);
        expect($held)->not->toBeNull();

        eventsGet($this->member, $this->project, 'q=second')->assertStatus(429)->assertHeader('Retry-After', '2');
        expect($reads->calls)->toBe(0);

        eventsGet($this->otherMember, $this->other, 'q=second')->assertOk();

        $held->release();
        eventsGet($this->member, $this->project, 'q=second')->assertOk();
    });

    it('does not run an identical search twice at once: while one request asks ClickHouse the others wait for its answer, not for a slot', function () {
        $reads = slowEvents(0);
        $flight = app(InsightGate::class)->acquireEventNamesFlight($this->project->id, hash('sha256', 'same'));
        expect($flight)->not->toBeNull();

        // no wait budget: the follower answers busy, and has not asked ClickHouse
        eventsGet($this->member, $this->project, 'q=same')->assertStatus(429);
        expect($reads->calls)->toBe(0);

        // the holder's answer shows up in the cache: the follower serves it without querying
        Cache::store('array')->put(EventNames::cacheKey($this->project->id, 'same'), ['events' => [['name' => 'same', 'count' => 3]], 'truncated' => false], 60);
        eventsGet($this->member, $this->project, 'q=same')->assertOk()->assertJsonPath('events.0.count', 3);
        expect($reads->calls)->toBe(0);
        $flight->release();
    });

    it('waits a short bounded time for the global slot before it answers 429', function () {
        config(['insights.events_wait_ms' => 250]);
        $reads = slowEvents(0);
        $held = app(InsightGate::class)->acquireEventNames();

        $start = hrtime(true);
        eventsGet($this->member, $this->project, 'q=x')->assertStatus(429)->assertHeader('Retry-After', '2');
        $waited = (hrtime(true) - $start) / 1e6;

        expect($waited)->toBeGreaterThanOrEqual(200)->toBeLessThan(1500)->and($reads->calls)->toBe(0);
        $held->release();
    });

    it('never waits when the wait budget is 0 and does not leave any lock behind after a failure', function () {
        $reads = new ScriptedReads([new ClickHouseException('Code: 241. raw', 241, ClickHouseErrorKind::MemoryLimit)]);
        app()->instance(EventNames::class, new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, app(InsightGate::class), Cache::store('array')));

        eventsGet($this->member, $this->project, 'q=a')->assertStatus(503);

        $gate = app(InsightGate::class);
        expect($gate->acquireEventNames())->not->toBeNull()
            ->and($gate->acquireEventNamesProject($this->project->id))->not->toBeNull()
            ->and($gate->acquireEventNamesFlight($this->project->id, hash('sha256', 'a')))->not->toBeNull();
    });

    it('charges a failed query too (a tenant cannot dodge the budget by failing)', function () {
        $reads = new ScriptedReads([new ClickHouseException('Code: 159. raw', 159, ClickHouseErrorKind::Timeout)]);
        app()->instance(EventNames::class, new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, app(InsightGate::class), Cache::store('array')));

        eventsGet($this->member, $this->project, 'q=a')->assertStatus(503);

        expect(app(InsightGate::class)->eventNamesUsedMs($this->project->id))->toBeGreaterThanOrEqual(1);
    });

    it('runs live and without any gate when the store is down (nothing to coordinate with), and caches nothing', function () {
        $reads = new ScriptedReads([namesRows(['a' => 1])]);
        $broken = new Repository(new FailingCacheStore);
        app()->instance(EventNames::class, new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, new InsightGate($broken), $broken));

        eventsGet($this->member, $this->project, 'q=a')->assertOk()->assertJsonPath('events.0.name', 'a');
        expect($reads->calls)->toHaveCount(1);
    });
});

describe('failures', function () {
    it('maps every ClickHouse kind to a fixed answer without any server text', function (ClickHouseErrorKind $kind, int $code, int $status, string $apiKind, bool $retryable) {
        scriptEvents([new ClickHouseException("Code: {$code}. DB::Exception: SECRET-RAW select from posthog.events", $code, $kind)]);

        $response = eventsGet($this->member, $this->project, 'q=zz');

        expect($response->getStatusCode())->toBe($status)
            ->and($response->json('error.kind'))->toBe($apiKind)
            ->and($response->json('error.retryable'))->toBe($retryable)
            ->and($response->json('message'))->toBe($response->json('error.message'))
            ->and($response->getContent())->not->toContain('SECRET-RAW')->not->toContain('posthog.events')->not->toContain('Code: ');
    })->with([
        'busy' => [ClickHouseErrorKind::Busy, 202, 429, 'busy', true],
        'timeout' => [ClickHouseErrorKind::Timeout, 159, 503, 'timeout', false],
        'memory' => [ClickHouseErrorKind::MemoryLimit, 241, 503, 'memory_limit', false],
        'rows' => [ClickHouseErrorKind::TooManyRows, 396, 503, 'too_many_rows', false],
        'constraint' => [ClickHouseErrorKind::ConstraintViolation, 452, 500, 'constraint_violation', false],
        'auth' => [ClickHouseErrorKind::AuthenticationFailed, 516, 500, 'authentication_failed', false],
        'unknown' => [ClickHouseErrorKind::Unknown, 1, 500, 'unknown', false],
    ]);

    it('sets Retry-After for busy (3) and unavailable (5) and for no other kind', function () {
        scriptEvents([new ClickHouseException('Code: 202. raw', 202, ClickHouseErrorKind::Busy)]);
        eventsGet($this->member, $this->project, 'q=1')->assertStatus(429)->assertHeader('Retry-After', '3')->assertJsonPath('error.retry_after_seconds', 3);

        scriptEvents([new ConnectionException('cURL error 7 SECRET-HOST clickhouse:8123')]);
        $response = eventsGet($this->member, $this->project, 'q=2')->assertStatus(503)->assertHeader('Retry-After', '5');
        expect($response->json('error.kind'))->toBe('unavailable')->and($response->json('error.retryable'))->toBeTrue()
            ->and($response->getContent())->not->toContain('SECRET-HOST')->not->toContain('8123');

        scriptEvents([new ClickHouseException('Code: 159. raw', 159, ClickHouseErrorKind::Timeout)]);
        expect(eventsGet($this->member, $this->project, 'q=3')->assertStatus(503)->headers->has('Retry-After'))->toBeFalse();
    });

    it('answers a dead cache store by running the query live and caches nothing', function () {
        $reads = new ScriptedReads([namesRows(['a' => 1]), namesRows(['a' => 1])]);
        ProjectCache::breakStore();
        app()->instance(EventNames::class, new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, app(InsightGate::class), Cache::store('failing')));

        eventsGet($this->member, $this->project)->assertOk()->assertJsonPath('events.0.name', 'a');
        eventsGet($this->member, $this->project)->assertOk();

        expect($reads->calls)->toHaveCount(2);
    });
});

describe('throttle', function () {
    it('answers 429 JSON with Retry-After over the user budget, counted per user', function () {
        $budgets = config('ratelimit.user_throttles');
        $budgets['insights.events']['limit'] = 2;
        config(['ratelimit.user_throttles' => $budgets]);
        scriptEvents([[], [], []]);
        $other = User::factory()->create();
        $this->project->users()->attach($other);

        eventsGet($this->member, $this->project)->assertOk();
        eventsGet($this->member, $this->project)->assertOk();
        $response = eventsGet($this->member, $this->project)->assertStatus(429)->assertHeader('Retry-After');

        expect($response->json('error.kind'))->toBe('rate_limited');
        eventsGet($other, $this->project)->assertOk();
    });

    it('does not count a request the binder answered 404 for', function () {
        $budgets = config('ratelimit.user_throttles');
        $budgets['insights.events']['limit'] = 1;
        config(['ratelimit.user_throttles' => $budgets]);
        scriptEvents([[]]);
        $outsider = User::factory()->create();

        eventsGet($outsider, $this->project)->assertNotFound();
        eventsGet($outsider, $this->project)->assertNotFound();
        eventsGet($this->member, $this->project)->assertOk();
    });

    it('pins the budget of the family', function () {
        expect(config('ratelimit.user_throttles')['insights.events'])->toBe(['limit' => 120, 'window_seconds' => 60]);
    });
});
