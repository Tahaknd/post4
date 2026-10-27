<?php

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesQuery;
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

/*
 * The event-name list of the picker (GET /api/projects/{project}/insights/events): authorisation matrix,
 * validation, cache and its TTL, the single slot (busy, fail-open), error mapping. ClickHouse is scripted; the real
 * thing is InsightEventNamesClickHouseTest.
 */

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.events_cache_ttl_seconds' => 120,
        'insights.events_max_execution_time' => 5,
        'insights.events_lock_ttl_seconds' => 15,
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
    app()->instance(EventNames::class, new EventNames(new ProjectScopedReader($reads), new EventNamesQuery, app(InsightGate::class), Cache::store('array')));

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
        config(['insights.events_max_execution_time' => 2]);
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
        $key = EventNames::cacheKey($this->project->id, null);

        eventsGet($this->member, $this->project)->assertOk();
        expect(Cache::store('array')->has($key))->toBeTrue();

        Carbon::setTestNow(now()->addSeconds(119));
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
        config(['insights.events_cache_ttl_seconds' => 0]);
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

    it('has a default TTL between 60 and 300 seconds and a time limit below the profile maximum', function () {
        $config = require base_path('config/insights.php');

        expect($config['events_cache_ttl_seconds'])->toBeBetween(60, 300)
            ->and($config['events_max_execution_time'])->toBeBetween(1, 22)->toBeLessThan(20)
            ->and($config['events_lock_ttl_seconds'])->toBeGreaterThan($config['events_max_execution_time'] + 3)
            ->and(file_get_contents(base_path('config/insights.php')))->toContain("env('INSIGHTS_EVENTS_CACHE_TTL_SECONDS', 120)")->toContain("env('INSIGHTS_EVENTS_MAX_EXECUTION_TIME', 5)")->toContain("env('INSIGHTS_EVENTS_LOCK_TTL_SECONDS', 15)")
            ->and(file_get_contents(base_path('.env.example')))->toContain('INSIGHTS_EVENTS_CACHE_TTL_SECONDS')->toContain('INSIGHTS_EVENTS_MAX_EXECUTION_TIME');
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
            new EventNamesQuery,
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
        app()->instance(EventNames::class, new EventNames(new ProjectScopedReader($reads), new EventNamesQuery, app(InsightGate::class), Cache::store('failing')));

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
