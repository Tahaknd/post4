<?php

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\LimiterOutage;
use App\Domain\Capture\ProjectRateLimiter;
use App\Models\Project;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FailingCacheStore;
use Tests\Support\FakeEventProducer;

beforeEach(function () {
    $this->producer = new FakeEventProducer;
    $this->app->instance(EventProducer::class, $this->producer);
    $this->project = Project::factory()->create();
    $this->key = $this->project->api_key;
});

afterEach(function () {
    TrustProxies::flushState();
    LimiterOutage::reset();
});

/** The trusted proxies are applied when the provider boots, so re-boot it after changing the config. */
function bootTrustedProxies(string|array|null $proxies): void
{
    config(['capture.trusted_proxies' => $proxies]);
    app()->getProvider(AppServiceProvider::class)->boot();
}

function captureEvent(string $key, array $extra = []): array
{
    return array_merge(['api_key' => $key, 'event' => 'pageview', 'distinct_id' => 'u1'], $extra);
}

it('accepts a single event and produces a kafka message', function () {
    $this->postJson('/e/', captureEvent($this->key))
        ->assertOk()->assertExactJson(['status' => 1]);

    expect($this->producer->messages)->toHaveCount(1)
        ->and($this->producer->messages[0]['project_id'])->toBe($this->project->id)
        ->and($this->producer->messages[0]['event'])->toBe('pageview');
});

it('accepts batches on every capture path', function (string $path) {
    $this->postJson($path, ['api_key' => $this->key, 'batch' => [
        ['event' => 'a', 'distinct_id' => 'u'],
        ['event' => 'b', 'distinct_id' => 'u'],
    ]])->assertOk();

    expect($this->producer->messages)->toHaveCount(2);
})->with(['/e/', '/e', '/batch/', '/capture/']);

it('accepts a gzip text/plain body with the compression query parameter', function () {
    $body = gzencode(json_encode(captureEvent($this->key, ['event' => 'gz'])));

    $this->call('POST', '/e/?compression=gzip-js', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertOk();

    expect($this->producer->messages[0]['event'])->toBe('gz');
});

it('accepts a Content-Encoding gzip raw body', function () {
    $body = gzencode(json_encode(captureEvent($this->key, ['event' => 'ce'])));

    $this->call('POST', '/e/', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
    ], $body)->assertOk();

    expect($this->producer->messages[0]['event'])->toBe('ce');
});

it('accepts a real urlencoded form body with base64 data', function () {
    $json = json_encode(captureEvent($this->key, ['event' => 'form']));

    $this->call('POST', '/e/?ver=1.2.3&compression=base64', [], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], 'data='.urlencode(base64_encode($json)))->assertOk();

    expect($this->producer->messages)->toHaveCount(1)
        ->and($this->producer->messages[0]['event'])->toBe('form');
});

it('accepts a form body whose base64 plus signs arrive as spaces', function () {
    // Ensure the base64 contains "+" so the test is meaningful.
    $json = json_encode(captureEvent($this->key, ['event' => 'form+plus', 'properties' => ['x' => '>>>???~~~']]));
    $base64 = base64_encode($json);
    expect($base64)->toContain('+');

    $this->call('POST', '/e/?compression=base64', [], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], 'data='.str_replace('+', ' ', $base64))->assertOk();

    expect($this->producer->messages[0]['event'])->toBe('form+plus');
});

it('takes the api key from properties.token only', function () {
    $this->postJson('/e/', ['event' => 'e', 'distinct_id' => 'u', 'properties' => ['token' => $this->key]])
        ->assertOk();

    expect($this->producer->messages)->toHaveCount(1);
});

it('rejects empty batches and empty bodies with 400', function () {
    $this->postJson('/e/', ['api_key' => $this->key, 'batch' => []])->assertStatus(400)
        ->assertJsonPath('type', 'capture_error')->assertJsonPath('code', 'no_events');
    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], '')->assertStatus(400);
    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);
    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]')->assertStatus(400);

    expect($this->producer->messages)->toBeEmpty();
});

it('rejects malformed bodies with 400 and does not echo input', function () {
    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json secret-marker')
        ->assertStatus(400)
        ->assertJsonPath('type', 'capture_error')
        ->assertJsonPath('code', 'invalid_payload');
});

