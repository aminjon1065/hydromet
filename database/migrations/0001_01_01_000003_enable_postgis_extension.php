<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostGIS is required by later phases for alert polygons and administrative
 * boundaries (docs/02-architecture.md, section 6). Enabling the extension in
 * the baseline keeps every PostgreSQL environment spatially ready before the
 * first geometry column is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        // The extension is intentionally left in place. Dropping it would
        // invalidate any remaining geometry column and is not needed to roll
        // back application tables.
    }
};
