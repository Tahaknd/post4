<?php

use App\Http\Controllers\CaptureController;
use Illuminate\Support\Facades\Route;

foreach (['/e', '/batch', '/capture'] as $path) {
    Route::post($path, CaptureController::class);
}
