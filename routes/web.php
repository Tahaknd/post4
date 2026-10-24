<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\EnsureRegistrationOpen;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware\EncryptHistory;

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
// served with encrypted history so the back button after sign-out shows nothing readable.
Route::middleware(['auth', 'auth.session', EncryptHistory::class])->group(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});

// Unknown paths answer 404 inside the web group, so a signed-in user's error page knows who they are.
// RejectSessionlessGuests answers 404 itself, without a session, when there is no session cookie or the path
// belongs to capture/SDK; only a visitor presenting a session cookie gets here.
Route::fallback(fn () => abort(404));
