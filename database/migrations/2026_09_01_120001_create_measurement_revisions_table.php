<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source revision history, docs/03-data-contracts.md section 5.3.
 *
 * One row per applied change, holding the before and after value and quality so
 * a corrected observation can be explained without reconstructing it from
 * import logs. The first import of a measurement writes no row here: there was
 * nothing before it, and a synthetic "revision 1" entry would claim a change
 * that never happened.
 *
 * `unique(measurement_id, revision)` is what makes re-applying an already
 * applied revision idempotent rather than merely unlikely.
 *
 * `change_origin` and `changed_by` are present but only ever written as
 * `source` / null in this phase. They exist so the manual correction workflow
 * can be added without altering this table, and so no reader has to guess
 * whether an untagged revision came from a provider or a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('measurement_id')->constrained('measurements')->cascadeOnDelete();
            $table->unsignedInteger('revision');

            $table->decimal('previous_value', 16, 6)->nullable();
            $table->string('previous_quality', 16);
            $table->decimal('corrected_value', 16, 6)->nullable();
            $table->string('corrected_quality', 16);

            $table->string('reason_code', 64);
            // Null when the provider gave no reason. The portal does not invent
            // one (CLAUDE.md, external-data development).
            $table->text('reason_text')->nullable();

            $table->string('change_origin', 16)->default('source');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('source_updated_at', 6)->nullable();
            $table->timestampsTz();

            $table->unique(['measurement_id', 'revision'], 'measurement_revisions_measurement_revision_unique');
            $table->index(['measurement_id', 'created_at'], 'measurement_revisions_history_index');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE measurement_revisions
                ADD CONSTRAINT measurement_revisions_revision_check
                CHECK (revision >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurement_revisions
                ADD CONSTRAINT measurement_revisions_previous_quality_check
                CHECK (previous_quality IN ('valid', 'suspect', 'invalid', 'missing', 'corrected'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurement_revisions
                ADD CONSTRAINT measurement_revisions_corrected_quality_check
                CHECK (corrected_quality IN ('valid', 'suspect', 'invalid', 'missing', 'corrected'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurement_revisions
                ADD CONSTRAINT measurement_revisions_change_origin_check
                CHECK (change_origin IN ('source', 'manual'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurement_revisions
                ADD CONSTRAINT measurement_revisions_reason_code_not_blank_check
                CHECK (btrim(reason_code) <> '')
        SQL);

        // The same two-way rule the measurements table enforces, so history
        // cannot record a state the measurements table would have refused.
        DB::statement(<<<'SQL'
            ALTER TABLE measurement_revisions
                ADD CONSTRAINT measurement_revisions_previous_value_matches_quality_check
                CHECK ((previous_value IS NULL) = (previous_quality = 'missing'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE measurement_revisions
                ADD CONSTRAINT measurement_revisions_corrected_value_matches_quality_check
                CHECK ((corrected_value IS NULL) = (corrected_quality = 'missing'))
        SQL);

        // A manual correction must name the person who made it; a source
        // revision has no user behind it.
        DB::statement(<<<'SQL'
            ALTER TABLE measurement_revisions
                ADD CONSTRAINT measurement_revisions_manual_requires_user_check
                CHECK (change_origin <> 'manual' OR changed_by IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_revisions');
    }
};
