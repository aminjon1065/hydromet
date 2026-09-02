<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the warning history append-only in the database, not only in prose.
 *
 * `docs/03-data-contracts.md` section 7 and the `alert_messages` migration both
 * state that a message is never deleted and its content never rewritten,
 * because that is what lets the portal answer "what did we publish yesterday at
 * 06:00". Until this migration nothing enforced it: a message could be deleted,
 * its areas went with it through the cascade, and any business column could be
 * overwritten in place. The claim was documentation, not a guarantee.
 *
 * The rules, matching the append-only audit log:
 *
 *   - `alert_messages` rows are never deleted or truncated;
 *   - the only permitted change to a stored message is stamping supersession
 *     once, plus the technical `updated_at`;
 *   - `alert_areas` rows are never changed, deleted or truncated.
 *
 * The supersession stamp has exactly one legal transition, and both halves move
 * together:
 *
 * | From | To | Verdict |
 * | --- | --- | --- |
 * | `(null, null)` | `(null, null)` | allowed — only `updated_at` moved |
 * | `(null, null)` | `(id, timestamp)` | allowed — the one stamp, unless the id is the row's own |
 * | `(null, null)` | `(id, null)` or `(null, timestamp)` | refused — half a stamp says a warning was withdrawn at no particular time, or at a time by nobody |
 * | `(id, timestamp)` | anything different | refused — a stamp is written once |
 *
 * That model is spelled out in the trigger on both engines rather than left to
 * PostgreSQL's `CHECK`, because the `CHECK` has no SQLite counterpart: without
 * it SQLite accepted a `superseded_by_id` with a null `superseded_at`, on both
 * the insert and the update path, and accepted a message superseding itself.
 * The two test environments have to refuse the same writes or the SQLite suite
 * proves nothing about production.
 *
 * `AlertImporter` keeps working unchanged: it writes a message once, writes its
 * areas once, and afterwards only ever stamps supersession.
 *
 * Both drivers are covered, because both are real test environments. PostgreSQL
 * compares the rest of the row with `to_jsonb`, which needs no column list and
 * therefore cannot fall behind a future column. SQLite has no such operator, so
 * its trigger names the columns one by one; `AlertHistoryImmutabilityTest`
 * compares that list against the columns the table actually has, so a column
 * added later fails the suite rather than quietly becoming editable.
 */
