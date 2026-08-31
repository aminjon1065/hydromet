<?php

use App\Support\Canonical\RejectedRow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rows a synchronization run refused, docs/02-architecture.md section 7
 * ("quarantine invalid rows; expose counts in admin").
 *
 * The three stored fields are exactly the three an
 * {@see RejectedRow} carries, and that object is already
 * sanitized at construction: control characters removed, single line, length
 * capped. Nothing else from the offending row is kept. There is deliberately no
 * `payload` column — quarantining a raw provider row would put untrusted text,
 * and potentially credentials, into the portal's own database.
 *
 * Column widths match the sanitizer's caps so the database refuses anything
 * that did not come through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synchronization_rejected_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('synchronization_run_id')
                ->constrained('synchronization_runs')
                ->cascadeOnDelete();
            $table->string('reference', 80);
            $table->string('reason_code', 64);
            $table->string('safe_detail', 200);
            $table->timestampsTz();

            $table->index(['synchronization_run_id', 'reason_code'], 'synchronization_rejected_rows_run_reason_index');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_rejected_rows
                ADD CONSTRAINT synchronization_rejected_rows_not_blank_check
                CHECK (
                    btrim(reference) <> ''
                    AND btrim(reason_code) <> ''
                    AND btrim(safe_detail) <> ''
                )
        SQL);

        // The sanitizer collapses newlines and tabs; anything still carrying a
        // control character did not come from it.
        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_rejected_rows
                ADD CONSTRAINT synchronization_rejected_rows_single_line_check
                CHECK (
                    reference !~ '[\n\r\t]'
                    AND reason_code !~ '[\n\r\t]'
                    AND safe_detail !~ '[\n\r\t]'
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('synchronization_rejected_rows');
    }
};
