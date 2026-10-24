<?php

use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeViteBuild;

beforeEach(function () {
    $this->publicPath = FakeViteBuild::install();
});

afterEach(function () {
    FakeViteBuild::remove($this->publicPath);
});

it('renders the Home page component', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Home'));
});

it('shares only the app name and validation errors with every page', function () {
    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('app.name', config('app.name'))
        ->has('errors', 0)
        ->has('app', fn (AssertableInertia $app) => $app->has('name')->etc())
        ->missing('auth')
        ->missing('user'));

    $props = $this->get('/')->viewData('page')['props'];

    expect(array_keys($props))->toEqualCanonicalizing(['errors', 'app'])
        ->and(array_keys($props['app']))->toBe(['name']);
});

it('answers an Inertia visit with JSON instead of the root view', function () {
    $version = hash_file('xxh128', $this->publicPath.'/build/manifest.json');

    $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Home')
        ->assertJsonPath('version', $version);
});

it('derives the asset version from the Vite manifest and asks stale clients to reload', function () {
    $current = hash_file('xxh128', $this->publicPath.'/build/manifest.json');

    expect((new HandleInertiaRequests)->version(request()))->toBe($current);

    $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'a-build-from-last-week'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('references the built Vite assets from the root view', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="stylesheet" href="'.url('build/'.FakeViteBuild::CSS).'"')
        ->toContain('<script type="module" src="'.url('build/'.FakeViteBuild::JS).'"')
        ->toContain('<script data-page="app" type="application/json">')
        ->toContain('<div id="app"></div>');
});

it('loads nothing from outside the application (no CDN or font links)', function () {
    $html = $this->get('/')->getContent();

    preg_match_all('/(?:href|src)="([^"]+)"/', $html, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $url) {
        expect(str_starts_with($url, url('/').'/') || str_starts_with($url, '/'))
            ->toBeTrue("Root view loads an external resource: {$url}");
    }
});
