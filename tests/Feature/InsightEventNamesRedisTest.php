<?php

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesBusy;
use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\Runs\InsightGate;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Tests\Support\RedisIntegration;
use Tests\Support\ScriptedReads;

/*
 * The event-name list's state against a real Redis (database 15, random key prefix; Tests\Support\RedisIntegration):
 * the single slot is atomic and owner-checked across two "workers" and expires by itself, the cached answer always
 * carries a TTL. Opt-in like the other Redis tests:
 *
 *   docker compose exec -T -e REDIS_INTEGRATION_HOST=redis app php artisan test --group=redis
 */

pest()->group('redis');

beforeEach(function () {
    config(['insights.events_cache_ttl_seconds' => 120, 'insights.events_empty_search_cache_ttl_seconds' => 300, 'insights.events_lock_ttl_seconds' => 15, 'insights.events_max_execution_seconds' => 3, 'insights.events_wait_ms' => 0, 'insights.events_project_budget_ms' => 10000]);
    $this->it = RedisIntegration::connect();
    $this->workerA = $this->it->newRepository();
    $this->workerB = $this->it->newRepository();
    $this->real = fn (string $key) => RedisIntegration::DB_PREFIX.$this->it->storePrefix.$key;
    $this->ttl = fn (string $key) => $this->it->raw()->ttl(($this->real)($key));
});

afterEach(function () {
    if (isset($this->it)) {
        $keys = $this->it->raw()->keys(RedisIntegration::DB_PREFIX.$this->it->storePrefix.'*');
        if ($keys !== []) {
            $this->it->raw()->del(...$keys);
        }
        $this->it->cleanup();
    }
});