return new class extends Migration
{
    /**
     * Every `alert_messages` column that must never change after insert.
     *
     * `superseded_by_id`, `superseded_at` and `updated_at` are deliberately
     * absent: they are the one permitted write.
     *
     * @var array<int, string>
     */
    private const IMMUTABLE_MESSAGE_COLUMNS = [
        'id',
        'source',
        'identifier',
        'sender',
        'status',
        'message_type',
        'scope',
        'event_code',
        'severity',
        'urgency',
        'certainty',
        'categories',
        'references',
        'parameters',
        'sent_at',
        'effective_at',
        'onset_at',
        'expires_at',
        'headline_tj',
        'headline_ru',
        'headline_en',
        'description_tj',
        'description_ru',
        'description_en',
        'instruction_tj',
        'instruction_ru',
        'instruction_en',
        'raw_payload',
        'imported_at',
        'created_at',
    ];

    public function up(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresGuards(),
            'sqlite' => $this->createSqliteGuards(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->dropPostgresGuards(),
            'sqlite' => $this->dropSqliteGuards(),
            default => null,
        };
    }

    private function createPostgresGuards(): void
    {
        // CREATE OR REPLACE, because `db:wipe` drops tables but never
        // functions: a repeated `migrate:fresh` would otherwise meet a
        // surviving definition, exactly as the audit guard does.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hydromet_reject_alert_message_removal()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'alert messages are never deleted';
            END;
            $$
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hydromet_reject_alert_area_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'alert areas are immutable';
            END;
            $$
        SQL);

        // Comparing the row minus the three permitted columns needs no column
        // list, so a column added later is protected without touching this.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hydromet_guard_alert_message_update()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF (to_jsonb(NEW) - 'superseded_by_id' - 'superseded_at' - 'updated_at')
                    IS DISTINCT FROM
                   (to_jsonb(OLD) - 'superseded_by_id' - 'superseded_at' - 'updated_at') THEN
                    RAISE EXCEPTION 'alert messages are immutable apart from a single supersession stamp';
                END IF;

                -- Not yet stamped: either nothing moved, or the whole stamp did.
                IF OLD.superseded_by_id IS NULL AND OLD.superseded_at IS NULL THEN
                    IF NEW.superseded_by_id IS NULL AND NEW.superseded_at IS NULL THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.superseded_by_id IS NULL OR NEW.superseded_at IS NULL THEN
                        RAISE EXCEPTION 'an alert supersession sets superseded_by_id and superseded_at together or not at all';
                    END IF;

                    IF NEW.superseded_by_id = NEW.id THEN
                        RAISE EXCEPTION 'an alert message cannot supersede itself';
                    END IF;

                    RETURN NEW;
                END IF;

                -- Already stamped: the stamp is part of the history now.
                IF NEW.superseded_by_id IS DISTINCT FROM OLD.superseded_by_id
                    OR NEW.superseded_at IS DISTINCT FROM OLD.superseded_at THEN
                    RAISE EXCEPTION 'an alert supersession is stamped once and cannot be cleared, reassigned or retimed';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_reject_delete
            BEFORE DELETE ON alert_messages
            FOR EACH ROW EXECUTE FUNCTION hydromet_reject_alert_message_removal()
        SQL);

        // Row triggers never fire for TRUNCATE, which is the statement that
        // empties a table fastest.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_reject_truncate
            BEFORE TRUNCATE ON alert_messages
            FOR EACH STATEMENT EXECUTE FUNCTION hydromet_reject_alert_message_removal()
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_guard_update
            BEFORE UPDATE ON alert_messages
            FOR EACH ROW EXECUTE FUNCTION hydromet_guard_alert_message_update()
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_areas_reject_update_or_delete
            BEFORE UPDATE OR DELETE ON alert_areas
            FOR EACH ROW EXECUTE FUNCTION hydromet_reject_alert_area_mutation()
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_areas_reject_truncate
            BEFORE TRUNCATE ON alert_areas
            FOR EACH STATEMENT EXECUTE FUNCTION hydromet_reject_alert_area_mutation()
        SQL);
    }

    private function dropPostgresGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS alert_areas_reject_truncate ON alert_areas');
        DB::unprepared('DROP TRIGGER IF EXISTS alert_areas_reject_update_or_delete ON alert_areas');
        DB::unprepared('DROP TRIGGER IF EXISTS alert_messages_guard_update ON alert_messages');
        DB::unprepared('DROP TRIGGER IF EXISTS alert_messages_reject_truncate ON alert_messages');
        DB::unprepared('DROP TRIGGER IF EXISTS alert_messages_reject_delete ON alert_messages');
        DB::unprepared('DROP FUNCTION IF EXISTS hydromet_guard_alert_message_update()');
        DB::unprepared('DROP FUNCTION IF EXISTS hydromet_reject_alert_area_mutation()');
        DB::unprepared('DROP FUNCTION IF EXISTS hydromet_reject_alert_message_removal()');
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_reject_delete
            BEFORE DELETE ON alert_messages
            BEGIN
                SELECT RAISE(ABORT, 'alert messages are never deleted');
            END
        SQL);

        // SQLite has no TRUNCATE, and its truncate optimisation for an
        // unqualified DELETE is disabled on a table carrying row triggers, so
        // the delete guard above is the whole story here.
        //
        // The condition is written out rather than generated, so the statement
        // reaching the driver is exactly what is reviewed here. Keeping it in
        // step with the table is not left to care: `IMMUTABLE_MESSAGE_COLUMNS`
        // is the same list, and a test compares both against the columns the
        // table actually has, so a column added later fails the suite instead
        // of quietly becoming editable.
        //
        // `IS NOT` rather than `<>`, because a column going from NULL to a
        // value — or back — has to count as a change; `<>` would evaluate to
        // NULL and let it through. Identifiers are quoted because `references`
        // is a keyword in both engines.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_guard_update
            BEFORE UPDATE ON alert_messages
            FOR EACH ROW
            WHEN OLD."id" IS NOT NEW."id"
                OR OLD."source" IS NOT NEW."source"
                OR OLD."identifier" IS NOT NEW."identifier"
                OR OLD."sender" IS NOT NEW."sender"
                OR OLD."status" IS NOT NEW."status"
                OR OLD."message_type" IS NOT NEW."message_type"
                OR OLD."scope" IS NOT NEW."scope"
                OR OLD."event_code" IS NOT NEW."event_code"
                OR OLD."severity" IS NOT NEW."severity"
                OR OLD."urgency" IS NOT NEW."urgency"
                OR OLD."certainty" IS NOT NEW."certainty"
                OR OLD."categories" IS NOT NEW."categories"
                OR OLD."references" IS NOT NEW."references"
                OR OLD."parameters" IS NOT NEW."parameters"
                OR OLD."sent_at" IS NOT NEW."sent_at"
                OR OLD."effective_at" IS NOT NEW."effective_at"
                OR OLD."onset_at" IS NOT NEW."onset_at"
                OR OLD."expires_at" IS NOT NEW."expires_at"
                OR OLD."headline_tj" IS NOT NEW."headline_tj"
                OR OLD."headline_ru" IS NOT NEW."headline_ru"
                OR OLD."headline_en" IS NOT NEW."headline_en"
                OR OLD."description_tj" IS NOT NEW."description_tj"
                OR OLD."description_ru" IS NOT NEW."description_ru"
                OR OLD."description_en" IS NOT NEW."description_en"
                OR OLD."instruction_tj" IS NOT NEW."instruction_tj"
                OR OLD."instruction_ru" IS NOT NEW."instruction_ru"
                OR OLD."instruction_en" IS NOT NEW."instruction_en"
                OR OLD."raw_payload" IS NOT NEW."raw_payload"
                OR OLD."imported_at" IS NOT NEW."imported_at"
                OR OLD."created_at" IS NOT NEW."created_at"
            BEGIN
                SELECT RAISE(ABORT, 'alert messages are immutable apart from a single supersession stamp');
            END
        SQL);

        // The supersession transition, in three triggers so each refusal says
        // which rule it broke. Together they are the SQLite counterpart of the
        // branches in `hydromet_guard_alert_message_update()`.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_guard_supersession_pair
            BEFORE UPDATE ON alert_messages
            FOR EACH ROW
            WHEN OLD."superseded_by_id" IS NULL
                AND OLD."superseded_at" IS NULL
                AND ((NEW."superseded_by_id" IS NOT NULL AND NEW."superseded_at" IS NULL)
                    OR (NEW."superseded_by_id" IS NULL AND NEW."superseded_at" IS NOT NULL))
            BEGIN
                SELECT RAISE(ABORT, 'an alert supersession sets superseded_by_id and superseded_at together or not at all');
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_guard_supersession_self
            BEFORE UPDATE ON alert_messages
            FOR EACH ROW
            WHEN NEW."superseded_by_id" IS NOT NULL
                AND NEW."superseded_by_id" = NEW."id"
            BEGIN
                SELECT RAISE(ABORT, 'an alert message cannot supersede itself');
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_guard_supersession_final
            BEFORE UPDATE ON alert_messages
            FOR EACH ROW
            WHEN (OLD."superseded_by_id" IS NOT NULL OR OLD."superseded_at" IS NOT NULL)
                AND (NEW."superseded_by_id" IS NOT OLD."superseded_by_id"
                    OR NEW."superseded_at" IS NOT OLD."superseded_at")
            BEGIN
                SELECT RAISE(ABORT, 'an alert supersession is stamped once and cannot be cleared, reassigned or retimed');
            END
        SQL);

        // PostgreSQL refuses half a stamp at insert time through
        // `alert_messages_supersession_check`; SQLite cannot add a table
        // constraint after creation, so it gets the same rule as a trigger.
        //
        // The self-supersession clause only fires for an insert that supplies
        // its own key: SQLite assigns the rowid after BEFORE INSERT triggers
        // run, so `NEW."id"` is null for an ordinary insert and the comparison
        // is simply false. Nothing in the portal inserts an explicit key, and
        // the update path — the one the importer uses — is covered above.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_messages_guard_supersession_insert
            BEFORE INSERT ON alert_messages
            FOR EACH ROW
            WHEN (NEW."superseded_by_id" IS NOT NULL AND NEW."superseded_at" IS NULL)
                OR (NEW."superseded_by_id" IS NULL AND NEW."superseded_at" IS NOT NULL)
                OR (NEW."superseded_by_id" IS NOT NULL AND NEW."superseded_by_id" = NEW."id")
            BEGIN
                SELECT RAISE(ABORT, 'an alert supersession sets superseded_by_id and superseded_at together or not at all');
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_areas_reject_update
            BEFORE UPDATE ON alert_areas
            BEGIN
                SELECT RAISE(ABORT, 'alert areas are immutable');
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alert_areas_reject_delete
            BEFORE DELETE ON alert_areas
            BEGIN
                SELECT RAISE(ABORT, 'alert areas are immutable');
            END
        SQL);
    }

    private function dropSqliteGuards(): void
    {
        foreach ([
            'alert_areas_reject_delete',
            'alert_areas_reject_update',
            'alert_messages_guard_supersession_insert',
            'alert_messages_guard_supersession_final',
            'alert_messages_guard_supersession_self',
            'alert_messages_guard_supersession_pair',
            'alert_messages_guard_update',
            'alert_messages_reject_delete',
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }

    /**
     * The columns the SQLite trigger has to name, for the test that checks it
     * against the table as it actually exists.
     *
     * @return array<int, string>
     */
    public static function immutableMessageColumns(): array
    {
        return self::IMMUTABLE_MESSAGE_COLUMNS;
    }
};
