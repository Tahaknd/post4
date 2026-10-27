<?php

use App\Http\HistoryEncryption;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/*
 * Inertia's encrypted history needs crypto.subtle, which browsers only offer in a secure context (https, localhost).
 * On a plain-http LAN address it threw in the browser and no signed-in page ever finished loading, so encryption is
 * decided per request (App\Http\HistoryEncryption).
 */

afterEach(fn () => TrustProxies::flushState());

function bootProxies(string|array|null $proxies): void
{
    config(['proxies.trusted' => $proxies]);
    app()->getProvider(AppServiceProvider::class)->boot();
}

function encrypted($response): bool
{
    return ($response->viewData('page')['encryptHistory'] ?? false) === true;
}

it('serves a signed-in page without encrypted history on plain http at a LAN or server address', function (string $url) {
    $response = $this->actingAs(User::factory()->create())->get($url)->assertOk();

    expect(encrypted($response))->toBeFalse();
})->with(['http://192.168.1.10/', 'http://10.0.0.5:8000/', 'http://analytics.example/', 'http://203.0.113.7/']);

it('encrypts on https and on localhost hosts', function (string $url) {
    $response = $this->actingAs(User::factory()->create())->get($url)->assertOk();

    expect(encrypted($response))->toBeTrue();
})->with(['https://analytics.example/', 'http://localhost/', 'http://localhost:8000/', 'http://127.0.0.1:8000/', 'http://127.1.2.3/', 'http://[::1]:8000/', 'http://app.localhost/']);

it('counts https seen through a TRUSTED proxy, and ignores the header from one that is not trusted', function () {
    $user = User::factory()->create();

    bootProxies('*');
    expect(encrypted($this->actingAs($user)->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://192.168.1.10/')))->toBeTrue();

    TrustProxies::flushState();
    bootProxies(null);
    expect(encrypted($this->actingAs($user)->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://192.168.1.10/')))->toBeFalse();
});

it('does not trust names that merely look like localhost', function (string $host) {
    $request = Request::create("http://{$host}/");

    expect(HistoryEncryption::secureContext($request))->toBeFalse();
})->with(['localhost.evil.example', 'evil-localhost', 'notlocalhost', '127.0.0.1.evil.example', '192.168.1.10', '0.0.0.0', 'localhost.']);

it('follows INERTIA_ENCRYPT_HISTORY: true always, false never, auto and anything else per request', function (string $mode, string $url, bool $expected) {
    config(['inertia.history.mode' => $mode]);

    expect(encrypted($this->actingAs(User::factory()->create())->get($url)))->toBe($expected);
})->with([
    'true on a LAN address' => ['true', 'http://192.168.1.10/', true],
    'TRUE is read case-insensitively' => ['TRUE', 'http://192.168.1.10/', true],
    'false on localhost' => ['false', 'http://localhost/', false],
    'false on https' => ['false', 'https://analytics.example/', false],
    'auto on a LAN address' => ['auto', 'http://192.168.1.10/', false],
    'auto on localhost' => ['auto', 'http://localhost/', true],
    'unknown value acts as auto (LAN)' => ['maybe', 'http://192.168.1.10/', false],
    'unknown value acts as auto (localhost)' => ['maybe', 'http://localhost/', true],
]);

it('defaults to auto and keeps the Inertia-wide switch off', function () {
    $config = require base_path('config/inertia.php');

    expect(config('inertia.history.mode'))->toBe('auto')
        ->and($config['history']['encrypt'])->toBeFalse()
        ->and(file_get_contents(base_path('config/inertia.php')))->toContain("env('INERTIA_ENCRYPT_HISTORY', 'auto')")
        ->and(file_get_contents(base_path('.env.example')))->toContain('INERTIA_ENCRYPT_HISTORY=auto');
});

it('never encrypts the guest pages, wherever they are served', function (string $url) {
    expect($this->get($url)->viewData('page'))->not->toHaveKey('encryptHistory');
})->with(['http://localhost/login', 'http://192.168.1.10/login', 'https://analytics.example/login']);

it('serves the error page of a signed-in user the same way as the other pages', function (string $url, bool $expected) {
    $response = $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

    expect(encrypted($response))->toBe($expected);
})->with([
    'LAN http' => ['http://192.168.1.10/horizon', false],
    'localhost' => ['http://localhost/horizon', true],
    'https' => ['https://analytics.example/horizon', true],
]);

it('still clears the history on sign-out, wherever the user was', function (string $origin) {
    $user = User::factory()->create();

    $this->actingAs($user)->post("{$origin}/logout")->assertRedirect();

    expect($this->get("{$origin}/login")->viewData('page'))->toHaveKey('clearHistory', true);
})->with(['http://localhost', 'http://192.168.1.10']);
