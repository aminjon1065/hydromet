<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long a source may go without a successful import before the public
 * status endpoint calls it stale.
 *
 * This is deliberately not `polling_interval_seconds`. The interval says how
 * often the portal intends to ask; the threshold says how long silence is
 * tolerable before a visitor should be told the data may be out of date. A
 * source polled every fifteen minutes can be perfectly acceptable after two
 * hours of failures, or unacceptable after twenty. Only Hydromet can say which
 * (docs/08-hydromet-input-checklist.md, section 3), so the column is nullable
 * and null is not a default in disguise: it makes the source report `unknown`,
 * never `healthy`.
 *
 * The lower bound of 60 seconds exists because a shorter threshold would make a
 * source flap between states faster than any import could plausibly finish.
 *
 * Additive: the column is nullable with no default, so existing rows keep
 * reporting `unknown` until an approved threshold is entered for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sources', function (Blueprint $table) {
            $table->unsignedInteger('stale_after_seconds')
                ->nullable()
                ->after('polling_interval_seconds');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_stale_after_check
                CHECK (stale_after_seconds IS NULL OR stale_after_seconds >= 60)
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE integration_sources
                    DROP CONSTRAINT IF EXISTS integration_sources_stale_after_check
            SQL);
        }

        Schema::table('integration_sources', function (Blueprint $table) {
            $table->dropColumn('stale_after_seconds');
        });
    }
};
