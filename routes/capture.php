<?php

use App\Http\Controllers\CaptureController;
use Illuminate\Support\Facades\Route;

foreach (['/e', '/batch', '/capture', '/i/v0/e'] as $path) {
    Route::post($path, CaptureController::class);
}
