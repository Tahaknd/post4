<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

const SECURITY_HEADER_NAMES = ['X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'Cross-Origin-Opener-Policy', 'X-Frame-Options'];

/** The headers the middleware adds to a UI page, and the policy it sends by default. */
function expectUiHeaders($response, string $csp = 'Content-Security-Policy-Report-Only'): void
{
    $headers = $response->headers;

    expect($headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=()')
        ->and($headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
        ->and($headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($headers->get($csp))->toBe(config('security.csp'));
}

describe('UI pages', function () {
    it('carry the security headers and a report-only policy by default', function () {
        $response = $this->get('/login')->assertOk();

        expectUiHeaders($response);
        expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
    });

    it('carry them on signed-in pages, error pages and redirects too', function () {
        $user = User::factory()->create();

        expectUiHeaders($this->actingAs($user)->get('/projects')->assertOk());
        expectUiHeaders($this->actingAs($user)->get('/projects/999999')->assertNotFound());
    });

    it('carry them on a guest redirect answered before the session starts', function () {
        expectUiHeaders($this->get('/projects')->assertRedirect());
    });

    it('sends a policy that keeps everything on this origin and forbids framing', function () {
        $policy = config('security.csp');

        expect($policy)->toContain("default-src 'self'")
            ->toContain("script-src 'self'")
            ->not->toContain("script-src 'self' 'unsafe")
            ->toContain("object-src 'none'")
            ->toContain("frame-ancestors 'none'")
            ->toContain("form-action 'self'")
            ->toContain("connect-src 'self'")
            ->not->toMatch('#https?://#');
    });

    it('sends Strict-Transport-Security only when the request is secure', function () {
        expect($this->get('/login')->headers->has('Strict-Transport-Security'))->toBeFalse();

        $secure = $this->get('https://localhost/login')->assertOk();
        expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
        expectUiHeaders($secure);
    });
});

describe('CSP mode', function () {
    it('switches between report-only, enforce and off', function (string $mode, ?string $header) {
        config(['security.csp_mode' => $mode]);

        $response = $this->get('/login')->assertOk();

        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $name) {
            expect($response->headers->has($name))->toBe($name === $header);
        }
        // The other headers do not depend on the policy.
        expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    })->with([
        'report-only' => ['report-only', 'Content-Security-Policy-Report-Only'],
        'enforce' => ['enforce', 'Content-Security-Policy'],
        'off' => ['off', null],
        'garbage means off' => ['maybe', null],
    ]);

    it('is report-only by default', function () {
        expect(config('security.csp_mode'))->toBe('report-only');
    });

    it('sends no policy while the Vite dev server runs (public/hot exists)', function () {
        File::put(public_path('hot'), 'http://localhost:5173');

        $response = $this->get('/login');

        expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
    });
});

it('can be turned off as a whole', function () {
    config(['security.headers_enabled' => false]);

    $response = $this->get('/login')->assertOk();

    foreach ([...SECURITY_HEADER_NAMES, 'Content-Security-Policy-Report-Only', 'Strict-Transport-Security'] as $name) {
        expect($response->headers->has($name))->toBeFalse($name);
    }
});

it('leaves a header a controller already set alone', function () {
    Route::middleware('web')->get('/_security-test', fn () => response('x')->header('X-Frame-Options', 'SAMEORIGIN'));

    expect($this->get('/_security-test')->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

describe('everything outside the UI pages', function () {
    it('is not touched: capture, the SDK stubs, /up and the insight API', function () {
        $project = Project::factory()->create();
        $key = $project->api_key;
        $member = User::factory()->create();
        $project->users()->attach($member);

        $answers = [
            'capture' => $this->postJson('/e/', ['api_key' => $key, 'event' => 'x', 'distinct_id' => 'u'], ['Origin' => 'https://a.example']),
            'capture 401' => $this->postJson('/e/', ['api_key' => Project::generateApiKey(), 'event' => 'x', 'distinct_id' => 'u']),
            'config' => $this->get("/array/{$key}/config"),
            'config.js' => $this->get("/array/{$key}/config.js"),
            'flags' => $this->postJson('/flags', ['token' => $key, 'distinct_id' => 'u']),
            'decide' => $this->postJson('/decide', ['token' => $key, 'distinct_id' => 'u']),
            'up' => $this->get('/up'),
            'api' => $this->actingAs($member)->getJson("/api/projects/{$project->id}/insights/events"),
        ];

        foreach ($answers as $name => $response) {
            foreach (['X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Cross-Origin-Opener-Policy', 'Content-Security-Policy-Report-Only', 'Content-Security-Policy', 'Strict-Transport-Security'] as $header) {
                expect($response->headers->has($header))->toBeFalse("{$name} must not carry {$header}");
            }
        }

        // The stubs set nosniff themselves.
        foreach (['config', 'config.js', 'flags', 'decide'] as $name) {
            expect($answers[$name]->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        }
    });

    it('runs on the web group only', function () {
        app(Kernel::class);
        $groups = app('router')->getMiddlewareGroups();

        expect($groups['web'])->toContain(SecurityHeaders::class)
            ->and(app(Kernel::class)->getGlobalMiddleware())->not->toContain(SecurityHeaders::class);

        foreach ($groups as $name => $middleware) {
            if ($name !== 'web') {
                expect($middleware)->not->toContain(SecurityHeaders::class, "group {$name}");
            }
        }
    });
});

describe('Horizon', function () {
    it('gets the headers but no content security policy (its pages use inline scripts and styles)', function () {
        $response = $this->actingAs(User::factory()->admin()->create())->get('/horizon')->assertOk();

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
            ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
    });

    it('is excluded from the policy only below /horizon, not for a path that merely starts with it', function () {
        config(['security.csp_mode' => 'enforce']);
        Route::middleware('web')->get('/horizons', fn () => 'x');

        expect($this->get('/horizons')->headers->has('Content-Security-Policy'))->toBeTrue();
        expect($this->actingAs(User::factory()->admin()->create())->get('/horizon/dashboard')->headers->has('Content-Security-Policy'))->toBeFalse();
    });
});
