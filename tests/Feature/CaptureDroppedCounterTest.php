<?php

use App\Domain\Capture\EventProducer;
use App\Models\Project;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeEventProducer;
use Tests\Support\ProjectCache;

beforeEach(function () {
    Carbon::setTestNow('2026-10-22 12:34:56');
    $this->producer = new FakeEventProducer;
    $this->app->instance(EventProducer::class, $this->producer);
    $this->project = Project::factory()->create();
    $this->key = $this->project->api_key;
    $this->bucket = "capture-dropped:{$this->project->id}:2026102212";
});

afterEach(function () {
    Carbon::setTestNow();
    LimiterOutage::reset();
});

/** A batch with $good valid and $bad invalid events (the normaliser rejects an empty event name). */
function mixedBatch(string $key, int $good, int $bad): array
{
    $events = [];
    for ($i = 0; $i < $good; $i++) {
        $events[] = ['event' => 'good', 'distinct_id' => 'u'];
    }
    for ($i = 0; $i < $bad; $i++) {
        $events[] = ['event' => '', 'distinct_id' => 'u'];
    }

    return ['api_key' => $key, 'batch' => $events];
}

it('adds the dropped events of a request to the per-project hourly counter', function () {
    $this->postJson('/batch/', mixedBatch($this->key, 3, 2))->assertOk()->assertExactJson(['status' => 1]);

    expect(Cache::get($this->bucket))->toBe(2)
        ->and($this->producer->messages)->toHaveCount(3);
});

it('accumulates across requests', function () {
    $this->postJson('/batch/', mixedBatch($this->key, 1, 1))->assertOk();
    $this->postJson('/batch/', mixedBatch($this->key, 0, 3))->assertOk();

    expect(Cache::get($this->bucket))->toBe(4);
});

it('creates no counter when nothing was dropped', function () {
    $this->postJson('/batch/', mixedBatch($this->key, 3, 0))->assertOk();

    expect(Cache::has($this->bucket))->toBeFalse();
});

it('uses the hour bucket of the request time in UTC, with a two hour TTL', function () {
    $this->postJson('/batch/', mixedBatch($this->key, 1, 1))->assertOk();

    Carbon::setTestNow('2026-10-22 13:00:00');
    $this->postJson('/batch/', mixedBatch($this->key, 1, 2))->assertOk();

    expect(Cache::get($this->bucket))->toBe(1)
        ->and(Cache::get("capture-dropped:{$this->project->id}:2026102213"))->toBe(2);

    // The array store keeps no TTL to inspect; the contract is that add() uses 7200 s.
    Cache::spy();
    $this->postJson('/batch/', mixedBatch($this->key, 1, 1))->assertOk();
    Cache::shouldHaveReceived('add')->with("capture-dropped:{$this->project->id}:2026102213", 0, 7200)->once();
    Cache::shouldHaveReceived('increment')->with("capture-dropped:{$this->project->id}:2026102213", 1)->once();
});

it('counts per project', function () {
    $other = Project::factory()->create();

    $this->postJson('/batch/', mixedBatch($this->key, 0, 2))->assertOk();
    $this->postJson('/batch/', mixedBatch($other->api_key, 0, 5))->assertOk();

    expect(Cache::get($this->bucket))->toBe(2)
        ->and(Cache::get("capture-dropped:{$other->id}:2026102212"))->toBe(5);
});

it('counts a request in which every event was dropped', function () {
    $this->postJson('/batch/', mixedBatch($this->key, 0, 4))->assertOk();

    expect(Cache::get($this->bucket))->toBe(4)->and($this->producer->messages)->toBeEmpty();
});

it('does not count drops of a request that was not accepted (retries would double count)', function () {
    $this->producer->fail = true;
    $this->postJson('/batch/', mixedBatch($this->key, 1, 2))->assertStatus(503);

    // One invalid event plus 25 valid ones in 25 distinct months: over the 24 month cap.
    $batch = [['event' => '', 'distinct_id' => 'u']];
    for ($i = 0; $i < 25; $i++) {
        $batch[] = ['event' => 'e', 'distinct_id' => 'u', 'timestamp' => sprintf('%04d-%02d-01T00:00:00Z', 2000 + intdiv($i, 12), $i % 12 + 1)];
    }
    $months = ['api_key' => $this->key, 'batch' => $batch];
    $this->producer->fail = false;
    $this->postJson('/batch/', $months)->assertStatus(400)->assertJsonPath('code', 'too_many_months');

    expect(Cache::has($this->bucket))->toBeFalse();
});

it('fails open when the cache throws: the request is still answered 200 and produced', function () {
    ProjectCache::breakStore();
    Log::spy();

    $this->postJson('/batch/', mixedBatch($this->key, 2, 1))->assertOk()->assertExactJson(['status' => 1]);

    expect($this->producer->messages)->toHaveCount(2);
});
