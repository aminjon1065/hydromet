<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Synchronization run journal, docs/03-data-contracts.md section 8.2.
 *
 * One row per import attempt, opened as `running` before the provider is read
 * so that a run which dies mid-flight still leaves a trace. Everything stored
 * here is safe to show an operator: counters, a stable `error_code` and a
 * `sanitized_error` sentence.
 *
 * Provider payloads, credentials, SQL and stack traces stay out — and not by
 * being written elsewhere instead. The original exception, its message and its
 * trace are never sent to the application log either; the log receives only
 * safe structured metadata (run id, source code, kind and the exception's class
 * name), because a provider failure message routinely carries a DSN with its
 * password, an `Authorization` header or a slice of the raw payload.
 *
 * `cursor_from` / `cursor_to` record the exact bounded interval handed to an
 * incremental provider. `response_checksum` remains optional until a real
 * adapter defines which sanitized response bytes are authoritative.
 *
 * CHECK constraints are applied on PostgreSQL only; SQLite cannot add table
 * constraints after creation, and the same rules are enforced by the runner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synchronization_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('integration_sources')->restrictOnDelete();
            $table->string('kind', 32);
            $table->timestampTz('started_at', 6);
            $table->timestampTz('finished_at', 6)->nullable();
            $table->string('status', 16)->default('running');
            $table->timestampTz('cursor_from', 6)->nullable();
            $table->timestampTz('cursor_to', 6)->nullable();
            $table->unsignedInteger('received_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('sanitized_error', 500)->nullable();
            $table->string('response_checksum', 64)->nullable();
            $table->timestampsTz();

            $table->index(['source_id', 'started_at'], 'synchronization_runs_source_started_index');
            $table->index(['status'], 'synchronization_runs_status_index');
            $table->index(['kind', 'started_at'], 'synchronization_runs_kind_started_index');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_status_check
                CHECK (status IN ('running', 'succeeded', 'partial', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_kind_check
                CHECK (kind IN ('station_registry', 'measurements'))
        SQL);

        // A run is finished exactly when it is no longer running, so a crashed
        // process cannot leave a row that looks both open and closed.
        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_finished_matches_status_check
                CHECK ((status = 'running') = (finished_at IS NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_finished_after_started_check
                CHECK (finished_at IS NULL OR finished_at >= started_at)
        SQL);

        // PostgreSQL has no unsigned integer, so `unsignedInteger()` produces a
        // plain `integer` and a negative counter would otherwise be storable.
        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_counts_are_not_negative_check
                CHECK (
                    received_count >= 0
                    AND accepted_count >= 0
                    AND updated_count >= 0
                    AND rejected_count >= 0
                )
        SQL);

        // Every row a provider sent was either stored or quarantined. A gap
        // would mean a row went missing with nothing reporting it, so the
        // totals must match exactly rather than merely not overflow.
        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_counts_add_up_check
                CHECK (received_count = accepted_count + rejected_count)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_updated_within_accepted_check
                CHECK (updated_count <= accepted_count)
        SQL);

        // The three closing statuses each mean something specific, so each one
        // is pinned to the evidence that justifies it.
        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_succeeded_has_no_rejections_check
                CHECK (status <> 'succeeded' OR rejected_count = 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_partial_has_rejections_check
                CHECK (status <> 'partial' OR rejected_count > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_failed_states_a_code_check
                CHECK (status <> 'failed' OR btrim(coalesce(error_code, '')) <> '')
        SQL);

        // An error code belongs to a failed run only; a green run explaining an
        // error would be a contradiction an operator cannot act on.
        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_error_only_when_failed_check
                CHECK (status = 'failed' OR (error_code IS NULL AND sanitized_error IS NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_cursor_order_check
                CHECK (cursor_from IS NULL OR cursor_to IS NULL OR cursor_to >= cursor_from)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('synchronization_runs');
    }
};