it('rejects batches over the configured size with 413', function () {
    config(['capture.max_batch_size' => 2]);

    $this->postJson('/e/', ['api_key' => $this->key, 'batch' => [
        ['event' => 'a', 'distinct_id' => 'u'], ['event' => 'b', 'distinct_id' => 'u'], ['event' => 'c', 'distinct_id' => 'u'],
    ]])->assertStatus(413)->assertJsonPath('code', 'batch_too_large');

    expect($this->producer->messages)->toBeEmpty();
});

it('rejects oversized bodies with 413', function () {
    config(['capture.max_body_bytes' => 10]);

    $this->postJson('/e/', captureEvent($this->key))->assertStatus(413);
    expect($this->producer->messages)->toBeEmpty();
});

it('rejects an oversized declared Content-Length with 413', function () {
    $this->call('POST', '/e/', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_LENGTH' => '5000000',
    ], json_encode(captureEvent($this->key)))->assertStatus(413);
});

it('rejects unknown api keys with 401', function () {
    $unknown = Project::generateApiKey();

    $this->postJson('/e/', captureEvent($unknown))->assertUnauthorized()
        ->assertJsonPath('type', 'capture_error')->assertJsonPath('code', 'invalid_api_key');
    $this->postJson('/e/', ['event' => 'e', 'distinct_id' => 'u'])->assertUnauthorized();

    expect($this->producer->messages)->toBeEmpty();
});

it('rejects malformed key formats with 401 without touching the database', function (string $key) {
    DB::enableQueryLog();

    $this->postJson('/e/', captureEvent($key))->assertUnauthorized();

    expect(DB::getQueryLog())->toBeEmpty()
        ->and($this->producer->messages)->toBeEmpty();
})->with(['short' => 'phc_short', 'x' => 'x', 'huge' => fn () => str_repeat('a', 5000)]);

it('negative-caches well-formed unknown keys', function () {
    $unknown = Project::generateApiKey();

    DB::enableQueryLog();
    $this->postJson('/e/', captureEvent($unknown))->assertUnauthorized();
    expect(DB::getQueryLog())->toHaveCount(1);

    DB::flushQueryLog();
    $this->postJson('/e/', captureEvent($unknown))->assertUnauthorized();
    expect(DB::getQueryLog())->toBeEmpty();
});

it('rate limits repeated 401 responses per ip with 429', function () {
    config(['capture.auth_fail_limit' => 3, 'capture.auth_fail_window_seconds' => 60]);

    $bad = captureEvent(Project::generateApiKey());
    foreach (range(1, 3) as $_) {
        $this->postJson('/e/', $bad)->assertUnauthorized();
    }

    $this->postJson('/e/', $bad)->assertStatus(429)
        ->assertJsonPath('type', 'capture_error')
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');

    // A valid key from the blocked ip is NOT refused: only unauthenticated requests are limited.
    $this->postJson('/e/', captureEvent($this->key))->assertOk()->assertExactJson(['status' => 1]);
    expect($this->producer->messages)->toHaveCount(1);
});

it('answers 429 with a numeric Retry-After within the window', function () {
    config(['capture.auth_fail_limit' => 1, 'capture.auth_fail_window_seconds' => 60]);

    $bad = captureEvent(Project::generateApiKey());
    $this->postJson('/e/', $bad)->assertUnauthorized();

    $retryAfter = $this->postJson('/e/', $bad)->assertStatus(429)->headers->get('Retry-After');

    expect($retryAfter)->toMatch('/^\d+$/')
        ->and((int) $retryAfter)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
});

