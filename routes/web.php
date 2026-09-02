<?php

use App\Http\Controllers\ContentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\SilamController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StationExportController;
use App\Http\Middleware\SilamFramePolicy;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/content/{slug}', ContentController::class)
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('content.show');

Route::get('/language/{locale}', LocaleController::class)->name('language.switch');

Route::get('/silam', SilamController::class)
    ->middleware(SilamFramePolicy::class)
    ->name('silam');

Route::get('/stations/{station}/export.csv', StationExportController::class)
    ->whereNumber('station')
    ->name('stations.export');
Route::get('/stations/{station}', StationController::class)
    ->whereNumber('station')
    ->name('stations.show');

/*
 * Readiness probe. `/up` (configured in bootstrap/app.php) stays the liveness
 * probe; this endpoint additionally verifies the database and cache store.
 */
Route::get('/health', HealthController::class)
    ->middleware('throttle:60,1')
    ->name('health');
