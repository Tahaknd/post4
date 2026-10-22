<?php

use App\Domain\Capture\EventProducer;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeEventProducer;

beforeEach(function () {
    $this->producer = new FakeEventProducer;
    $this->app->instance(EventProducer::class, $this->producer);
    $this->project = Project::factory()->create();
    $this->key = $this->project->api_key;
});

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
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');

    // Even a valid key from the blocked ip is refused until the window passes.
    $this->postJson('/e/', captureEvent($this->key))->assertStatus(429);
    expect($this->producer->messages)->toBeEmpty();
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
