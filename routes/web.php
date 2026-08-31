<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/language/{locale}', LocaleController::class)->name('language.switch');

/*
 * Readiness probe. `/up` (configured in bootstrap/app.php) stays the liveness
 * probe; this endpoint additionally verifies the database and cache store.
 */
Route::get('/health', HealthController::class)
    ->middleware('throttle:60,1')
    ->name('health');