it('lets one worker hold the single slot, with a TTL, and frees it by release and by expiry', function () {
    $a = new InsightGate($this->workerA);
    $b = new InsightGate($this->workerB);

    $one = $a->acquireEventNames();
    $two = $b->acquireEventNames();

    expect($one)->not->toBeNull()->and($two)->toBeNull()
        ->and(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBeGreaterThan(0)->toBeLessThanOrEqual(15);

    $one->release();
    expect(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBe(-2); // the key is gone

    $three = $b->acquireEventNames();
    expect($three)->not->toBeNull();

    // a holder that never lets go (killed request): the TTL frees the slot
    $this->it->raw()->expire(($this->real)(InsightGate::EVENT_NAMES_KEY), 1);
    sleep(2);
    expect($a->acquireEventNames())->not->toBeNull();
});

it('releases only for the owner: a stale holder cannot free the slot of the one that took it over', function () {
    $a = new InsightGate($this->workerA);

    $stale = $a->acquireEventNames();
    // the slot expires while the stale holder is still working, another request takes it
    $this->it->raw()->del(($this->real)(InsightGate::EVENT_NAMES_KEY));
    $current = (new InsightGate($this->workerB))->acquireEventNames();
    expect($current)->not->toBeNull();

    $stale->release();

    expect((new InsightGate($this->workerA))->acquireEventNames())->toBeNull()
        ->and(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBeGreaterThan(0);
    $current->release();
});

it('caches the answer with the configured TTL, per project and normalised text, and serves it without a second query', function () {
    $reads = new ScriptedReads([[['name' => 'signup', 'total' => 5]], [['name' => 'signup', 'total' => 6]]]);
    $names = new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, new InsightGate($this->workerA), $this->workerA);

    $first = $names->list(77, null);
    $again = $names->list(77, null);
    $filtered = $names->list(77, '  SIGN ');
    $sameFiltered = $names->list(77, 'sign');

    expect($first)->toBe(['events' => [['name' => 'signup', 'count' => 5]], 'truncated' => false])
        ->and($again)->toBe($first)
        ->and($filtered['events'][0]['count'])->toBe(6)->and($sameFiltered)->toBe($filtered)
        ->and($reads->calls)->toHaveCount(2)
        ->and(($this->ttl)(EventNames::cacheKey(77, null)))->toBeGreaterThan(120)->toBeLessThanOrEqual(300)
        ->and(($this->ttl)(EventNames::cacheKey(77, 'sign')))->toBeGreaterThan(0)->toBeLessThanOrEqual(120)
        ->and(($this->ttl)(EventNames::cacheKey(78, null)))->toBe(-2);
});

it('leaves no slot behind after a query that fails and no cache entry either', function () {
    $reads = new ScriptedReads([new RuntimeException('boom')]);
    $names = new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, new InsightGate($this->workerA), $this->workerA);

    expect(fn () => $names->list(79, 'x'))->toThrow(RuntimeException::class);

    expect(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBe(-2)->and(($this->ttl)(EventNames::cacheKey(79, 'x')))->toBe(-2);
});

it('throws busy while another worker holds the slot and the cache has nothing', function () {
    $held = (new InsightGate($this->workerB))->acquireEventNames();
    $names = new EventNames(new ProjectScopedReader(new ScriptedReads([[]])), new EventNamesSqlBuilder, new InsightGate($this->workerA), $this->workerA);

    expect(fn () => $names->list(80, null))->toThrow(EventNamesBusy::class);
    $held->release();
    expect($names->list(80, null))->toBe(['events' => [], 'truncated' => false]);
});

it('keeps every key of the event list with a TTL: slot, project lock, single flight and the minute budget', function () {
    $gate = new InsightGate($this->workerA);
    $slot = $gate->acquireEventNames();
    $project = $gate->acquireEventNamesProject(81);
    $flight = $gate->acquireEventNamesFlight(81, hash('sha256', 'q'));
    $gate->chargeEventNames(81, 250);
    $gate->chargeEventNames(81, 100);

    $budget = InsightGate::eventBudgetKey(81, intdiv(now()->timestamp, 60));
    foreach ([InsightGate::EVENT_NAMES_KEY, 'insights:events:project:81', 'insights:events:flight:81:'.hash('sha256', 'q'), $budget] as $key) {
        expect(($this->ttl)($key))->toBeGreaterThan(0, "{$key} has no expiry");
    }
    expect(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBeLessThanOrEqual(15)
        ->and(($this->ttl)($budget))->toBeLessThanOrEqual(120)
        ->and($gate->eventNamesUsedMs(81))->toBe(350);

    $slot->release();
    $project->release();
    $flight->release();
});

/** Runs tests/Support/events_race_worker.php in the background; returns the process and its stdout pipe. */
function eventsWorker(object $t, array $args): array
{
    $command = [PHP_BINARY, base_path('tests/Support/events_race_worker.php'), $args[0], RedisIntegration::host(), $t->it->storePrefix, ...array_slice($args, 1)];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->not->toBeFalse();

    return [$process, $pipes];
}

function eventsWorkerFinish(array $worker): string
{
    [$process, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    expect(proc_close($process))->toBe(0, "worker failed: {$err}");

    return trim($out);
}

it('waits for the slot of another process (a real child) and takes it as soon as it is released', function () {
    config(['insights.events_wait_ms' => 3000]);
    $worker = eventsWorker($this, ['slot', '1']);
    expect(fgets($worker[1][1]))->toBe("held\n");

    $reads = new ScriptedReads([[['name' => 'signup', 'total' => 2]]]);
    $names = new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, new InsightGate($this->workerA), $this->workerA);

    $start = hrtime(true);
    $answer = $names->list(82, 'sig');
    $waited = (hrtime(true) - $start) / 1e9;

    expect($answer['events'][0]['name'])->toBe('signup')->and($waited)->toBeGreaterThan(0.5)->toBeLessThan(2.9)->and($reads->calls)->toHaveCount(1);
    expect(eventsWorkerFinish($worker))->toBe('released');
});

it('answers busy once the bounded wait is over while another process keeps the slot', function () {
    config(['insights.events_wait_ms' => 400]);
    $worker = eventsWorker($this, ['slot', '2']);
    expect(fgets($worker[1][1]))->toBe("held\n");
    $names = new EventNames(new ProjectScopedReader(new ScriptedReads([[]])), new EventNamesSqlBuilder, new InsightGate($this->workerA), $this->workerA);

    $start = hrtime(true);
    expect(fn () => $names->list(83, 'x'))->toThrow(EventNamesBusy::class);
    $waited = (hrtime(true) - $start) / 1e9;

    expect($waited)->toBeGreaterThan(0.3)->toBeLessThan(1.5);
    eventsWorkerFinish($worker);
});

it('serves the answer of the process that is asking ClickHouse for the same search instead of asking again', function () {
    config(['insights.events_wait_ms' => 3000]);
    $worker = eventsWorker($this, ['flight', '84', 'same', '0.6']);
    expect(fgets($worker[1][1]))->toBe("held\n");
    $reads = new ScriptedReads([[['name' => 'should-not-be-asked', 'total' => 1]]]);
    $names = new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, new InsightGate($this->workerA), $this->workerA);

    $start = hrtime(true);
    $answer = $names->list(84, 'same');
    $waited = (hrtime(true) - $start) / 1e9;

    expect($answer['events'])->toBe([['name' => 'from-the-holder', 'count' => 42]])->and($reads->calls)->toBe([])->and($waited)->toBeGreaterThan(0.4)->toBeLessThan(2.5);
    eventsWorkerFinish($worker);
});

it('lets a tenant that hammers distinct searches spend only its budget, while another tenant keeps being served', function () {
    config(['insights.events_wait_ms' => 1500]);
    // A: 40 distinct searches of 100 ms each against a budget of 800 ms, in a child process, while B asks 12 times.
    $worker = eventsWorker($this, ['hammer', '85', '40', '100', '800']);

    $reads = new ScriptedReads(array_fill(0, 12, [['name' => 'b-event', 'total' => 1]]));
    $names = new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, new InsightGate($this->workerA), $this->workerA);
    $served = 0;
    for ($i = 0; $i < 12; $i++) {
        try {
            $names->list(86, "mine{$i}");
            $served++;
        } catch (EventNamesBusy) {
            // counted below
        }
        usleep(30_000);
    }

    $outcomes = json_decode(eventsWorkerFinish($worker), true);
    $okA = count(array_filter($outcomes, fn ($code) => $code === 200));

    expect($served)->toBe(12, 'the other tenant was starved')
        ->and($okA)->toBeLessThanOrEqual(10)->toBeGreaterThanOrEqual(7)
        ->and(array_count_values($outcomes)[429] ?? 0)->toBeGreaterThanOrEqual(30);
});
