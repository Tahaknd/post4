<?php

/*
 * Child process of the sign-in throttle race test (LoginThrottleRedisTest). Not a test itself.
 *
 *   php login_throttle_worker.php <host> <storePrefix> <email> <ip> <pairLimit> <startAt>
 *
 * Boots only what the throttle needs, waits for <startAt> so all workers start together, makes ONE
 * LoginThrottle::hit() and prints {"proceeded": bool}: whether it would have gone on to check the password.
 */

use App\Domain\Auth\LoginThrottle;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use Tests\Support\RedisIntegration;

require __DIR__.'/../../vendor/autoload.php';

[, $host, $storePrefix, $email, $ip, $pairLimit, $startAt] = $argv;

putenv("REDIS_INTEGRATION_HOST={$host}");

$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Config([
    'auth' => ['login_throttle' => ['email_ip_limit' => (int) $pairLimit, 'ip_limit' => 1000, 'email_limit' => 1000, 'window_seconds' => 120]],
    'ratelimit' => ['warning_interval_seconds' => 60],
]));
$app->instance('log', new NullLogger);
$cache = RedisIntegration::repository(RedisIntegration::manager($app), $storePrefix);
$app->instance('cache', $cache);
$app->instance(CacheRateLimiter::class, new CacheRateLimiter($cache));
Facade::setFacadeApplication($app);

while (microtime(true) < (float) $startAt) {
    usleep(300);
}

echo json_encode(['proceeded' => (new LoginThrottle)->hit($email, $ip) === null]), "\n";
