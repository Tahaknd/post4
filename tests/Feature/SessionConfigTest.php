<?php

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Env;
use Tests\Support\FakeViteBuild;

/**
 * Evaluates a config file as the framework would with the given environment values. The real env
 * repository is immutable (Compose already defines APP_URL), so a private mutable one is swapped in.
 */
function configFileWith(string $file, array $env): array
{
    $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();

    foreach ($env as $name => $value) {
        if ($value !== null) {
            $repository->set($name, $value);
        }
    }

    $property = new ReflectionProperty(Env::class, 'repository');
    $original = $property->getValue();
    $property->setValue(null, $repository);

    try {
        return require config_path($file);
    } finally {
        $property->setValue(null, $original);
    }
}

it('gives sessions their own bounded Redis connection on database 2', function () {
    // Evaluated without the container's environment: Compose sets REDIS_SESSION_DB=0 for the dedicated instance.
    $redis = configFileWith('database.php', [])['redis'];

    expect($redis)->toHaveKey('session')
        ->and($redis['session']['database'])->toBe('2')
        ->and($redis['session']['database'])->not->toBe($redis['default']['database'])
        ->and($redis['session']['database'])->not->toBe($redis['cache']['database'])
        ->and($redis['session']['timeout'])->toBeFloat()->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0)
        ->and($redis['session']['read_timeout'])->toBeFloat()->toBeGreaterThan(0.0)->toBeLessThanOrEqual(2.0)
        ->and($redis['default'])->not->toHaveKey('read_timeout');
});

it('makes the redis session driver use the session connection', function () {
    config(['session.driver' => 'redis', 'session.connection' => 'session']);
    app('session')->forgetDrivers();

    $store = app('session')->driver()->getHandler()->getCache()->getStore();

    expect($store)->toBeInstanceOf(RedisStore::class);

    $property = new ReflectionProperty(RedisStore::class, 'connection');
    expect($property->getValue($store))->toBe('session');

    // The shared cache repository must not have been switched over to the session connection.
    $cacheStore = new ReflectionProperty(RedisStore::class, 'connection');
    config(['cache.stores.redis.connection' => 'cache']);
    expect($cacheStore->getValue(app('cache')->store('redis')->getStore()))->toBe('cache');
});

it('marks the session cookie Secure when APP_URL is https', function () {
    expect(configFileWith('session.php', ['APP_URL' => 'https://analytics.example.test', 'SESSION_SECURE_COOKIE' => null])['secure'])->toBeTrue()
        ->and(configFileWith('session.php', ['APP_URL' => 'http://localhost:8000', 'SESSION_SECURE_COOKIE' => null])['secure'])->toBeFalse()
        ->and(configFileWith('session.php', ['APP_URL' => 'https://analytics.example.test', 'SESSION_SECURE_COOKIE' => 'false'])['secure'])->toBeFalse()
        ->and(configFileWith('session.php', ['APP_URL' => 'http://localhost:8000', 'SESSION_SECURE_COOKIE' => 'true'])['secure'])->toBeTrue();
});

it('keeps the session cookie HttpOnly, SameSite=Lax and rolling for two hours', function () {
    $config = configFileWith('session.php', ['SESSION_LIFETIME' => null, 'SESSION_HTTP_ONLY' => null, 'SESSION_SAME_SITE' => null]);

    expect($config['http_only'])->toBeTrue()
        ->and($config['same_site'])->toBe('lax')
        ->and($config['lifetime'])->toBe(120)
        ->and($config['expire_on_close'])->toBeFalse()
        ->and($config['encrypt'])->toBeFalse()
        ->and($config['path'])->toBe('/');
});

it('sends the session cookie with HttpOnly and SameSite=Lax, plus Secure over https', function () {
    $public = FakeViteBuild::install();
    config(['session.secure' => true]);

    $cookie = collect($this->get('/login')->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

    FakeViteBuild::remove($public);

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->isSecure())->toBeTrue();
});

it('points the session connection at the dedicated sessions instance when REDIS_SESSION_HOST is set', function () {
    $session = configFileWith('database.php', [
        'REDIS_HOST' => 'redis', 'REDIS_SESSION_HOST' => 'redis-sessions', 'REDIS_SESSION_DB' => '0', 'REDIS_PASSWORD' => 'shared',
    ])['redis']['session'];

    expect($session['host'])->toBe('redis-sessions')
        ->and($session['database'])->toBe('0')
        ->and($session['password'])->toBe('shared');

    // The main and cache connections stay on the main Redis.
    $redis = configFileWith('database.php', ['REDIS_HOST' => 'redis', 'REDIS_SESSION_HOST' => 'redis-sessions'])['redis'];
    expect($redis['default']['host'])->toBe('redis')->and($redis['cache']['host'])->toBe('redis');
});

it('falls back to the main Redis, database 2, when no session host is configured', function () {
    $session = configFileWith('database.php', ['REDIS_HOST' => 'cache-box', 'REDIS_SESSION_HOST' => null])['redis']['session'];

    expect($session['host'])->toBe('cache-box')->and($session['database'])->toBe('2');
});

it('lets the session connection have its own password and port', function () {
    $session = configFileWith('database.php', [
        'REDIS_SESSION_HOST' => 's', 'REDIS_SESSION_PORT' => '6380', 'REDIS_SESSION_PASSWORD' => 'own', 'REDIS_PORT' => '6379', 'REDIS_PASSWORD' => 'main',
    ])['redis']['session'];

    expect($session['port'])->toBe('6380')->and($session['password'])->toBe('own');
});
