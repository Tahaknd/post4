<?php

use App\Http\Controllers\CaptureController;
use App\Http\Controllers\SdkStubController;
use Illuminate\Support\Facades\Route;

foreach (['/e', '/batch', '/capture', '/i/v0/e'] as $path) {
    Route::post($path, CaptureController::class);
}

// posthog-js endpoints that are stubs until Milestone 7 (see SdkStubController): remote config and feature flags.
// `{token}` is any one segment on purpose: a malformed token gets the same 401 JSON as an unknown one (and counts
// against the same per-ip limiter) instead of falling through to the UI's 404.
Route::get('/array/{token}/config', [SdkStubController::class, 'config'])->name('sdk.config')->where('token', '[^/]+');
Route::get('/array/{token}/config.js', [SdkStubController::class, 'configScript'])->name('sdk.config-js')->where('token', '[^/]+');
Route::post('/flags', [SdkStubController::class, 'flags'])->name('sdk.flags');
Route::post('/decide', [SdkStubController::class, 'decide'])->name('sdk.decide');
