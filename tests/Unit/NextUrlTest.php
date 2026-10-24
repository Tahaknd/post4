<?php

use App\Http\NextUrl;

it('accepts a same-origin path with a query', function (string $next) {
    expect(NextUrl::sanitise($next))->toBe($next);
})->with([
    ['/projects/12'],
    ['/projects/12?tab=events&range=7d'],
    ['/horizon'],
    ['/horizon/dashboard'],
    ['/a/b%20c'],
    ['/%2F%2Fevil.example'],
]);

it('refuses everything that could leave the site or loop', function (mixed $next) {
    expect(NextUrl::sanitise($next))->toBeNull();
})->with([
    'null' => [null],
    'array' => [['/x']],
    'empty' => [''],
    'root is the fallback anyway' => ['/'],
    'relative' => ['projects'],
    'absolute https' => ['https://evil.example/'],
    'absolute http with path' => ['http://evil.example/a'],
    'protocol relative' => ['//evil.example'],
    'protocol relative with path' => ['//evil.example/login'],
    'backslash host' => ['/\\evil.example'],
    'backslash anywhere' => ['/a\\b'],
    'slash tab slash (browsers drop the tab)' => ["/\t/evil.example"],
    'line feed' => ["/a\nb"],
    'carriage return' => ["/a\rb"],
    'NUL' => ["/a\0b"],
    'space' => ['/a b'],
    'DEL' => ["/a\x7fb"],
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:text/html,x'],
    'scheme after a slash is just a path, with a colon host' => ['/@evil.example:80'],
    'too long' => ['/'.str_repeat('a', 200)],
    'login' => ['/login'],
    'login with query' => ['/login?next=/x'],
    'register' => ['/register'],
    'logout' => ['/logout'],
    'capture' => ['/e'],
    'capture path' => ['/e/x'],
    'batch' => ['/batch'],
    'sdk' => ['/array/abc/config'],
    'health' => ['/up'],
    'assets' => ['/build/assets/x.js'],
    'api' => ['/api/insights'],
]);

it('accepts exactly 200 characters and refuses 201', function () {
    expect(NextUrl::sanitise('/'.str_repeat('a', 199)))->not->toBeNull()
        ->and(NextUrl::sanitise('/'.str_repeat('a', 200)))->toBeNull();
});