it('answers 429 to every kind of unauthenticated request from a blocked ip', function (string $kind) {
    config(['capture.auth_fail_limit' => 2, 'capture.max_batch_size' => 1]);
    RateLimiter::hit('capture-auth-fail:127.0.0.1', 60);
    RateLimiter::hit('capture-auth-fail:127.0.0.1', 60);

    $json = ['CONTENT_TYPE' => 'application/json'];
    $response = match ($kind) {
        'unknown key' => $this->postJson('/e/', captureEvent(Project::generateApiKey())),
        'malformed key' => $this->postJson('/e/', captureEvent('nope')),
        'missing key' => $this->postJson('/e/', ['event' => 'e', 'distinct_id' => 'u']),
        'invalid json' => $this->call('POST', '/e/', [], [], [], $json, 'not json'),
        'empty array' => $this->call('POST', '/e/', [], [], [], $json, '[]'),
        'valid key but no events' => $this->postJson('/e/', ['api_key' => $this->key, 'batch' => []]),
        'oversized batch' => $this->postJson('/e/', ['api_key' => $this->key, 'batch' => [['event' => 'a'], ['event' => 'b']]]),
    };

    $response->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');
    expect($this->producer->messages)->toBeEmpty()
        ->and(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(2);
})->with(['unknown key', 'malformed key', 'missing key', 'invalid json', 'empty array', 'valid key but no events', 'oversized batch']);

it('lets a valid key through from a blocked ip and leaves the failure budget untouched', function () {
    config(['capture.auth_fail_limit' => 1]);
    RateLimiter::hit('capture-auth-fail:127.0.0.1', 60);

    foreach (range(1, 5) as $_) {
        $this->postJson('/e/', captureEvent($this->key))->assertOk();
        $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => [['event' => 'a', 'distinct_id' => 'u']]])->assertOk();
    }

    expect($this->producer->messages)->toHaveCount(10)
        ->and(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(1);
});

it('does not let authenticated requests consume the failure budget', function () {
    config(['capture.auth_fail_limit' => 2]);

    foreach (range(1, 10) as $_) {
        $this->postJson('/e/', captureEvent($this->key))->assertOk();
    }
    expect(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(0);

    // Still a full budget of two failures before the block.
    $bad = captureEvent(Project::generateApiKey());
    $this->postJson('/e/', $bad)->assertUnauthorized();
    $this->postJson('/e/', $bad)->assertUnauthorized();
    $this->postJson('/e/', $bad)->assertStatus(429);
});

it('counts unknown keys and decode failures against the same budget', function () {
    config(['capture.auth_fail_limit' => 2]);

    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);
    $this->postJson('/e/', captureEvent(Project::generateApiKey()))->assertUnauthorized();

    expect(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(2);
    $this->postJson('/e/', captureEvent(Project::generateApiKey()))->assertStatus(429);
});

it('does not count successful requests towards the 401 limit', function () {
    config(['capture.auth_fail_limit' => 2]);

    foreach (range(1, 5) as $_) {
        $this->postJson('/e/', captureEvent($this->key))->assertOk();
    }
});

it('rejects requests spanning too many calendar months with 400', function () {
    config(['capture.max_months_per_request' => 24]);

    $events = [];
    for ($i = 0; $i < 25; $i++) {
        $events[] = [
            'event' => 'old',
            'distinct_id' => 'u',
            'timestamp' => now('UTC')->subMonthsNoOverflow($i)->startOfMonth()->addDays(2)->toIso8601String(),
        ];
    }

    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => $events])
        ->assertStatus(400)->assertJsonPath('code', 'too_many_months');

    expect($this->producer->messages)->toBeEmpty();
});

it('accepts requests spanning exactly the allowed number of months', function () {
    $events = [];
    for ($i = 0; $i < 24; $i++) {
        $events[] = [
            'event' => 'old',
            'distinct_id' => 'u',
            'timestamp' => now('UTC')->subMonthsNoOverflow($i)->startOfMonth()->addDays(2)->toIso8601String(),
        ];
    }

    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => $events])->assertOk();

    expect($this->producer->messages)->toHaveCount(24);
});

it('omits $ip when ip=0 and records it by default', function () {
    $this->postJson('/e/?ip=0', captureEvent($this->key))->assertOk();
    $this->postJson('/e/', captureEvent($this->key))->assertOk();

    $without = json_decode($this->producer->messages[0]['properties'], true);
    $with = json_decode($this->producer->messages[1]['properties'], true);

    expect($without)->not->toHaveKey('$ip')
        ->and($with)->toHaveKey('$ip');
});

it('ignores an invalid sent_at instead of failing', function () {
    $this->postJson('/e/', captureEvent($this->key, ['sent_at' => 'not a date at all']))->assertOk();
    $this->postJson('/e/?sent_at=garbage', captureEvent($this->key))->assertOk();
    $this->postJson('/e/', captureEvent($this->key, ['sent_at' => ['x']]))->assertOk();

    expect($this->producer->messages)->toHaveCount(3);
});

