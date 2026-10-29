<?php

use App\Domain\Capture\CapturePayload;
use App\Domain\Capture\EventProducer;
use App\Domain\Capture\FlagEvaluation;
use App\Domain\Capture\FlagEvaluator;
use App\Domain\Capture\FlagRequest;
use App\Domain\Capture\PayloadDecoder;
use App\Domain\Capture\SdkStubs;
use App\Http\Controllers\SdkStubController;
use App\Models\Project;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Http\Request;
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

/** Everything in the remote config that, left on, would make the SDK fetch a script from /static/ or open /s/. */
const REMOTE_CONFIG_SWITCHED_OFF = ['hasFeatureFlags', 'sessionRecording', 'surveys', 'heatmaps', 'captureDeadClicks', 'capturePerformance', 'autocaptureExceptions', 'productTours', 'conversations'];

/** The `{...}` the config script assigns to window._POSTHOG_REMOTE_CONFIG[token], decoded. */
function scriptedConfig(string $script, string $token): array
{
    expect(preg_match('/^window\._POSTHOG_REMOTE_CONFIG = window\._POSTHOG_REMOTE_CONFIG \|\| \{\};\nwindow\._POSTHOG_REMOTE_CONFIG\["'.preg_quote($token, '/').'"\] = (\{.*\});\n\z/s', $script, $m))->toBe(1);

    return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
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
    it('answers a JSON config with the keys and types the SDK reads', function () {
        $response = $this->getJson("/array/{$this->key}/config")->assertOk();
        $config = $response->json();

        expect($config['token'])->toBe($this->key)
            ->and($config['supportedCompression'])->toBeList()->toContain('gzip-js')
            ->and($config['siteApps'])->toBe([])
            ->and($config['autocapture_opt_out'])->toBeFalse()
            ->and($config['defaultIdentifiedOnly'])->toBeBool()
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=60')->not->toContain('public')
            ->and($response->headers->get('Content-Type'))->toStartWith('application/json')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expectNoCookies($response);
    });

    it('switches off everything that would make the SDK fetch a script or open /s/', function () {
        $config = $this->getJson("/array/{$this->key}/config")->json();

        foreach (REMOTE_CONFIG_SWITCHED_OFF as $key) {
            expect($config)->toHaveKey($key)
                ->and($config[$key])->toBeFalse("{$key} must stay false");
        }
    });

    it('answers the config script with the same config under window._POSTHOG_REMOTE_CONFIG[token]', function () {
        $json = $this->getJson("/array/{$this->key}/config")->json();
        $response = $this->get("/array/{$this->key}/config.js")->assertOk();

        expect(scriptedConfig($response->getContent(), $this->key))->toBe(['config' => $json, 'siteApps' => []])
            ->and($response->headers->get('Content-Type'))->toBe('application/javascript; charset=utf-8')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('private');
        expectNoCookies($response);
    });

    it('cannot be made to break out of the script by the token', function () {
        // Only a well formed token reaches the script, so this is a guard against a future loosening of the format.
        $script = SdkStubs::remoteConfigScript(1, 'phc_</script>"\'&');

        expect($script)->not->toContain('</script>')->not->toContain("'");
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
    it('answers /flags in the shape the SDK reads: maps are objects, lists are lists, a fresh request id each time', function () {
        $first = $this->postJson('/flags/?v=2&ip=0&_=1&ver=1.438.24&compression=gzip-js', flagsBody($this->key))->assertOk();
        $second = $this->postJson('/flags', flagsBody($this->key))->assertOk();
        $body = json_decode($first->getContent());

        // Empty maps must encode as {} (the SDK treats [] as a v1 list of flags), the list as [].
        expect($body->flags)->toBeInstanceOf(stdClass::class)
            ->and($body->toolbarParams)->toBeInstanceOf(stdClass::class)
            ->and($body->quotaLimited)->toBe([])
            ->and($body->errorsWhileComputingFlags)->toBeFalse()
            ->and($body->isAuthenticated)->toBeFalse()
            ->and($body->supportedCompression)->toBe(['gzip-js'])
            ->and($body->config->enable_collect_everything)->toBeTrue()
            ->and($body->requestId)->toBeString()->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
            ->and($second->json('requestId'))->not->toBe($body->requestId);
        expect($first->headers->get('Cache-Control'))->toContain('no-store')
            ->and($first->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expectNoCookies($first);
    });

    it('answers /decide in the legacy shape with the same objects and the keys older SDKs read', function () {
        $response = $this->postJson('/decide/?v=3', flagsBody($this->key))->assertOk();
        $body = json_decode($response->getContent());

        expect($body->featureFlags)->toBeInstanceOf(stdClass::class)
            ->and($body->featureFlagPayloads)->toBeInstanceOf(stdClass::class)
            ->and($body->flags)->toBeInstanceOf(stdClass::class)
            ->and($body->quotaLimited)->toBe([])
            ->and($body->errorsWhileComputingFlags)->toBeFalse()
            ->and($body->supportedCompression)->toBe(['gzip-js'])
            ->and($body->siteApps)->toBe([])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
        foreach (['sessionRecording', 'surveys', 'heatmaps', 'capturePerformance', 'autocapture_opt_out'] as $key) {
            expect($body->{$key})->toBeFalse("{$key} must stay false");
        }
        expectNoCookies($response);
    });

    it('hands the evaluator the request and presents its evaluation in both shapes', function () {
        $seen = [];
        $this->app->instance(FlagEvaluator::class, new class($seen) implements FlagEvaluator
        {
            public function __construct(private array &$seen) {}

            public function evaluate(FlagRequest $request): FlagEvaluation
            {
                $this->seen[] = $request;

                return new FlagEvaluation(
                    ['beta' => ['enabled' => true, 'variant' => null]],
                    ['beta' => true],
                    ['beta' => ['x' => 1]],
                    true,
                    ['feature_flags'],
                    'req-1',
                );
            }
        });
        $body = flagsBody($this->key, ['$anon_distinct_id' => 'anon-1', 'flag_keys' => ['beta', 7], 'groups' => ['org' => 'o1'], 'group_properties' => ['org' => ['plan' => 'pro']]]);

        $flags = $this->postJson('/flags', $body)->assertOk()->json();
        $decide = $this->postJson('/decide', $body)->assertOk()->json();

        expect($flags['flags'])->toBe(['beta' => ['enabled' => true, 'variant' => null]])
            ->and($flags['errorsWhileComputingFlags'])->toBeTrue()
            ->and($flags['quotaLimited'])->toBe(['feature_flags'])
            ->and($flags['requestId'])->toBe('req-1')
            ->and($decide['featureFlags'])->toBe(['beta' => true])
            ->and($decide['featureFlagPayloads'])->toBe(['beta' => ['x' => 1]]);

        expect($seen)->toHaveCount(2)
            ->and($seen[0])->toEqual(new FlagRequest($this->project->id, 'u1', 'anon-1', ['org' => 'o1'], ['$lib' => 'web'], ['org' => ['plan' => 'pro']], ['beta'], 'Europe/Istanbul'));
    });

    it('turns missing and mistyped request fields into empty defaults', function () {
        $request = FlagRequest::fromBody(5, ['distinct_id' => 7, 'groups' => [1, 2], 'person_properties' => 'x', 'flag_keys' => 'beta', 'timezone' => '']);

        expect($request)->toEqual(new FlagRequest(5, null, null, [], [], [], null, null));
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
        config(['capture.stub_max_body_bytes' => 100]);

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

    it('caps the body of a stub call at capture.stub_max_body_bytes, 64 KiB by default', function () {
        expect(config('capture.stub_max_body_bytes'))->toBe(65536);

        $this->postJson('/flags', flagsBody($this->key, ['pad' => str_repeat('x', 70_000)]))->assertStatus(413)->assertExactJson(['type' => 'capture_error', 'code' => 'payload_too_large', 'detail' => 'Request body is too large']);
        $this->postJson('/decide', flagsBody($this->key, ['pad' => str_repeat('x', 60_000)]))->assertOk();
    });

    it('answers a blocked ip with a big body a bare 429 without decoding it, here and on capture', function (string $path) {
        config(['capture.auth_fail_limit' => 1]);
        $decoded = 0;
        $this->app->instance(PayloadDecoder::class, new class($decoded) extends PayloadDecoder
        {
            public function __construct(private int &$decoded)
            {
                parent::__construct();
            }

            public function decodeObject(Request $request): array
            {
                $this->decoded++;

                return parent::decodeObject($request);
            }

            public function decode(Request $request): CapturePayload
            {
                $this->decoded++;

                return parent::decode($request);
            }
        });
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{nope')->assertStatus(400);
        expect($decoded)->toBe(1);

        $big = json_encode(flagsBody($this->key, ['pad' => str_repeat('x', 300_000)]));
        $blocked = $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], $big);

        $blocked->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        expect($decoded)->toBe(1)
            ->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and(RateLimiter::attempts('capture-auth-fail:127.0.0.1'))->toBe(1);

        // A small body of a valid key from the same blocked ip is still decoded and answered (a shared address stays usable).
        $small = $path === '/e/' ? json_encode(['api_key' => $this->key, 'event' => 'x', 'distinct_id' => 'u']) : json_encode(flagsBody($this->key));
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], $small)->assertOk();
        expect($decoded)->toBe(2);
    })->with(['/flags', '/decide', '/e/']);

    it('limits successful stub calls per ip, config flags and decide together, with a capture-style 429', function () {
        config(['capture.stub_calls_per_minute' => 3]);

        $this->get("/array/{$this->key}/config")->assertOk();
        $this->postJson('/flags', flagsBody($this->key))->assertOk();
        $this->postJson('/decide', flagsBody($this->key))->assertOk();

        foreach ([fn () => $this->get("/array/{$this->key}/config.js"), fn () => $this->postJson('/flags', flagsBody($this->key))] as $call) {
            $limited = $call()->assertStatus(429)->assertExactJson(['type' => 'capture_error', 'code' => 'too_many_requests', 'detail' => 'Too many requests, retry later']);
            expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0);
        }

        // Failed calls are not counted by it (the failure limiter owns them), and capture is not touched.
        expect(RateLimiter::attempts('sdk-stub-calls:127.0.0.1'))->toBe(3);
        $this->postJson('/e/', ['api_key' => $this->key, 'event' => 'x', 'distinct_id' => 'u'])->assertOk();
    });

    it('counts only the calls that were answered, and 0 disables the cap', function () {
        config(['capture.stub_calls_per_minute' => 2]);
        $this->get('/array/bad/config')->assertStatus(401);
        $this->postJson('/flags', flagsBody('bad'))->assertStatus(401);
        expect(RateLimiter::attempts('sdk-stub-calls:127.0.0.1'))->toBe(0);

        config(['capture.stub_calls_per_minute' => 0]);
        foreach (range(1, 5) as $_) {
            $this->get("/array/{$this->key}/config")->assertOk();
        }
        expect(RateLimiter::attempts('sdk-stub-calls:127.0.0.1'))->toBe(0);
    });

    it('fails open when the cache is down for the stub call cap', function () {
        config(['capture.stub_calls_per_minute' => 1]);
        Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
        config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
        Cache::forgetDriver();
        Log::spy();

        $this->get("/array/{$this->key}/config")->assertOk();
        $this->get("/array/{$this->key}/config")->assertOk();
        $this->postJson('/flags', flagsBody($this->key))->assertOk();

        Log::shouldHaveReceived('warning')->once();
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
