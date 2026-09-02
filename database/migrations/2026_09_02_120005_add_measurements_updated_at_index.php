<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the public `updated_after` refresh filter on `GET /api/v1/stations`.
 *
 * That filter asks, per station, whether any of its measurements changed since
 * the client's cursor. `measurements_series_index` starts with `station_id` but
 * continues with `parameter_id` and `observed_at`, so it cannot answer a
 * question about `updated_at`. The column order here matches the correlated
 * lookup: seek the station, then range-scan its revision times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->index(['station_id', 'updated_at'], 'measurements_station_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropIndex('measurements_station_updated_at_index');
        });
    }
};