it('returns 503 with Retry-After when kafka is unavailable', function () {
    config(['capture.kafka_breaker_cooldown_seconds' => 9.2]);
    $this->producer->fail = true;

    $this->postJson('/e/', captureEvent($this->key))->assertStatus(503)
        ->assertJsonPath('code', 'service_unavailable')
        ->assertHeader('Retry-After', '10');
});

it('answers 200 and produces only valid events when some are dropped', function () {
    Log::spy();

    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => [
        ['event' => 'good', 'distinct_id' => 'u'],
        ['event' => '', 'distinct_id' => 'u'],
    ]])->assertOk()->assertExactJson(['status' => 1]);

    expect($this->producer->messages)->toHaveCount(1)
        ->and($this->producer->messages[0]['event'])->toBe('good');

    Log::shouldHaveReceived('debug')->once();
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('info');
});

it('answers CORS preflight for browser SDKs', function () {
    $this->call('OPTIONS', '/e/', [], [], [], [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', '*');
});

it('is not behind session, cookie or csrf middleware', function () {
    $response = $this->postJson('/e/', captureEvent($this->key));

    $response->assertOk();
    expect($response->headers->get('Set-Cookie'))->toBeNull()
        ->and($response->headers->getCookies())->toBeEmpty();
});

it('answers 400 for a gzip body of many tiny containers', function () {
    $body = gzencode('['.str_repeat('[0],', 500_000).'[0]]');

    $this->call('POST', '/e/?compression=gzip-js', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)
        ->assertStatus(400)->assertJsonPath('code', 'invalid_payload');

    expect($this->producer->messages)->toBeEmpty();
});

it('records the forwarded client ip and limits per client when proxies are trusted', function () {
    bootTrustedProxies('*');
    $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'];

    $this->call('POST', '/e/', [], [], [], $server, json_encode(captureEvent($this->key)))->assertOk();
    $this->call('POST', '/e/', [], [], [], $server, json_encode(captureEvent(Project::generateApiKey())))->assertUnauthorized();

    expect(json_decode($this->producer->messages[0]['properties'], true)['$ip'])->toBe('1.2.3.4')
        ->and(RateLimiter::attempts('capture-auth-fail:1.2.3.4'))->toBe(1)
        ->and(RateLimiter::attempts('capture-auth-fail:10.0.0.1'))->toBe(0);
});

it('ignores X-Forwarded-For when no proxy is trusted', function () {
    bootTrustedProxies(null);
    $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'];

    $this->call('POST', '/e/', [], [], [], $server, json_encode(captureEvent($this->key)))->assertOk();
    $this->call('POST', '/e/', [], [], [], $server, json_encode(captureEvent(Project::generateApiKey())))->assertUnauthorized();

    expect(json_decode($this->producer->messages[0]['properties'], true)['$ip'])->toBe('10.0.0.1')
        ->and(RateLimiter::attempts('capture-auth-fail:10.0.0.1'))->toBe(1)
        ->and(RateLimiter::attempts('capture-auth-fail:1.2.3.4'))->toBe(0);
});

it('only trusts X-Forwarded-For from the listed proxies', function () {
    bootTrustedProxies(['10.0.0.0/8']);

    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], json_encode(captureEvent($this->key)))->assertOk();
    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], json_encode(captureEvent($this->key)))->assertOk();

    expect(json_decode($this->producer->messages[0]['properties'], true)['$ip'])->toBe('1.2.3.4')
        ->and(json_decode($this->producer->messages[1]['properties'], true)['$ip'])->toBe('192.0.2.9');
});

it('parses TRUSTED_PROXIES into the config value', function (string $env, mixed $expected) {
    putenv("TRUSTED_PROXIES=$env");
    $_ENV['TRUSTED_PROXIES'] = $env;
    $_SERVER['TRUSTED_PROXIES'] = $env;

    try {
        expect((require config_path('capture.php'))['trusted_proxies'])->toBe($expected);
    } finally {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    }
})->with([
    'unset' => ['', null],
    'blank' => ['   ', null],
    'star' => ['*', '*'],
    'list' => ['10.0.0.1, 172.16.0.0/12,,', ['10.0.0.1', '172.16.0.0/12']],
]);

