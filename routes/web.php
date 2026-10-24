<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// UI routes must not start with a prefix in App\Http\ReservedPaths (capture and posthog-js SDK
// endpoints); tests/Feature/ReservedRoutePrefixesTest.php enforces it.
Route::get('/', fn () => Inertia::render('Home'));
