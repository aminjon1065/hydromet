<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter catalogue, docs/03-data-contracts.md section 4.
 *
 * Units, precision and averaging periods are explicit columns: the portal must
 * never infer a unit from a parameter code. Plausibility bounds are quality
 * control aids, not legal thresholds.
 *
 * CHECK constraints are applied on PostgreSQL only. SQLite cannot add table
 * constraints after creation and is used for fast local test runs, where the
 * same rules are enforced by the import service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameters', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('kind', 32);
            $table->string('name_tj');
            $table->string('name_ru');
            $table->string('name_en');
            $table->string('canonical_unit', 32);
            $table->unsignedTinyInteger('precision');
            // ISO 8601 duration such as PT1H. Null means the source did not
            // declare a default averaging period.
            $table->string('default_averaging_period', 32)->nullable();
            $table->decimal('plausible_min', 12, 4)->nullable();
            $table->decimal('plausible_max', 12, 4)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['kind', 'active']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE parameters
                ADD CONSTRAINT parameters_kind_check
                CHECK (kind IN ('pollutant', 'meteorological', 'derived'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE parameters
                ADD CONSTRAINT parameters_code_not_blank_check
                CHECK (btrim(code) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE parameters
                ADD CONSTRAINT parameters_precision_check
                CHECK (precision BETWEEN 0 AND 6)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE parameters
                ADD CONSTRAINT parameters_plausible_range_check
                CHECK (
                    plausible_min IS NULL
                    OR plausible_max IS NULL
                    OR plausible_min <= plausible_max
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('parameters');
    }
};