it('counts decode failures and empty requests towards the failure limit', function () {
    config(['capture.auth_fail_limit' => 3]);

    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);
    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]')->assertStatus(400);
    $this->call('POST', '/e/?compression=gzip-js', [], [], [], ['CONTENT_TYPE' => 'text/plain'], gzencode('['.str_repeat('[0],', 200_000).'[0]]'))->assertStatus(400);

    expect(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(3);
    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(429);
    $this->postJson('/e/', captureEvent($this->key))->assertOk();
});

it('counts oversized batches towards the failure limit', function () {
    config(['capture.auth_fail_limit' => 1, 'capture.max_batch_size' => 1]);

    $this->postJson('/e/', ['api_key' => $this->key, 'batch' => [['event' => 'a'], ['event' => 'b']]])->assertStatus(413);

    $this->postJson('/e/', ['api_key' => $this->key, 'batch' => [['event' => 'a'], ['event' => 'b']]])->assertStatus(429);
    $this->postJson('/e/', captureEvent($this->key))->assertOk();
});

it('defaults to a failure limit of 120 per minute', function () {
    expect(config('capture.auth_fail_limit'))->toBe(120)->and(config('capture.auth_fail_window_seconds'))->toBe(60);
});

it('warns once when the failure limit is first reached', function () {
    config(['capture.auth_fail_limit' => 2]);
    Log::spy();

    $bad = captureEvent(Project::generateApiKey());
    $this->postJson('/e/', $bad)->assertUnauthorized();
    $this->postJson('/e/', $bad)->assertUnauthorized();
    $this->postJson('/e/', $bad)->assertStatus(429);
    $this->postJson('/e/', $bad)->assertStatus(429);

    Log::shouldHaveReceived('warning')->once();
});

it('fails open and warns when the rate limiter is unavailable', function () {
    Log::spy();
    RateLimiter::shouldReceive('tooManyAttempts')->andThrow(new RuntimeException('redis down'));
    RateLimiter::shouldReceive('hit')->andThrow(new RuntimeException('redis down'));

    $this->postJson('/e/', captureEvent($this->key))->assertOk();
    $this->postJson('/e/', captureEvent(Project::generateApiKey()))->assertUnauthorized();
    $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);

    expect($this->producer->messages)->toHaveCount(1);
    // At most one warning per minute, however many requests fail.
    Log::shouldHaveReceived('warning')->once();
});

it('falls back to the database when the cache is unavailable', function () {
    Cache::shouldReceive('get', 'has', 'put', 'forget')->andThrow(new RuntimeException('redis down'));

    $this->postJson('/e/', captureEvent($this->key))->assertOk();
    $this->postJson('/e/', captureEvent(Project::generateApiKey()))->assertUnauthorized();

    expect($this->producer->messages)->toHaveCount(1);
});

it('answers 503 with Retry-After when the project lookup fails', function () {
    Log::spy();
    DB::beforeExecuting(fn (string $query) => str_contains($query, '"projects"') ? throw new RuntimeException('db down') : null);

    $this->postJson('/e/', captureEvent(Project::generateApiKey()))->assertStatus(503)
        ->assertJsonPath('code', 'service_unavailable')
        ->assertHeader('Retry-After', '5');

    expect($this->producer->messages)->toBeEmpty();
});

it('warns when every event of a request was dropped but only debugs partial drops', function () {
    Log::spy();

    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => [
        ['event' => '', 'distinct_id' => 'u'],
        ['distinct_id' => 'u'],
    ]])->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['project_id' => $this->project->id, 'received' => 2, 'dropped' => 2]);
    Log::shouldNotHaveReceived('debug');
});

it('treats ip=false and ip=0 alike, case-insensitively', function (string $value) {
    $this->postJson("/e/?ip=$value", captureEvent($this->key))->assertOk();

    expect(json_decode($this->producer->messages[0]['properties'], true))->not->toHaveKey('$ip');
})->with(['0', 'false', 'FALSE', 'False']);

it('keeps the ip for other ip values and array-valued ip', function (string $query) {
    $this->postJson("/e/?$query", captureEvent($this->key))->assertOk();

    expect(json_decode($this->producer->messages[0]['properties'], true))->toHaveKey('$ip');
})->with(['ip=1' => 'ip=1', 'ip[]=x' => 'ip[]=x', 'ip=true' => 'ip=true']);

