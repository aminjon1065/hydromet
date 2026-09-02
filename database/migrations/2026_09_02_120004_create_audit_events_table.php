<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only evidence for sensitive administrative actions.
 *
 * Database triggers reject UPDATE and DELETE even when code bypasses Eloquent.
 * Actor rows are restricted from deletion: accounts are deactivated so their
 * audit identity remains intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 96);
            $table->string('subject_type', 64);
            $table->string('subject_id', 128);
            $table->string('subject_label')->nullable();
            $table->json('changes');

            $table->index('occurred_at');
            $table->index(['action', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'occurred_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // `db:wipe` drops tables but never functions, so a repeated
            // `migrate:fresh` would meet a surviving definition. Replacing the
            // body keeps this migration re-runnable.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION hydromet_reject_audit_event_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'audit events are immutable';
                END;
                $$
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_update_or_delete
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION hydromet_reject_audit_event_mutation()
            SQL);

            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_update
                BEFORE UPDATE ON audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'audit events are immutable');
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_delete
                BEFORE DELETE ON audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'audit events are immutable');
                END
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS hydromet_reject_audit_event_mutation()');
        }
    }
};
