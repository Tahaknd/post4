<?php

use App\Domain\Insights\Runs\InsightRunStore;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardTileController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InsightController;
use App\Http\Controllers\InsightEventsController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectInsightsController;
use App\Http\Controllers\SavedInsightController;
use App\Http\Middleware\EncryptHistoryWhenSecure;
use App\Http\Middleware\EnsureRegistrationOpen;
use Illuminate\Support\Facades\Route;

// UI routes must not start with a prefix in App\Http\ReservedPaths (capture and posthog-js SDK
// endpoints); tests/Feature/ReservedRoutePrefixesTest.php enforces it. Static URLs the frontend links
// to are mirrored in resources/js/routes.js (tests/Feature/FrontendRoutesTest.php keeps them in sync).

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    // Open only while no user exists: the first account becomes the administrator.
    Route::middleware(EnsureRegistrationOpen::class)->group(function (): void {
        Route::get('/register', [RegisterController::class, 'create'])->name('register');
        Route::post('/register', [RegisterController::class, 'store']);
    });
});

// auth.session signs a user out everywhere when their password changes (user:reset-password). Pages are
// served with encrypted history where the browser can do it (https or localhost: App\Http\HistoryEncryption), so the
// back button after sign-out shows nothing readable.
Route::middleware(['auth', 'auth.session', EncryptHistoryWhenSecure::class])->group(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Everything project-related lives under /projects (never a bare /{project}: capture and SDK paths own those
    // first segments). `{project}` is 1 to 18 digits everywhere (Route::pattern in AppServiceProvider) and is bound
    // through Project::visibleTo(user): a project the user cannot open is a 404.
    Route::prefix('projects')->group(function (): void {
        Route::get('/', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('/', [ProjectController::class, 'store'])->name('projects.store')->middleware('throttle.user:projects.create');
        Route::get('/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('/{project}/demo', [ProjectController::class, 'demo'])->name('projects.demo');
        Route::get('/{project}/insights/trends', [ProjectInsightsController::class, 'trends'])->name('projects.insights.trends');
        Route::get('/{project}/insights/funnel', [ProjectInsightsController::class, 'funnel'])->name('projects.insights.funnel');
        Route::get('/{project}/insights/retention', [ProjectInsightsController::class, 'retention'])->name('projects.insights.retention');
        Route::put('/{project}/api-key', [ProjectController::class, 'rotateKey'])->name('projects.rotate-key')->middleware('throttle.user:projects.rotate');
        Route::delete('/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy')->middleware('throttle.user:projects.delete');
    });
});

// The insight JSON API. The ONLY routes under `api/`: ReservedPaths::APP_OWNED keeps UI pages off that prefix, and
// tests/Feature/ReservedRoutePrefixesTest.php requires every `api/` route to be named `api.*` and to live under
// api/projects/{project}/. Same session cookie, CSRF token (X-XSRF-TOKEN) and `auth` as the UI, but JSON in and out:
// a guest gets 401 JSON (never a redirect), errors are fixed JSON (App\Http\ApiErrors), nothing is cached.
// `{project}` is bound through Project::visibleTo(user) (404 for a non-member); `{run}` is a 128 bit hex id.
Route::middleware(['auth', 'auth.session', 'api.no-store'])
    ->prefix('api/projects/{project}/insights')
    ->name('api.insights.')
    ->group(function (): void {
        // One POST per registered insight type (App\Domain\Insights\InsightTypes), the same throttle and the same flow.
        Route::post('/trends', [InsightController::class, 'trends'])->name('trends')->middleware('throttle.user:insights.run');
        Route::post('/funnel', [InsightController::class, 'funnel'])->name('funnel')->middleware('throttle.user:insights.run');
        Route::post('/retention', [InsightController::class, 'retention'])->name('retention')->middleware('throttle.user:insights.run');
        // The event names of the picker: interactive, cached per project, one global ClickHouse slot of its own.
        Route::get('/events', InsightEventsController::class)->name('events')->middleware('throttle.user:insights.events');
        Route::get('/runs/{run}', [InsightController::class, 'show'])->name('runs.show')
            ->where('run', InsightRunStore::ID_ROUTE_PATTERN)
            ->middleware('throttle.user:insights.poll');
        Route::delete('/runs/{run}', [InsightController::class, 'cancel'])->name('runs.cancel')
            ->where('run', InsightRunStore::ID_ROUTE_PATTERN)
            ->middleware('throttle.user:insights.cancel');
    });

// Saved insights and dashboards (Milestone 6). Same session, CSRF, `auth`, JSON errors and project binding as above; the
// `{insight}`, `{dashboard}` and `{tile}` segments are UUIDs (Route::pattern in AppServiceProvider) bound INSIDE the project
// (a foreign one is a 404, and so is anything that is not a uuid). Reads and writes have their own per-user throttle family;
// running a saved insight shares the budget of `insights.run` with running one by hand.
Route::middleware(['auth', 'auth.session', 'api.no-store'])
    ->prefix('api/projects/{project}')
    ->name('api.')
    ->group(function (): void {
        Route::get('/saved-insights', [SavedInsightController::class, 'index'])->name('saved-insights.index')->middleware('throttle.user:dashboards.read');
        Route::post('/saved-insights', [SavedInsightController::class, 'store'])->name('saved-insights.store')->middleware('throttle.user:dashboards.write');
        Route::get('/saved-insights/{insight}', [SavedInsightController::class, 'show'])->name('saved-insights.show')->middleware('throttle.user:dashboards.read');
        Route::patch('/saved-insights/{insight}', [SavedInsightController::class, 'update'])->name('saved-insights.update')->middleware('throttle.user:dashboards.write');
        Route::delete('/saved-insights/{insight}', [SavedInsightController::class, 'destroy'])->name('saved-insights.destroy')->middleware('throttle.user:dashboards.write');
        Route::post('/saved-insights/{insight}/run', [SavedInsightController::class, 'run'])->name('saved-insights.run')->middleware('throttle.user:insights.run');

        Route::get('/dashboards', [DashboardController::class, 'index'])->name('dashboards.index')->middleware('throttle.user:dashboards.read');
        Route::post('/dashboards', [DashboardController::class, 'store'])->name('dashboards.store')->middleware('throttle.user:dashboards.write');
        Route::get('/dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show')->middleware('throttle.user:dashboards.read');
        Route::patch('/dashboards/{dashboard}', [DashboardController::class, 'update'])->name('dashboards.update')->middleware('throttle.user:dashboards.write');
        Route::delete('/dashboards/{dashboard}', [DashboardController::class, 'destroy'])->name('dashboards.destroy')->middleware('throttle.user:dashboards.write');
        Route::post('/dashboards/{dashboard}/tiles', [DashboardTileController::class, 'store'])->name('dashboards.tiles.store')->middleware('throttle.user:dashboards.write');
        Route::put('/dashboards/{dashboard}/tiles/order', [DashboardTileController::class, 'order'])->name('dashboards.tiles.order')->middleware('throttle.user:dashboards.write');
        Route::delete('/dashboards/{dashboard}/tiles/{tile}', [DashboardTileController::class, 'destroy'])->name('dashboards.tiles.destroy')->middleware('throttle.user:dashboards.write');
    });

// Every other method on an `api/` path that no route above takes (a malformed `{project}` segment, a wrong verb) is a
// plain 404 JSON, not Laravel's 405 that would name the verbs the web fallback happens to accept. GET/HEAD are covered
// by the web fallback below. Flagged as a fallback so a guest without a session cookie is answered 404 early too.
Route::match(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], 'api/{rest?}', fn () => abort(404))
    ->where('rest', '.*')
    ->name('api.fallback')
    ->fallback();

// Unknown paths answer 404 inside the web group, so a signed-in user's error page knows who they are.
// RejectSessionlessGuests answers 404 itself, without a session, when there is no session cookie or the path
// belongs to capture/SDK; only a visitor presenting a session cookie gets here.
Route::fallback(fn () => abort(404));