it('accepts the posthog-js /i/v0/e path', function (string $path) {
    $this->postJson($path, captureEvent($this->key, ['event' => 'via_i']))->assertOk();

    expect($this->producer->messages[0]['event'])->toBe('via_i');
})->with(['/i/v0/e', '/i/v0/e/']);

it('answers CORS preflight on the /i/ prefix', function () {
    $this->call('OPTIONS', '/i/v0/e/', [], [], [], [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', '*');
});

function rateKey(Project $project, ?int $at = null): string
{
    return "capture-rate:{$project->id}:".intdiv($at ?? now('UTC')->getTimestamp(), 60);
}

/** Replaces the project limiter by one whose Retry-After jitter is pinned. */
function pinRetryJitter(int $seconds): void
{
    app()->instance(ProjectRateLimiter::class, new ProjectRateLimiter(fn (): int => $seconds));
}

function eventBatch(string $key, int $count, string $name = 'e'): array
{
    return ['api_key' => $key, 'batch' => array_map(fn (int $i) => ['event' => $name, 'distinct_id' => 'u'.$i], range(1, $count))];
}

it('defaults to 60000 events per project per minute', function () {
    expect(config('capture.project_events_per_minute'))->toBe(60000);
});

it('accepts requests under the project limit and counts the events', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 10, 'capture.max_batch_size' => 7]);

    $this->postJson('/batch/', eventBatch($this->key, 3))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 7))->assertOk();

    expect($this->producer->messages)->toHaveCount(10)
        ->and((int) Cache::get(rateKey($this->project)))->toBe(10);
});

it('answers 503 rate_limited with Retry-After and produces nothing over the project limit', function () {
    pinRetryJitter(0);
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 5, 'capture.max_batch_size' => 4]);

    $this->postJson('/batch/', eventBatch($this->key, 4, 'secret-marker'))->assertOk();
    $response = $this->postJson('/batch/', eventBatch($this->key, 2, 'secret-marker'));

    $response->assertStatus(503)
        ->assertExactJson(['type' => 'capture_error', 'code' => 'rate_limited', 'detail' => 'Project event rate limit exceeded, retry later'])
        ->assertHeader('Retry-After', '50');
    expect($this->producer->messages)->toHaveCount(4)
        ->and($response->getContent())->not->toContain('secret-marker');
});

it('restores the budget after a rejection so a smaller request still fits', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 5, 'capture.max_batch_size' => 4]);

    $this->postJson('/batch/', eventBatch($this->key, 4))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 3))->assertStatus(503);
    $this->postJson('/batch/', eventBatch($this->key, 3))->assertStatus(503);

    expect((int) Cache::get(rateKey($this->project)))->toBe(4);
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertOk();
    expect($this->producer->messages)->toHaveCount(5);
});

it('never lets the project limit drop below max_batch_size', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    // Effective limit = max(10, 20) = 20: a full batch is accepted once instead of being rejected forever.
    config(['capture.project_events_per_minute' => 10, 'capture.max_batch_size' => 20]);

    $this->postJson('/batch/', eventBatch($this->key, 20))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 20))->assertStatus(503)->assertJsonPath('code', 'rate_limited');

    expect($this->producer->messages)->toHaveCount(20)
        ->and((int) Cache::get(rateKey($this->project)))->toBe(20);

    Carbon::setTestNow('2026-10-22 12:01:10');
    $this->postJson('/batch/', eventBatch($this->key, 20))->assertOk();
    expect($this->producer->messages)->toHaveCount(40);
});

it('keeps a limit above max_batch_size as configured', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 30, 'capture.max_batch_size' => 20]);

    $this->postJson('/batch/', eventBatch($this->key, 20))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 11))->assertStatus(503);
    $this->postJson('/batch/', eventBatch($this->key, 10))->assertOk();

    expect($this->producer->messages)->toHaveCount(30);
});

