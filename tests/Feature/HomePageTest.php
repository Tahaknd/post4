<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeViteBuild;

it('redirects a guest from the Home page to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('renders the Home page component for a signed-in user', function () {
    $this->actingAs(User::factory()->create(['name' => 'Ada']))
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Home')
            ->where('projects', [])
            ->where('auth.user.name', 'Ada'));
});

it('lists only the projects the user can open on the Home page, without keys', function () {
    $member = User::factory()->create();
    $mine = Project::factory()->create(['name' => 'Mine']);
    Project::factory()->create(['name' => 'Theirs']);
    $mine->users()->attach($member);

    $props = $this->actingAs($member)->get('/')->viewData('page')['props'];

    expect($props['projects'])->toBe([['id' => $mine->id, 'name' => 'Mine', 'url' => "/projects/{$mine->id}"]]);

    $admin = User::factory()->admin()->create();
    $names = array_column($this->actingAs($admin)->get('/')->viewData('page')['props']['projects'], 'name');

    expect($names)->toBe(['Mine', 'Theirs']);
});

it('shares exactly the app name, errors, auth and flash with a guest page', function () {
    $props = $this->get('/login')->viewData('page')['props'];

    expect(array_keys($props))->toEqualCanonicalizing(['errors', 'app', 'auth', 'projects', 'projectsTruncated', 'trendsUrl', 'funnelUrl', 'retentionUrl', 'dashboardsUrl', 'flash', 'canRegister', 'next'])
        ->and($props['app'])->toBe(['name' => config('app.name')])
        ->and($props['auth'])->toBe(['user' => null])
        ->and($props['projects'])->toBe([])
        ->and($props['projectsTruncated'])->toBeFalse()->and($props['trendsUrl'])->toBeNull()->and($props['funnelUrl'])->toBeNull()->and($props['retentionUrl'])->toBeNull()
        ->and($props['flash'])->toBe(['status' => null]);
});

it('shares the signed-in user as plain id, name, email and is_admin only', function () {
    $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

    $props = $this->actingAs($user)->get('/')->viewData('page')['props'];

    expect($props['auth'])->toBe(['user' => [
        'id' => $user->id,
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'is_admin' => false,
    ]]);

    $admin = User::factory()->admin()->create();
    $adminProps = $this->actingAs($admin)->get('/')->viewData('page')['props'];

    expect($adminProps['auth']['user']['is_admin'])->toBeTrue()
        ->and(json_encode($adminProps))->not->toContain('password')->not->toContain('remember_token');
});

it('shares the flash status message once', function () {
    $this->withSession(['status' => 'Registration is closed.'])
        ->get('/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.status', 'Registration is closed.'));
});

it('answers an Inertia visit with JSON instead of the root view', function () {
    $version = hash_file('md5', $this->publicPath.'/build/manifest.json');

    $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Auth/Login')
        ->assertJsonPath('version', $version);
});

it('derives the asset version from the Vite manifest and asks stale clients to reload', function () {
    $current = hash_file('md5', $this->publicPath.'/build/manifest.json');

    expect((new HandleInertiaRequests)->version(request()))->toBe($current);

    $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'a-build-from-last-week'])
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
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="stylesheet" href="'.url('build/'.FakeViteBuild::CSS).'"')
        ->toContain('<script type="module" src="'.url('build/'.FakeViteBuild::JS).'"')
        ->toContain('<script data-page="app" type="application/json">')
        ->toContain('<div id="app"></div>');
});

it('loads nothing from outside the application (no CDN, protocol-relative or font links)', function () {
    $html = $this->get('/login')->getContent();

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

    // The posthog-js chunk of the demo page (found by its global, not by its hashed file name) is not scanned: it carries
    // PostHog's own default hosts and help links as strings, never requested because the demo always sets `api_host`
    // (the browser e2e, tests/e2e/demo.spec.js, proves every request of the page stays on this origin). What this test
    // keeps is that it is exactly one chunk of its own and that no other bundle carries an external URL.
    $external = [];
    $sdkChunks = 0;

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $isSdk = str_contains($source, '__PosthogExtensions__');
        $sdkChunks += $isSdk ? 1 : 0;

        if ($isSdk) {
            continue;
        }

        preg_match_all('#https?://[^\s"\'`)\\\\]+#', $source, $found);

        foreach ($found[0] as $url) {
            $isAllowed = array_filter($allowed, fn (string $prefix) => str_starts_with($url, $prefix)) !== [];

            if (! $isAllowed) {
                $external[] = basename($file).': '.$url;
            }
        }
    }

    expect($external)->toBe([])
        // The SDK ships as one lazy chunk of its own (never in the main entry).
        ->and($sdkChunks)->toBe(1);
});
