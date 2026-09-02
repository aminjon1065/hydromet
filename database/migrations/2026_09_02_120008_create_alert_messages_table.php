<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical warnings, docs/03-data-contracts.md section 7.
 *
 * One row per message received, never one row per "warning". CAP models a
 * warning as a chain: an `Alert`, then `Update`s, then possibly a `Cancel`,
 * each with its own identifier and each referencing its predecessors. Storing
 * the chain is what lets the portal answer both "what is in force now" and
 * "what did we publish at 06:00 yesterday" — deleting or overwriting a
 * superseded message would destroy the second answer
 * (docs/06-testing-and-acceptance.md, ALERT-02 and ALERT-03).
 *
 * `superseded_by_id` / `superseded_at` are the resolution result: an Update or
 * Cancel stamps the messages it references. Nothing is deleted, so history
 * survives and the active view is a query, not a mutation.
 *
 * The model is deliberately not tied to CAP XML. Hydromet has not chosen a
 * source type yet (docs/08-hydromet-input-checklist.md, section 3), so an
 * adapter for CAP Atom, WFS GeoJSON or a custom JSON feed maps into these same
 * columns. The CAP *vocabulary* is used because it is the interchange standard
 * the contract names, not because the wire format is assumed to be CAP.
 *
 * `raw_payload` is part of the contract and is created here, but nothing writes
 * it: "sanitized authoritative message" has no defined sanitization rule until
 * the source type is known, and storing an unsanitized upstream document would
 * break the untrusted-input rule in CLAUDE.md. It stays null, like
 * `synchronization_runs.response_checksum`, until that rule exists.
 *
 * CHECK constraints are applied on PostgreSQL only; SQLite cannot add table
 * constraints after creation, and the import service enforces the same rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_messages', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('identifier', 190);
            $table->string('sender', 190);

            $table->string('status', 16);
            $table->string('message_type', 16);
            $table->string('scope', 16);
            $table->string('event_code', 64);
            $table->string('severity', 16);
            $table->string('urgency', 16);
            $table->string('certainty', 16);

            // Lists and free-form provider values stay JSON: the portal does not
            // yet know Hydromet's category vocabulary or which physical
            // parameters a warning carries.
            $table->jsonb('categories')->default(DB::raw("'[]'"));
            $table->jsonb('references')->default(DB::raw("'[]'"));
            $table->jsonb('parameters')->default(DB::raw("'{}'"));

            $table->timestampTz('sent_at', 6);
            $table->timestampTz('effective_at', 6)->nullable();
            $table->timestampTz('onset_at', 6)->nullable();
            $table->timestampTz('expires_at', 6);

            // Explicit per-locale columns, matching content_items: a warning
            // must never fall back to another language without an approved
            // rule (CLAUDE.md), and separate columns make a missing
            // translation a visible NOT NULL violation rather than a silent
            // empty string.
            $table->string('headline_tj', 255);
            $table->string('headline_ru', 255);
            $table->string('headline_en', 255);
            $table->text('description_tj');
            $table->text('description_ru');
            $table->text('description_en');
            $table->text('instruction_tj')->nullable();
            $table->text('instruction_ru')->nullable();
            $table->text('instruction_en')->nullable();

            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('alert_messages')->nullOnDelete();
            $table->timestampTz('superseded_at', 6)->nullable();

            // Reserved by docs/03-data-contracts.md section 7; see the class
            // comment for why nothing fills it yet.
            $table->jsonb('raw_payload')->nullable();

            // When the portal first stored this message, as distinct from when
            // the sender sent it.
            $table->timestampTz('imported_at', 6);
            $table->timestampsTz();

            $table->unique(['source', 'identifier'], 'alert_messages_source_identifier_unique');
            $table->index(['source', 'expires_at'], 'alert_messages_source_expires_index');
            $table->index(['status', 'scope', 'expires_at'], 'alert_messages_public_window_index');
            $table->index(['event_code'], 'alert_messages_event_code_index');
            $table->index(['severity'], 'alert_messages_severity_index');
            $table->index(['superseded_at'], 'alert_messages_superseded_index');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_status_check
                CHECK (status IN ('Actual', 'Exercise', 'System', 'Test', 'Draft'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_message_type_check
                CHECK (message_type IN ('Alert', 'Update', 'Cancel', 'Ack', 'Error'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_scope_check
                CHECK (scope IN ('Public', 'Restricted', 'Private'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_severity_check
                CHECK (severity IN ('Extreme', 'Severe', 'Moderate', 'Minor', 'Unknown'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_urgency_check
                CHECK (urgency IN ('Immediate', 'Expected', 'Future', 'Past', 'Unknown'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_certainty_check
                CHECK (certainty IN ('Observed', 'Likely', 'Possible', 'Unlikely', 'Unknown'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_identity_not_blank_check
                CHECK (
                    btrim(source) <> ''
                    AND btrim(identifier) <> ''
                    AND btrim(sender) <> ''
                    AND btrim(event_code) <> ''
                )
        SQL);

        // Every required translation must actually be present. A warning shown
        // in only two of three languages is a publication failure, not a
        // degraded state to paper over with a fallback.
        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_translations_present_check
                CHECK (
                    btrim(headline_tj) <> '' AND btrim(headline_ru) <> '' AND btrim(headline_en) <> ''
                    AND btrim(description_tj) <> '' AND btrim(description_ru) <> '' AND btrim(description_en) <> ''
                )
        SQL);

        // Instruction is optional, but it is optional for the whole message:
        // supplying it in one language only would force a fallback at render
        // time, which no approved rule allows.
        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_instruction_all_or_none_check
                CHECK (
                    (instruction_tj IS NULL AND instruction_ru IS NULL AND instruction_en IS NULL)
                    OR (
                        btrim(coalesce(instruction_tj, '')) <> ''
                        AND btrim(coalesce(instruction_ru, '')) <> ''
                        AND btrim(coalesce(instruction_en, '')) <> ''
                    )
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_validity_order_check
                CHECK (
                    expires_at > sent_at
                    AND (effective_at IS NULL OR effective_at >= sent_at)
                    AND (onset_at IS NULL OR effective_at IS NULL OR onset_at >= effective_at)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_supersession_pairing_check
                CHECK ((superseded_by_id IS NULL) = (superseded_at IS NULL))
        SQL);

        // A message cannot withdraw itself.
        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_not_self_superseding_check
                CHECK (superseded_by_id IS NULL OR superseded_by_id <> id)
        SQL);

        // `references` is a reserved word in PostgreSQL, so it must be quoted
        // here. Laravel quotes identifiers for the schema builder and for
        // Eloquent, which is why this only surfaces in raw constraint SQL.
        DB::statement(<<<'SQL'
            ALTER TABLE alert_messages
                ADD CONSTRAINT alert_messages_json_shapes_check
                CHECK (
                    jsonb_typeof(categories) = 'array'
                    AND jsonb_typeof("references") = 'array'
                    AND jsonb_typeof(parameters) = 'object'
                    AND (raw_payload IS NULL OR jsonb_typeof(raw_payload) = 'object')
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_messages');
    }
};
