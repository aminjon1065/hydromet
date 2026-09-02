<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the synchronization journal record alert imports.
 *
 * `synchronization_runs.kind` is pinned by a CHECK constraint to the import
 * kinds that existed when the journal was created. A new capability therefore
 * needs the vocabulary widened; the constraint is replaced rather than dropped
 * so an unknown kind still cannot be stored.
 *
 * A separate migration because the journal table is already released: editing
 * the original would leave every deployed database on the old constraint while
 * the file claimed otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE synchronization_runs DROP CONSTRAINT IF EXISTS synchronization_runs_kind_check');

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_kind_check
                CHECK (kind IN ('station_registry', 'measurements', 'alerts'))
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Alert runs would violate the narrower constraint, so they are removed
        // before it is restored. They are journal entries for a capability that
        // no longer exists at this schema version.
        DB::table('synchronization_runs')->where('kind', 'alerts')->delete();

        DB::statement('ALTER TABLE synchronization_runs DROP CONSTRAINT IF EXISTS synchronization_runs_kind_check');

        DB::statement(<<<'SQL'
            ALTER TABLE synchronization_runs
                ADD CONSTRAINT synchronization_runs_kind_check
                CHECK (kind IN ('station_registry', 'measurements'))
        SQL);
    }
};
