<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Affected areas of one warning, docs/03-data-contracts.md section 7.
 *
 * A CAP message may carry several areas, each with its own description,
 * geocodes and geometry, so this is a child table rather than a JSON column on
 * the message: an area is the unit the map draws and the unit a bbox filter
 * matches.
 *
 * Geometry is stored as GeoJSON in `jsonb`, not as a PostGIS geometry column.
 * PostGIS is available, but committing to it now would mean choosing an SRID
 * and a topology model for boundary data Hydromet has not supplied
 * (docs/08-hydromet-input-checklist.md, section 3), and it would split
 * behaviour between the PostgreSQL and SQLite suites — the spatial filter would
 * be exercised on only one of them. The four `bbox_*` columns are derived from
 * the geometry at import time and give an indexable, driver-identical extent
 * filter. Promoting them to a real geometry column is an additive migration
 * once the boundary dataset and CRS are agreed.
 *
 * A geometry is optional: CAP allows an area to be identified by geocode alone.
 * When Hydromet supplies only geocodes, the portal needs their administrative
 * boundary dataset before such an area can be drawn — the row is stored either
 * way so the gap is visible rather than silently dropped.
 *
 * CHECK constraints are applied on PostgreSQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_message_id')->constrained('alert_messages')->cascadeOnDelete();

            $table->string('description_tj', 255);
            $table->string('description_ru', 255);
            $table->string('description_en', 255);

            $table->jsonb('geocodes')->default(DB::raw("'[]'"));
            $table->jsonb('geometry')->nullable();

            // Derived from `geometry` at import; null when only geocodes exist.
            $table->decimal('bbox_west', 9, 6)->nullable();
            $table->decimal('bbox_south', 9, 6)->nullable();
            $table->decimal('bbox_east', 9, 6)->nullable();
            $table->decimal('bbox_north', 9, 6)->nullable();

            $table->decimal('altitude_m', 8, 2)->nullable();
            $table->decimal('ceiling_m', 8, 2)->nullable();

            $table->timestampsTz();

            $table->index(['alert_message_id'], 'alert_areas_message_index');
            $table->index(['bbox_west', 'bbox_east'], 'alert_areas_bbox_longitude_index');
            $table->index(['bbox_south', 'bbox_north'], 'alert_areas_bbox_latitude_index');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE alert_areas
                ADD CONSTRAINT alert_areas_descriptions_present_check
                CHECK (
                    btrim(description_tj) <> ''
                    AND btrim(description_ru) <> ''
                    AND btrim(description_en) <> ''
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_areas
                ADD CONSTRAINT alert_areas_json_shapes_check
                CHECK (
                    jsonb_typeof(geocodes) = 'array'
                    AND (geometry IS NULL OR jsonb_typeof(geometry) = 'object')
                )
        SQL);

        // Only Polygon and MultiPolygon are drawn. A point or line warning
        // would need a rendering rule nobody has agreed, so it is refused
        // rather than shown as something it is not.
        DB::statement(<<<'SQL'
            ALTER TABLE alert_areas
                ADD CONSTRAINT alert_areas_geometry_type_check
                CHECK (
                    geometry IS NULL
                    OR geometry->>'type' IN ('Polygon', 'MultiPolygon')
                )
        SQL);

        // The bounding box exists exactly when the geometry does, and stays
        // inside WGS84 with west/south not past east/north.
        DB::statement(<<<'SQL'
            ALTER TABLE alert_areas
                ADD CONSTRAINT alert_areas_bbox_pairing_check
                CHECK (
                    (geometry IS NULL AND bbox_west IS NULL AND bbox_south IS NULL
                        AND bbox_east IS NULL AND bbox_north IS NULL)
                    OR (geometry IS NOT NULL AND bbox_west IS NOT NULL AND bbox_south IS NOT NULL
                        AND bbox_east IS NOT NULL AND bbox_north IS NOT NULL)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_areas
                ADD CONSTRAINT alert_areas_bbox_range_check
                CHECK (
                    bbox_west IS NULL
                    OR (
                        bbox_west BETWEEN -180 AND 180
                        AND bbox_east BETWEEN -180 AND 180
                        AND bbox_south BETWEEN -90 AND 90
                        AND bbox_north BETWEEN -90 AND 90
                        AND bbox_west <= bbox_east
                        AND bbox_south <= bbox_north
                    )
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_areas
                ADD CONSTRAINT alert_areas_altitude_order_check
                CHECK (altitude_m IS NULL OR ceiling_m IS NULL OR ceiling_m >= altitude_m)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_areas');
    }
};
