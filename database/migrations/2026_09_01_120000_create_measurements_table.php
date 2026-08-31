<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical measurements, docs/03-data-contracts.md section 5.
 *
 * Identity is the natural key recommended in docs/02-architecture.md section 5:
 * `source + station_id + parameter_id + observed_at + sensor_no`. Because
 * `sensor_no` is optional and both PostgreSQL and SQLite treat NULLs in a
 * unique index as distinct from one another, a second row with no sensor number
 * would slip past that index. The key therefore uses `sensor_key`, a stored
 * generated column that collapses "no sensor" to an empty string, so the
 * constraint holds identically on both drivers while `sensor_no` keeps the
 * nullable contract shape.
 *
 * `original_value` / `original_quality` record what the source first supplied
 * and are never rewritten. `value` / `quality` carry the currently effective
 * source revision (docs/03-data-contracts.md, section 5.3).
 *
 * Timestamps in this table are `timestamptz`: an observation is an absolute
 * instant, and storing the zone makes that explicit rather than relying on
 * every reader assuming UTC. Values are still written and read as UTC.
 *
 * CHECK constraints are applied on PostgreSQL only; SQLite cannot add table
 * constraints after creation and is used for fast local runs, where the same
 * rules are enforced by the import service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurements', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('source_measurement_id', 190)->nullable();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->foreignId('parameter_id')->constrained('parameters')->restrictOnDelete();
            $table->string('sensor_no', 32)->nullable();

            $table->timestampTz('observed_at', 6);
            $table->timestampTz('received_at', 6)->nullable();

            // 10 integer digits and 6 decimals. The catalogue caps public
            // precision at 6 places, so a stored value is never coarser than
            // the portal is prepared to publish, and unit changes such as
            // mg/m3 to ug/m3 stay representable.
            $table->decimal('original_value', 16, 6)->nullable();
            $table->string('original_quality', 16);
            $table->decimal('value', 16, 6)->nullable();

            $table->string('unit', 32);
            $table->string('averaging_period', 32)->nullable();
            $table->string('quality', 16);
            // Always a JSON array, never null: "no flags" is an empty list.
            $table->jsonb('quality_flags')->default(DB::raw("'[]'"));
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_manual')->default(false);
            $table->timestampTz('source_updated_at', 6)->nullable();
            $table->timestampsTz();

            // Read-only companion column; never written by the application.
            $table->string('sensor_key', 32)->storedAs("coalesce(sensor_no, '')");

            $table->unique(
                ['source', 'station_id', 'parameter_id', 'observed_at', 'sensor_key'],
                'measurements_natural_key_unique',
            );

            // NULLs are distinct in both drivers, so this enforces uniqueness
            // exactly when the provider supplied an identifier.
            $table->unique(['source', 'source_measurement_id'], 'measurements_source_measurement_id_unique');

            $table->index(['station_id', 'parameter_id', 'observed_at'], 'measurements_series_index');
            $table->index(['source', 'observed_at'], 'measurements_source_observed_at_index');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE measurements
                ADD CONSTRAINT measurements_revision_check
                CHECK (revision >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurements
                ADD CONSTRAINT measurements_quality_check
                CHECK (quality IN ('valid', 'suspect', 'invalid', 'missing', 'corrected'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurements
                ADD CONSTRAINT measurements_original_quality_check
                CHECK (original_quality IN ('valid', 'suspect', 'invalid', 'missing', 'corrected'))
        SQL);

        // A missing reading is missing, and only a missing reading is. The rule
        // runs both ways because `null` is the contract's only way to say "no
        // reading" (docs/03-data-contracts.md, section 2): a number under
        // `missing` would publish a reading nobody took, and a null under any
        // other quality would publish an observation with no number to show.
        DB::statement(<<<'SQL'
            ALTER TABLE measurements
                ADD CONSTRAINT measurements_value_matches_quality_check
                CHECK ((value IS NULL) = (quality = 'missing'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurements
                ADD CONSTRAINT measurements_original_value_matches_quality_check
                CHECK ((original_value IS NULL) = (original_quality = 'missing'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurements
                ADD CONSTRAINT measurements_identity_not_blank_check
                CHECK (btrim(source) <> '' AND btrim(unit) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurements
                ADD CONSTRAINT measurements_quality_flags_is_array_check
                CHECK (jsonb_typeof(quality_flags) = 'array')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('measurements');
    }
};
