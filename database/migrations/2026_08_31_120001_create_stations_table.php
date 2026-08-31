<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Station registry, docs/03-data-contracts.md section 3.
 *
 * Identity is `source` + `external_id`: the provider key plus the immutable ID
 * in the provider's system. `code` is the human-readable station code and is
 * unique within a source, never used as the import key.
 *
 * Coordinates are stored as decimals rather than floats so a re-import of an
 * unchanged registry produces no spurious update, and so that the six stored
 * decimal places (about 0.11 m at the equator) are exact.
 *
 * CHECK constraints are applied on PostgreSQL only; see the parameters
 * migration for the reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('external_id', 128);
            $table->string('code', 64);
            $table->string('name_tj');
            $table->string('name_ru');
            $table->string('name_en');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->decimal('elevation_m', 8, 2)->nullable();
            $table->string('region_code', 64);
            $table->string('district_code', 64)->nullable();
            // Explicit per station. The default only covers rows created
            // outside the import path; the canonical contract requires the
            // provider to state the timezone.
            $table->string('timezone', 64)->default('Asia/Dushanbe');
            $table->string('status', 32);
            $table->string('station_type', 32);
            $table->string('owner')->nullable();
            $table->date('installed_at')->nullable();
            // Provider record revision time, stored in UTC. Distinct from the
            // portal's own updated_at.
            $table->timestamp('source_updated_at');
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->unique(['source', 'code']);
            $table->index('source');
            $table->index('status');
            $table->index('station_type');
            $table->index('region_code');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE stations
                ADD CONSTRAINT stations_latitude_range_check
                CHECK (latitude BETWEEN -90 AND 90)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE stations
                ADD CONSTRAINT stations_longitude_range_check
                CHECK (longitude BETWEEN -180 AND 180)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE stations
                ADD CONSTRAINT stations_status_check
                CHECK (status IN ('active', 'maintenance', 'offline', 'decommissioned'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE stations
                ADD CONSTRAINT stations_station_type_check
                CHECK (station_type IN ('air_quality', 'meteorological', 'combined'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE stations
                ADD CONSTRAINT stations_identity_not_blank_check
                CHECK (
                    btrim(source) <> ''
                    AND btrim(external_id) <> ''
                    AND btrim(code) <> ''
                    AND btrim(timezone) <> ''
                    AND btrim(region_code) <> ''
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