it('resets the project budget in the next minute window', function () {
    pinRetryJitter(0);
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 3, 'capture.max_batch_size' => 3]);

    $this->postJson('/batch/', eventBatch($this->key, 3))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertStatus(503)->assertHeader('Retry-After', '50');

    Carbon::setTestNow('2026-10-22 12:00:59');
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertStatus(503)->assertHeader('Retry-After', '1');

    Carbon::setTestNow('2026-10-22 12:01:00');
    $this->postJson('/batch/', eventBatch($this->key, 3))->assertOk();

    expect($this->producer->messages)->toHaveCount(6);
});

it('keeps independent budgets for different projects', function () {
    config(['capture.project_events_per_minute' => 2, 'capture.max_batch_size' => 2]);
    $other = Project::factory()->create();

    $this->postJson('/batch/', eventBatch($this->key, 2))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertStatus(503);
    $this->postJson('/batch/', eventBatch($other->api_key, 2))->assertOk();

    expect($this->producer->messages)->toHaveCount(4);
});

it('disables the project limit with zero or a negative value', function (int $limit) {
    config(['capture.project_events_per_minute' => $limit]);

    $this->postJson('/batch/', eventBatch($this->key, 50))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 50))->assertOk();

    expect($this->producer->messages)->toHaveCount(100)
        ->and(Cache::has(rateKey($this->project)))->toBeFalse();
})->with([0, -1]);

it('keeps only the producible messages counted and releases the dropped events', function () {
    config(['capture.project_events_per_minute' => 3, 'capture.max_batch_size' => 4]);

    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => [
        ['event' => 'good', 'distinct_id' => 'u'],
        ['event' => '', 'distinct_id' => 'u'],
        ['event' => 'good2', 'distinct_id' => 'u'],
        ['distinct_id' => 'u'],
    ]])->assertOk();
    expect((int) Cache::get(rateKey($this->project)))->toBe(2);

    // A request in which every event was dropped is reserved, fully released and answered 200 as before.
    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => [['event' => '', 'distinct_id' => 'u']]])->assertOk();
    expect((int) Cache::get(rateKey($this->project)))->toBe(2)
        ->and($this->producer->messages)->toHaveCount(2);
});

it('releases the whole reservation when the request spans too many months', function () {
    config(['capture.project_events_per_minute' => 100, 'capture.max_months_per_request' => 1]);
    $this->postJson('/batch/', eventBatch($this->key, 3))->assertOk();

    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => [
        ['event' => 'a', 'distinct_id' => 'u', 'timestamp' => '2024-01-05T00:00:00Z'],
        ['event' => 'b', 'distinct_id' => 'u', 'timestamp' => '2024-03-05T00:00:00Z'],
    ]])->assertStatus(400)->assertJsonPath('code', 'too_many_months');

    expect((int) Cache::get(rateKey($this->project)))->toBe(3);
});

it('releases the whole reservation when the event pipeline is unavailable', function () {
    config(['capture.project_events_per_minute' => 5, 'capture.max_batch_size' => 4]);
    $this->producer->fail = true;

    // Retried batches during a Kafka outage must not burn the project's budget.
    $this->postJson('/batch/', eventBatch($this->key, 4))->assertStatus(503)->assertJsonPath('code', 'service_unavailable');
    $this->postJson('/batch/', eventBatch($this->key, 4))->assertStatus(503)->assertJsonPath('code', 'service_unavailable');
    expect((int) Cache::get(rateKey($this->project)))->toBe(0);

    $this->producer->fail = false;
    $this->postJson('/batch/', eventBatch($this->key, 4))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertOk();
    expect((int) Cache::get(rateKey($this->project)))->toBe(5)
        ->and($this->producer->messages)->toHaveCount(5);
});

it('releases the reservation when normalising throws unexpectedly', function () {
    config(['capture.project_events_per_minute' => 10]);
    $this->postJson('/batch/', eventBatch($this->key, 2))->assertOk();
    $this->producer->failWith = new RuntimeException('boom');

    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson('/batch/', eventBatch($this->key, 4)))->toThrow(RuntimeException::class);

    expect((int) Cache::get(rateKey($this->project)))->toBe(2);
});

