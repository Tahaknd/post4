<?php

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\SdkStubs;
use App\Http\Controllers\SdkStubController;
use App\Models\Project;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\FailingCacheStore;
use Tests\Support\FakeEventProducer;

beforeEach(function () {
    $this->producer = new FakeEventProducer;
    $this->app->instance(EventProducer::class, $this->producer);
    $this->project = Project::factory()->create();
    $this->key = $this->project->api_key;
});

afterEach(fn () => LimiterOutage::reset());

const SDK_ORIGIN = ['HTTP_ORIGIN' => 'https://shop.example.com'];

/** The pinned answer of the remote config for $token. */
function expectedRemoteConfig(string $token): array
{
    return [
        'token' => $token,
        'supportedCompression' => ['gzip-js'],
        'hasFeatureFlags' => false,
        'sessionRecording' => false,
        'surveys' => false,
        'heatmaps' => false,
        'captureDeadClicks' => false,
        'capturePerformance' => false,
        'autocaptureExceptions' => false,
        'productTours' => false,
        'conversations' => false,
        'autocapture_opt_out' => false,
        'defaultIdentifiedOnly' => true,
        'siteApps' => [],
    ];
}

/** A flags request body the way posthog-js builds it. */
function flagsBody(string $token, array $extra = []): array
{
    return array_merge(['token' => $token, 'distinct_id' => 'u1', 'groups' => [], 'person_properties' => ['$lib' => 'web'], 'timezone' => 'Europe/Istanbul'], $extra);
}

function expectNoCookies($response): void
{
    expect($response->headers->get('Set-Cookie'))->toBeNull()
        ->and($response->headers->getCookies())->toBeEmpty();
}

