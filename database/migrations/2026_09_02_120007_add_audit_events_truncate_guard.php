<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes the last write path into the audit log on PostgreSQL.
 *
 * `audit_events_reject_update_or_delete` is a row trigger, and row triggers
 * never fire for TRUNCATE, so the statement that empties a table fastest was
 * also the one the immutability guarantee did not cover.
 *
 * SQLite needs nothing: it has no TRUNCATE, and its truncate optimisation for
 * an unqualified DELETE is disabled on a table that carries row triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_reject_truncate
            BEFORE TRUNCATE ON audit_events
            FOR EACH STATEMENT EXECUTE FUNCTION hydromet_reject_audit_event_mutation()
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_truncate ON audit_events');
    }
};