it('warns once per window and project when the project limit is first exceeded', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 2, 'capture.max_batch_size' => 2]);
    Log::spy();

    $this->postJson('/batch/', eventBatch($this->key, 2))->assertOk();
    foreach (range(1, 3) as $_) {
        $this->postJson('/batch/', eventBatch($this->key, 2, 'secret-marker'))->assertStatus(503);
    }
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context === ['project_id' => $this->project->id, 'limit' => 2, 'requested' => 2],
    );

    Carbon::setTestNow('2026-10-22 12:01:10');
    $this->postJson('/batch/', eventBatch($this->key, 2))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 2))->assertStatus(503);
    Log::shouldHaveReceived('warning')->twice();
});

it('fails open and warns once when the cache is unavailable for the project limit', function () {
    config(['capture.project_events_per_minute' => 1]);
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
    Cache::forgetDriver();
    Log::spy();

    $this->postJson('/batch/', eventBatch($this->key, 3))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 3))->assertOk();

    expect($this->producer->messages)->toHaveCount(6);
    Log::shouldHaveReceived('warning')->once();
});

it('adds jitter of up to five seconds to the project Retry-After', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 3, 'capture.max_batch_size' => 3]);
    $this->postJson('/batch/', eventBatch($this->key, 3))->assertOk();

    // Real randomness stays within [seconds to the window end, +5]; the reservation uses it.
    foreach (range(1, 20) as $_) {
        $retryAfter = (int) $this->postJson('/batch/', eventBatch($this->key, 1))->assertStatus(503)->headers->get('Retry-After');
        expect($retryAfter)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(55);
    }

    pinRetryJitter(4);
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertStatus(503)->assertHeader('Retry-After', '54');
});

it('refuses a spent project before any normalising work and keeps the counter', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 2, 'capture.max_batch_size' => 2]);
    pinRetryJitter(0);
    $this->postJson('/batch/', eventBatch($this->key, 2))->assertOk();

    Log::spy();
    // The normaliser would drop (and log) this event; the reservation is refused before it gets there,
    // so an all-dropped request is no special case: it is a 503 while the budget is spent.
    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => [['event' => '', 'distinct_id' => 'u']]])
        ->assertStatus(503)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After', '50');
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertStatus(503)->assertHeader('Retry-After', '50');

    Log::shouldNotHaveReceived('debug');
    // The only warning is the once-per-window limit notice, not the normaliser's "dropped every event".
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'capture project event rate limit exceeded' && $context['requested'] === 1,
    );
    expect((int) Cache::get(rateKey($this->project)))->toBe(2)
        ->and($this->producer->messages)->toHaveCount(2);
});

it('rejects a batch that no longer fits without normalising it, even with mixed batch sizes', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 10, 'capture.max_batch_size' => 7]);
    $this->postJson('/batch/', eventBatch($this->key, 7))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 2))->assertOk();
    expect((int) Cache::get(rateKey($this->project)))->toBe(9);

    Log::spy();
    // One of the two events is invalid: normalising would log the drop. 2 > 1 left, so it is rejected up front.
    $this->postJson('/batch/', ['api_key' => $this->key, 'batch' => [
        ['event' => 'good', 'distinct_id' => 'u'],
        ['event' => '', 'distinct_id' => 'u'],
    ]])->assertStatus(503)->assertJsonPath('code', 'rate_limited');
    Log::shouldNotHaveReceived('debug');

    expect((int) Cache::get(rateKey($this->project)))->toBe(9);
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertOk();
    expect((int) Cache::get(rateKey($this->project)))->toBe(10);
});

it('keeps rejecting per project and per window only', function () {
    Carbon::setTestNow('2026-10-22 12:00:10');
    config(['capture.project_events_per_minute' => 2, 'capture.max_batch_size' => 2]);
    $other = Project::factory()->create();
    $this->postJson('/batch/', eventBatch($this->key, 2))->assertOk();
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertStatus(503);

    $this->postJson('/batch/', eventBatch($other->api_key, 1))->assertOk();
    Carbon::setTestNow('2026-10-22 12:01:00');
    $this->postJson('/batch/', eventBatch($this->key, 1))->assertOk();
});

it('does not touch the counter at all when the project limit is disabled', function () {
    config(['capture.project_events_per_minute' => 0]);
    Cache::spy();

    $this->postJson('/batch/', eventBatch($this->key, 3))->assertOk();

    Cache::shouldNotHaveReceived('increment');
    Cache::shouldNotHaveReceived('decrement');
    Cache::shouldNotHaveReceived('add');
});