describe('remote config', function () {
    it('answers the pinned JSON for a known token', function () {
        $response = $this->getJson("/array/{$this->key}/config")->assertOk();

        expect($response->json())->toBe(expectedRemoteConfig($this->key))
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=60')->not->toContain('public')
            ->and($response->headers->get('Content-Type'))->toStartWith('application/json')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expectNoCookies($response);
    });

    it('switches off everything that would make the SDK fetch a script or open /s/', function () {
        $config = SdkStubs::remoteConfig($this->key);

        expect($config['sessionRecording'])->toBeFalse()
            ->and($config['surveys'])->toBeFalse()
            ->and($config['heatmaps'])->toBeFalse()
            ->and($config['captureDeadClicks'])->toBeFalse()
            ->and($config['capturePerformance'])->toBeFalse()
            ->and($config['autocaptureExceptions'])->toBeFalse()
            ->and($config['productTours'])->toBeFalse()
            ->and($config['conversations'])->toBeFalse()
            ->and($config['hasFeatureFlags'])->toBeFalse()
            ->and($config['siteApps'])->toBe([])
            ->and($config['supportedCompression'])->toBe(['gzip-js']);
    });

    it('answers the config script the SDK reads from window._POSTHOG_REMOTE_CONFIG', function () {
        $response = $this->get("/array/{$this->key}/config.js")->assertOk();
        $config = json_encode(['config' => expectedRemoteConfig($this->key), 'siteApps' => []], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        expect($response->getContent())->toBe("window._POSTHOG_REMOTE_CONFIG = window._POSTHOG_REMOTE_CONFIG || {};\nwindow._POSTHOG_REMOTE_CONFIG[\"{$this->key}\"] = {$config};\n")
            ->and($response->headers->get('Content-Type'))->toBe('application/javascript; charset=utf-8')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('private');
        expectNoCookies($response);
    });

    it('serves HEAD without a body', function (string $suffix) {
        $response = $this->call('HEAD', "/array/{$this->key}/{$suffix}");

        expect($response->getStatusCode())->toBe(200)->and($response->getContent())->toBe('');
    })->with(['config', 'config.js']);

    it('answers 401 JSON for an unknown, a malformed and a wrongly prefixed token alike', function (string $token) {
        foreach (['config', 'config.js'] as $suffix) {
            $this->get("/array/{$token}/{$suffix}", ['Accept' => 'text/html'])
                ->assertStatus(401)
                ->assertExactJson(['type' => 'capture_error', 'code' => 'invalid_api_key', 'detail' => 'API key is missing or invalid'])
                ->assertHeaderMissing('Set-Cookie');
        }
    })->with([
        'unknown but well formed' => 'phc_'.'0123456789abcdefghijklmnopqrstuvwxyzABCDEFGH',
        'garbage' => 'nope',
        'too long' => str_repeat('a', 5000),
        'wrong prefix' => 'phx_'.str_repeat('a', 43),
    ]);

    it('does not read the database for garbage and reads it once for an unknown token', function () {
        $unknown = Project::generateApiKey();
        DB::enableQueryLog();

        $this->get('/array/garbage/config')->assertStatus(401);
        expect(DB::getQueryLog())->toBe([]);

        $this->get("/array/{$unknown}/config")->assertStatus(401);
        $this->get("/array/{$unknown}/config")->assertStatus(401);
        expect(DB::getQueryLog())->toHaveCount(1);
    });

    it('answers a known token from the cache after the first request', function () {
        $this->get("/array/{$this->key}/config")->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get("/array/{$this->key}/config")->assertOk();
        $this->get("/array/{$this->key}/config.js")->assertOk();

        expect(DB::getQueryLog())->toBe([]);
    });

    it('blocks an ip with too many failures with 429 and Retry-After, and a valid token never counts', function () {
        config(['capture.auth_fail_limit' => 3]);

        foreach (range(1, 3) as $_) {
            $this->get('/array/bad/config')->assertStatus(401);
            $this->get("/array/{$this->key}/config")->assertOk();
        }

        $blocked = $this->get('/array/bad/config')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(3);
    });

    it('shares the failure budget with capture', function () {
        config(['capture.auth_fail_limit' => 2]);

        $this->postJson('/e/', ['api_key' => Project::generateApiKey(), 'event' => 'x', 'distinct_id' => 'u'])->assertStatus(401);
        $this->postJson('/flags', flagsBody(Project::generateApiKey()))->assertStatus(401);

        $this->get('/array/bad/config')->assertStatus(429);
    });

    it('allows only GET and HEAD', function () {
        $this->postJson("/array/{$this->key}/config")->assertStatus(405);
        $this->deleteJson("/array/{$this->key}/config.js")->assertStatus(405);
    });
});

describe('flags and decide', function () {
    it('answers the pinned v2 shape for /flags with a fresh request id', function () {
        $first = $this->postJson('/flags/?v=2&ip=0&_=1&ver=1.438.24&compression=gzip-js', flagsBody($this->key))->assertOk();
        $second = $this->postJson('/flags', flagsBody($this->key))->assertOk();

        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        expect($first->getContent())->toMatch('/\A\{"config":\{"enable_collect_everything":true\},"toolbarParams":\{\},"isAuthenticated":false,"supportedCompression":\["gzip-js"\],"flags":\{\},"errorsWhileComputingFlags":false,"quotaLimited":\[\],"requestId":"'.$uuid.'"\}\z/')
            ->and($second->json('requestId'))->not->toBe($first->json('requestId'));
        expect($first->headers->get('Cache-Control'))->toContain('no-store')
            ->and($first->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expectNoCookies($first);
    });

    it('answers the pinned legacy shape for /decide', function () {
        $response = $this->postJson('/decide/?v=3', flagsBody($this->key))->assertOk();

        expect($response->getContent())->toBe('{"config":{"enable_collect_everything":true},"featureFlags":{},"featureFlagPayloads":{},"flags":{},"errorsWhileComputingFlags":false,"quotaLimited":[],"sessionRecording":false,"surveys":false,"heatmaps":false,"capturePerformance":false,"autocapture_opt_out":false,"supportedCompression":["gzip-js"],"siteApps":[]}')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
        expectNoCookies($response);
    });

    it('accepts every body encoding the SDK uses', function (string $path, string $kind) {
        $json = json_encode(flagsBody($this->key));
        $server = ['CONTENT_TYPE' => 'application/json'];
        $content = $json;
        $uri = $path;

        if ($kind === 'gzip-js') {
            // posthog-js: gzip bytes as text/plain (a CORS simple request), `compression=gzip-js` in the query.
            $server = ['CONTENT_TYPE' => 'text/plain'];
            $content = gzencode($json);
            $uri .= '?v=2&compression=gzip-js';
        } elseif ($kind === 'gzip-bare') {
            $server = ['CONTENT_TYPE' => 'text/plain'];
            $content = gzencode($json);
        } elseif ($kind === 'form-base64') {
            $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
            $content = 'data='.rawurlencode(base64_encode($json));
            $uri .= '?compression=base64';
        } elseif ($kind === 'form-json') {
            $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
            $content = 'data='.rawurlencode($json);
        } elseif ($kind === 'text-plain') {
            $server = ['CONTENT_TYPE' => 'text/plain'];
        } elseif ($kind === 'api-key') {
            $content = json_encode(['api_key' => $this->key, 'distinct_id' => 'u1']);
        }

        $this->call('POST', $uri, [], [], [], $server, $content)->assertOk()->assertJsonPath('errorsWhileComputingFlags', false);
    })->with(['/flags', '/flags/', '/decide', '/decide/'])->with(['json', 'gzip-js', 'gzip-bare', 'form-base64', 'form-json', 'text-plain', 'api-key']);

    it('answers 401 for an unknown, missing or malformed token', function (array $body) {
        $body = array_map(fn ($value) => $value === 'UNKNOWN' ? Project::generateApiKey() : $value, $body);
        $this->postJson('/flags', $body)
            ->assertStatus(401)
            ->assertExactJson(['type' => 'capture_error', 'code' => 'invalid_api_key', 'detail' => 'API key is missing or invalid'])
            ->assertHeaderMissing('Set-Cookie');
        $this->postJson('/decide', $body)->assertStatus(401);
    })->with([
        'unknown' => [['token' => 'UNKNOWN', 'distinct_id' => 'u']],
        'missing' => [['distinct_id' => 'u']],
        'empty' => [['token' => '', 'distinct_id' => 'u']],
        'garbage' => [['token' => 'x', 'distinct_id' => 'u']],
        'not a string' => [['token' => ['a'], 'distinct_id' => 'u']],
        'empty object' => [[]],
    ]);

    it('answers 400 for a body that is not a JSON object and counts it as a failure', function (string $content) {
        $this->call('POST', '/flags', [], [], [], ['CONTENT_TYPE' => 'application/json'], $content)
            ->assertStatus(400)->assertJsonPath('code', 'invalid_payload');

        expect(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(1);
    })->with(['not json' => '{nope', 'a list' => '[1,2]', 'a scalar' => '"x"', 'a number' => '5', 'empty' => '']);

    it('answers 400 for a gzip bomb and an unreadable gzip body', function () {
        $this->call('POST', '/flags?compression=gzip-js', [], [], [], ['CONTENT_TYPE' => 'text/plain'], gzencode('['.str_repeat('[0],', 500_000).'[0]]'))
            ->assertStatus(400)->assertJsonPath('code', 'invalid_payload');
        $this->call('POST', '/flags?compression=gzip-js', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "\x1f\x8bnot gzip")
            ->assertStatus(400)->assertJsonPath('code', 'invalid_payload');
    });

    it('answers 413 for an oversized body without counting it against the ip', function () {
        config(['capture.max_body_bytes' => 100]);

        $this->postJson('/flags', flagsBody($this->key, ['pad' => str_repeat('x', 200)]))->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
        expect(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(0);
    });

    it('blocks an ip with too many failures and lets a valid token through while it is not blocked', function () {
        config(['capture.auth_fail_limit' => 2]);

        $this->postJson('/decide', flagsBody('bad'))->assertStatus(401);
        $this->postJson('/flags', flagsBody($this->key))->assertOk();
        $this->postJson('/flags', flagsBody('bad'))->assertStatus(401);

        $this->postJson('/flags', flagsBody('bad'))->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/decide', flagsBody($this->key))->assertOk();
    });

    it('never produces an event nor consumes the project budget', function () {
        config(['capture.project_events_per_minute' => 1]);

        foreach (range(1, 5) as $_) {
            $this->postJson('/flags', flagsBody($this->key))->assertOk();
            $this->postJson('/decide', flagsBody($this->key))->assertOk();
        }

        expect($this->producer->messages)->toBe([])
            ->and(Cache::get("capture-rate:{$this->project->id}:".intdiv(now('UTC')->getTimestamp(), 60)))->toBeNull();
        $this->postJson('/e/', ['api_key' => $this->key, 'event' => 'still_fits', 'distinct_id' => 'u'])->assertOk();
    });

    it('does not read the database once the token is cached', function () {
        $this->postJson('/flags', flagsBody($this->key))->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->postJson('/flags', flagsBody($this->key))->assertOk();
        $this->postJson('/decide', flagsBody($this->key))->assertOk();

        expect(DB::getQueryLog())->toBe([]);
    });

    // Same as the capture routes: a GET falls to the web group's 404 (the SDKs never GET these), other verbs are 405.
    it('allows only POST', function (string $method, string $path, int $status) {
        $this->call($method, $path)->assertStatus($status);
    })->with([['GET', '/flags', 404], ['GET', '/decide', 404], ['PUT', '/flags', 405], ['DELETE', '/decide', 405]]);

    it('fails open and warns once when the cache is down', function () {
        Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
        config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
        Cache::forgetDriver();
        Log::spy();

        $this->postJson('/flags', flagsBody($this->key))->assertOk();
        $this->postJson('/flags', flagsBody(Project::generateApiKey()))->assertStatus(401);
        $this->postJson('/decide', flagsBody($this->key))->assertOk();

        Log::shouldHaveReceived('warning')->once();
    });

    it('answers 503 with Retry-After when the project lookup fails', function () {
        Log::spy();
        DB::beforeExecuting(fn (string $query) => str_contains($query, '"projects"') ? throw new RuntimeException('db down') : null);
        $this->postJson('/flags', flagsBody($this->key))->assertStatus(503)->assertJsonPath('code', 'service_unavailable')->assertHeader('Retry-After', '5');
        $this->get("/array/{$this->key}/config")->assertStatus(503)->assertHeader('Retry-After', '5');
        Log::shouldHaveReceived('error')->twice();
    });
});

describe('CORS and middleware', function () {
    it('answers a preflight for every path, from any origin, without credentials', function (string $method, string $path) {
        $response = $this->call('OPTIONS', $path, [], [], [], [
            ...SDK_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ])->assertSuccessful();

        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('*')
            ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBeNull()
            ->and($response->headers->get('Access-Control-Allow-Headers'))->toBe('content-type')
            ->and($response->headers->get('Access-Control-Allow-Methods'))->toContain($method)
            ->and($response->headers->get('Set-Cookie'))->toBeNull();
    })->with([
        ['GET', '/array/phc_x/config'],
        ['GET', '/array/phc_x/config.js'],
        ['POST', '/flags'],
        ['POST', '/flags/'],
        ['POST', '/decide'],
        ['POST', '/decide/'],
    ]);

    it('puts the CORS header on the real answers too, errors included', function () {
        $answers = [
            $this->get("/array/{$this->key}/config", SDK_ORIGIN),
            $this->get("/array/{$this->key}/config.js", SDK_ORIGIN),
            $this->get('/array/bad/config', SDK_ORIGIN),
            $this->postJson('/flags', flagsBody($this->key), SDK_ORIGIN),
            $this->postJson('/decide', flagsBody('bad'), SDK_ORIGIN),
        ];

        foreach ($answers as $answer) {
            expect($answer->headers->get('Access-Control-Allow-Origin'))->toBe('*')
                ->and($answer->headers->get('Access-Control-Allow-Credentials'))->toBeNull()
                ->and($answer->headers->get('Set-Cookie'))->toBeNull();
        }
    });

    it('registers the stub routes without any middleware, under reserved prefixes only', function () {
        $stubs = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => ($route->getAction('controller') ?? '') !== '' && str_starts_with((string) $route->getAction('controller'), SdkStubController::class.'@'),
        ));

        expect(array_map(fn (RoutingRoute $r) => $r->methods()[0].' '.$r->uri(), $stubs))
            ->toBe(['GET array/{token}/config', 'GET array/{token}/config.js', 'POST flags', 'POST decide']);

        foreach ($stubs as $route) {
            expect($route->gatherMiddleware())->toBe([], "{$route->uri()} must stay middleware-free");
        }
    });

    it('does not start a session or set a cookie for any stub path', function () {
        $this->get("/array/{$this->key}/config");
        $this->postJson('/flags', flagsBody($this->key));
        $this->postJson('/decide', flagsBody('bad'));

        expect(session()->isStarted())->toBeFalse();
    });
});
