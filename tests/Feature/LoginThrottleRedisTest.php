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

    $script = base_path('tests/Support/login_throttle_worker.php');
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
        $proceeded += $result['proceeded'] ? 1 : 0;
    }

    // Every attempt was counted and exactly `limit` of them were let through: no overshoot, no lost update.
    expect($proceeded)->toBe($limit);
});
