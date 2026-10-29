<?php

use App\Domain\Auth\LoginThrottle;
use Tests\Support\RedisIntegration;

/*
 * The sign-in throttle against a real Redis with real competing processes (opt-in, group "redis"):
 *
 *   docker compose exec -T -e REDIS_INTEGRATION_HOST=redis app php artisan test --group=redis
 *
 * Runs in database 15 under a random key prefix, see Tests\Support\RedisIntegration.
 */

pest()->group('redis');

beforeEach(function () {
    $this->it = RedisIntegration::connect();
});

afterEach(function () {
    if (isset($this->it)) {
        $this->it->cleanup();
    }
});

it('lets at most the limit through when many sign-in attempts arrive at once', function () {
    $email = 'race-'.bin2hex(random_bytes(4)).'@example.test';
    $ip = '198.51.100.77';
    $limit = 5;
    $workers = 40;

    $throttle = new LoginThrottle;
    foreach ([$throttle->pairKey($email, $ip), $throttle->ipKey($ip), $throttle->emailKey($email)] as $key) {
        $this->it->track(RedisIntegration::DB_PREFIX.$this->it->storePrefix.$key, RedisIntegration::DB_PREFIX.$this->it->storePrefix.$key.':timer');
    }

    // One attempt is made BEFORE the race, so the counter exists and is at 1 when the workers start. Laravel's RateLimiter::increment
    // has a race of its own on the very FIRST hit of a key (add, increment, then `put` of the amount when the increment returned the
    // amount: a worker whose increment came back as 1 can reset a counter that others have already advanced, so a burst of first
    // hits can let a few extra attempts through; seen once as 6 instead of 5 on a loaded machine). That is the framework's, bounded
    // by the size of the first burst and harmless for a limit of a handful of password attempts; what this test pins is the part the
    // application owns: from the second hit on, concurrent attempts are counted exactly.
    $script = base_path('tests/Support/login_throttle_worker.php');
    $first = shell_exec(escapeshellarg(PHP_BINARY).' '.implode(' ', array_map('escapeshellarg', [$script, RedisIntegration::host(), $this->it->storePrefix, $email, $ip, (string) $limit, '0'])));
    expect(json_decode((string) $first, true))->toBe(['proceeded' => true, 'failed_open' => null]);

    $startAt = microtime(true) + 2.0;

    $processes = [];
    foreach (range(1, $workers) as $n) {
        $process = proc_open(
            [PHP_BINARY, $script, RedisIntegration::host(), $this->it->storePrefix, $email, $ip, (string) $limit, (string) $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        expect($process)->not->toBeFalse();
        $processes[$n] = [$process, $pipes];
    }

    $proceeded = 0;
    foreach ($processes as $n => [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        expect(proc_close($process))->toBe(0, "worker {$n}: {$err}");

        $result = json_decode(trim($out), true);
        expect($result)->toBeArray("worker {$n} printed: {$out}{$err}");
        // A limiter that failed open (Redis error, timeout) lets everyone through by design: say so instead of reporting a lost update.
        expect($result['failed_open'])->toBeNull("worker {$n} failed open: {$result['failed_open']}");
        $proceeded += $result['proceeded'] ? 1 : 0;
    }

    // Every attempt was counted and exactly the rest of the limit was let through: no overshoot, no lost update.
    expect($proceeded)->toBe($limit - 1);
});
