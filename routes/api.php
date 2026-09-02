<?php

use App\Http\Controllers\Api\V1\AlertIndexController;
use App\Http\Controllers\Api\V1\AlertShowController;
use App\Http\Controllers\Api\V1\ContentShowController;
use App\Http\Controllers\Api\V1\MetadataController;
use App\Http\Controllers\Api\V1\StationExportController;
use App\Http\Controllers\Api\V1\StationIndexController;
use App\Http\Controllers\Api\V1\StationSeriesController;
use App\Http\Controllers\Api\V1\StationShowController;
use App\Http\Middleware\ApiRequestId;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([ApiRequestId::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::get('/metadata', MetadataController::class)->name('api.v1.metadata');
        Route::get('/alerts', AlertIndexController::class)->name('api.v1.alerts.index');
        // The public identity of a warning is the pair (source, identifier): a
        // CAP identifier is unique within its sender, not globally, and the
        // list endpoint returns both fields so a client can build this URL.
        Route::get('/alerts/{source}/{identifier}', AlertShowController::class)
            // Both segments are provider-chosen text, so they are bounded here
            // rather than trusted. The lengths mirror the columns they query.
            ->where('source', '[A-Za-z0-9._-]{1,32}')
            // Real feeds use `@`, `:`, `.`, `_` and `-` in identifiers
            // (`urn:oid:…`, `NWS-IDP-PROD-1@2026-01-01T00:00:00Z`), so the set
            // is wide enough for them and still excludes `/`, which is what a
            // traversal attempt would need.
            ->where('identifier', '[A-Za-z0-9@._:+~-]{1,190}')
            ->name('api.v1.alerts.show');
        Route::get('/content/{slug}', ContentShowController::class)
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('api.v1.content.show');
        Route::get('/stations', StationIndexController::class)->name('api.v1.stations.index');
        Route::get('/stations/{station}', StationShowController::class)
            ->whereNumber('station')
            ->name('api.v1.stations.show');
        Route::get('/stations/{station}/series', StationSeriesController::class)
            ->whereNumber('station')
            ->name('api.v1.stations.series');
        Route::get('/stations/{station}/export.csv', StationExportController::class)
            ->whereNumber('station')
            ->name('api.v1.stations.export');
    });
