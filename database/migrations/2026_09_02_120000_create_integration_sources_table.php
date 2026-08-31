<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integration source configuration, docs/03-data-contracts.md section 8.1.
 *
 * This table deliberately has no column that could hold a secret. It records
 * *how* a source authenticates (`authentication_type`), never the credential
 * itself: keys, passwords and tokens live only in server-side secrets
 * (docs/02-architecture.md, section 9). `base_url` is additionally checked to
 * carry no query string and no `user:pass@` userinfo, because those are the two
 * places a credential most often hides inside a URL.
 *
 * `parameter_mapping` and `unit_mapping` are provider-to-canonical lookup
 * tables. They are objects, not arrays, and default to empty: a source with no
 * mapping declares none rather than being guessed at.
 *
 * CHECK constraints are applied on PostgreSQL only; SQLite cannot add table
 * constraints after creation and is used for fast local runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('type', 32);
            $table->string('base_url', 255)->nullable();
            $table->string('authentication_type', 32)->default('none');
            $table->string('producer', 64)->nullable();
            $table->string('timezone', 64)->default('UTC');
            // Off until an operator turns it on. Nothing schedules a source in
            // this phase; the flag is the switch the scheduler phase will read.
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('polling_interval_seconds')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->string('cursor_strategy', 32)->default('none');
            $table->unsignedInteger('overlap_seconds')->default(0);
            $table->jsonb('parameter_mapping')->default(DB::raw("'{}'"));
            $table->jsonb('unit_mapping')->default(DB::raw("'{}'"));
            $table->timestampsTz();

            $table->index('enabled');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_type_check
                CHECK (type IN ('fixture', 'http_json', 'smartmet_timeseries', 'cap_wfs', 'file'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_authentication_type_check
                CHECK (authentication_type IN ('none', 'api_key', 'basic', 'bearer', 'ip_allowlist'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_cursor_strategy_check
                CHECK (cursor_strategy IN ('none', 'observed_at', 'updated_at'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_code_not_blank_check
                CHECK (btrim(code) <> '' AND btrim(timezone) <> '')
        SQL);

        // A query string or userinfo in a stored base URL is the classic way an
        // API key ends up in the database and in logs.
        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_base_url_carries_no_credentials_check
                CHECK (
                    base_url IS NULL
                    OR (position('?' in base_url) = 0 AND position('@' in base_url) = 0)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_timeout_check
                CHECK (timeout_seconds BETWEEN 1 AND 600)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_polling_interval_check
                CHECK (polling_interval_seconds IS NULL OR polling_interval_seconds >= 60)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_sources
                ADD CONSTRAINT integration_sources_mappings_are_objects_check
                CHECK (
                    jsonb_typeof(parameter_mapping) = 'object'
                    AND jsonb_typeof(unit_mapping) = 'object'
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sources');
    }
};
