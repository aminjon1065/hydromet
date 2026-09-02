<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforces docs/03-data-contracts.md section 9 at the database boundary.
 *
 * `ContentItem::booted()` already refuses to publish an incomplete record, but
 * that guard only covers Eloquent. A query-builder or psql write could publish a
 * record with a missing translation, which both public readers would then serve
 * as an empty title and body.
 *
 * Blankness is measured after trimming, so whitespace cannot pass for a
 * translation. PostgreSQL expresses this as a CHECK; SQLite cannot add table
 * constraints after creation, so it receives the equivalent row triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE content_items
                    ADD CONSTRAINT content_items_publication_completeness_check
                    CHECK (
                        status <> 'published'
                        OR (
                            published_at IS NOT NULL
                            AND btrim(coalesce(title_tj, ''), E' \t\n\r') <> ''
                            AND btrim(coalesce(title_ru, ''), E' \t\n\r') <> ''
                            AND btrim(coalesce(title_en, ''), E' \t\n\r') <> ''
                            AND btrim(coalesce(body_tj, ''), E' \t\n\r') <> ''
                            AND btrim(coalesce(body_ru, ''), E' \t\n\r') <> ''
                            AND btrim(coalesce(body_en, ''), E' \t\n\r') <> ''
                        )
                    )
            SQL);

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER content_items_publication_completeness_{$suffix}
                BEFORE {$event} ON content_items
                FOR EACH ROW
                WHEN NEW.status = 'published' AND (
                    NEW.published_at IS NULL
                    OR trim(coalesce(NEW.title_tj, ''), char(9, 10, 13, 32)) = ''
                    OR trim(coalesce(NEW.title_ru, ''), char(9, 10, 13, 32)) = ''
                    OR trim(coalesce(NEW.title_en, ''), char(9, 10, 13, 32)) = ''
                    OR trim(coalesce(NEW.body_tj, ''), char(9, 10, 13, 32)) = ''
                    OR trim(coalesce(NEW.body_ru, ''), char(9, 10, 13, 32)) = ''
                    OR trim(coalesce(NEW.body_en, ''), char(9, 10, 13, 32)) = ''
                )
                BEGIN
                    SELECT RAISE(ABORT, 'published content requires a publication time and every translation');
                END
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE content_items DROP CONSTRAINT IF EXISTS content_items_publication_completeness_check'
            );

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS content_items_publication_completeness_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS content_items_publication_completeness_update');
    }
};
