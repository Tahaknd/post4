<?php

use Illuminate\Support\Facades\Route;

// UI routes must not start with a prefix in App\Http\ReservedPaths (capture and posthog-js SDK
// endpoints); tests/Feature/ReservedRoutePrefixesTest.php enforces it.
Route::inertia('/', 'Home')->name('home');
