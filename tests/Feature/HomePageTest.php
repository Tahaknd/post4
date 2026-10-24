<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\File;
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
    $version = hash_file('md5', $this->publicPath.'/build/manifest.json');

    $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Home')
        ->assertJsonPath('version', $version);
});

it('derives the asset version from the Vite manifest and asks stale clients to reload', function () {
    $current = hash_file('md5', $this->publicPath.'/build/manifest.json');

    expect((new HandleInertiaRequests)->version(request()))->toBe($current);

    $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'a-build-from-last-week'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('keeps deriving the version from the manifest when ASSET_URL is set', function () {
    // Inertia's own implementation would return a hash of the URL here, which no build ever changes.
    config(['app.asset_url' => 'https://cdn.example']);

    $manifestHash = hash_file('md5', $this->publicPath.'/build/manifest.json');

    expect((new HandleInertiaRequests)->version(request()))->toBe($manifestHash)
        ->and($manifestHash)->not->toBe(hash('xxh128', 'https://cdn.example'));
});

it('has no asset version when nothing is built', function () {
    File::delete($this->publicPath.'/build/manifest.json');

    expect((new HandleInertiaRequests)->version(request()))->toBeNull();
});

it('references the built Vite assets from the root view', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="stylesheet" href="'.url('build/'.FakeViteBuild::CSS).'"')
        ->toContain('<script type="module" src="'.url('build/'.FakeViteBuild::JS).'"')
        ->toContain('<script data-page="app" type="application/json">')
        ->toContain('<div id="app"></div>');
});

it('loads nothing from outside the application (no CDN, protocol-relative or font links)', function () {
    $html = $this->get('/')->getContent();

    preg_match_all('/(?:href|src)="([^"]+)"/', $html, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $url) {
        // "//host/x" is a protocol-relative URL to another host, so a bare "/" prefix is not enough.
        $local = str_starts_with($url, url('/').'/') || (str_starts_with($url, '/') && ! str_starts_with($url, '//'));

        expect($local)->toBeTrue("Root view loads an external resource: {$url}");
    }
});

it('keeps the built assets free of external URLs apart from an explicit allow-list', function () {
    $files = glob(base_path('public/build/assets/*.{css,js}'), GLOB_BRACE) ?: [];

    if ($files === []) {
        $this->markTestSkipped('public/build is not built here (npm run build, or the Docker assets stage); the root view test above still runs.');
    }

    // XML namespaces and error references that Vue and Tailwind carry as plain strings or comments.
    $allowed = [
        'http://www.w3.org/',
        'https://vuejs.org/error-reference/',
        'https://tailwindcss.com',
        'http://localhost',
    ];

    $external = [];

    foreach ($files as $file) {
        preg_match_all('#https?://[^\s"\'`)\\\\]+#', (string) file_get_contents($file), $found);

        foreach ($found[0] as $url) {
            $isAllowed = array_filter($allowed, fn (string $prefix) => str_starts_with($url, $prefix)) !== [];

            if (! $isAllowed) {
                $external[] = basename($file).': '.$url;
            }
        }
    }

    expect($external)->toBe([]);
});
