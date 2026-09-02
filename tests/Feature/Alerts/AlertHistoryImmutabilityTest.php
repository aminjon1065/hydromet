<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Fixtures\FixtureAlertProvider;
use App\Domain\Integrations\Fixtures\FixtureAlertScenario;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The warning history is append-only, and this proves it rather than trusting
 * the documentation that says so.
 *
 * Two boundaries are covered on purpose. The Eloquent guard catches the mistake
 * a developer actually makes — assigning to a model and saving it — with a
 * message that names the rule. The database triggers catch everything that
 * never loads a model: a mass update, a raw statement, a `TRUNCATE`. Neither
 * substitutes for the other, so both are asserted.
 *
 * The one write the rules permit is the supersession stamp, and the importer
 * has to keep working through it; that is asserted last.
 */
class AlertHistoryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    // --- Deletion --------------------------------------------------------

    #[Test]
    public function a_stored_message_cannot_be_deleted_through_the_model(): void
    {
        $message = AlertMessage::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('never deleted');

        $message->delete();
    }

    #[Test]
    public function a_stored_message_cannot_be_deleted_by_a_statement_that_loads_no_model(): void
    {
        $message = AlertMessage::factory()->create();

        $this->assertRefused(
            static fn () => DB::table('alert_messages')->where('id', $message->id)->delete(),
            'The database accepted a delete on an append-only table.',
        );

        $this->assertDatabaseHas('alert_messages', ['id' => $message->id]);
    }

    #[Test]
    public function an_area_cannot_be_deleted_or_changed(): void
    {
        $message = AlertMessage::factory()->create();
        $area = AlertArea::factory()->create(['alert_message_id' => $message->id]);

        $this->assertRefused(
            static fn () => DB::table('alert_areas')->where('id', $area->id)->delete(),
            'The database accepted a delete on an append-only table.',
        );

        $this->assertRefused(
            static fn () => DB::table('alert_areas')
                ->where('id', $area->id)
                ->update(['description_en' => 'Rewritten']),
            'The database accepted an update on an immutable table.',
        );

        $stored = AlertArea::query()->whereKey($area->id)->sole();

        $this->assertSame($area->description_en, $stored->description_en);
    }

    #[Test]
    public function an_area_cannot_be_changed_through_the_model(): void
    {
        $message = AlertMessage::factory()->create();
        $area = AlertArea::factory()->create(['alert_message_id' => $message->id]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');

        $area->update(['description_en' => 'Rewritten']);
    }

    #[Test]
    public function an_area_cannot_be_deleted_through_the_model(): void
    {
        $message = AlertMessage::factory()->create();
        $area = AlertArea::factory()->create(['alert_message_id' => $message->id]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('never deleted');

        $area->delete();
    }

    // --- Content ---------------------------------------------------------

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function immutableBusinessFields(): array
    {
        return [
            'headline' => ['headline_en', 'Rewritten headline'],
            'description' => ['description_ru', 'Переписанное описание'],
            'severity' => ['severity', 'Extreme'],
            'event code' => ['event_code', 'REWRITTEN_EVENT'],
            'message type' => ['message_type', 'Cancel'],
            'scope' => ['scope', 'Restricted'],
            'status' => ['status', 'Test'],
            'sender' => ['sender', 'someone-else'],
            'expiry' => ['expires_at', '2031-01-01 00:00:00.000000'],
            'identifier' => ['identifier', 'a-different-identifier'],
            'source' => ['source', 'another-feed'],
        ];
    }

    #[Test]
    #[DataProvider('immutableBusinessFields')]
    public function a_business_field_cannot_be_rewritten_through_the_model(string $column, mixed $value): void
    {
        $message = AlertMessage::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');

        $message->update([$column => $value]);
    }

    #[Test]
    #[DataProvider('immutableBusinessFields')]
    public function a_business_field_cannot_be_rewritten_by_a_statement_that_loads_no_model(
        string $column,
        mixed $value,
    ): void {
        $message = AlertMessage::factory()->create();
        $before = DB::table('alert_messages')->where('id', $message->id)->first();

        $this->assertRefused(
            static fn () => DB::table('alert_messages')
                ->where('id', $message->id)
                ->update([$column => $value]),
            "The database accepted a rewrite of [{$column}] on an append-only table.",
        );

        $this->assertEquals($before, DB::table('alert_messages')->where('id', $message->id)->first());
    }

    // --- Supersession ----------------------------------------------------

    #[Test]
    public function supersession_may_be_stamped_once(): void
    {
        $original = AlertMessage::factory()->create(['identifier' => 'TJ-1']);
        $replacement = AlertMessage::factory()->update('TJ-1')->create();
        $moment = Carbon::parse('2026-02-01T00:00:00Z');

        $original->update([
            'superseded_by_id' => $replacement->id,
            'superseded_at' => $moment,
        ]);

        $this->assertTrue($original->fresh()?->isSuperseded());
    }

    // The transition model, asserted on whichever driver the suite is running
    // on. None of these may be skipped: the SQLite suite is only evidence about
    // production if it refuses exactly what PostgreSQL refuses, and half a
    // stamp used to pass here while PostgreSQL rejected it.

    #[Test]
    public function a_successor_without_its_timestamp_is_refused_by_the_model(): void
    {
        $original = AlertMessage::factory()->create(['identifier' => 'TJ-1']);
        $replacement = AlertMessage::factory()->update('TJ-1')->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('together or not at all');

        $original->update(['superseded_by_id' => $replacement->id]);
    }

    #[Test]
    public function a_timestamp_without_its_successor_is_refused_by_the_model(): void
    {
        $original = AlertMessage::factory()->create(['identifier' => 'TJ-1']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('together or not at all');

        $original->update(['superseded_at' => Carbon::parse('2026-02-01T00:00:00Z')]);
    }

    /**
     * Half a stamp says a warning was withdrawn at no particular time, or at a
     * time by nobody. Neither is a state the history may hold, and the database
     * has to refuse it even when no model is involved.
     *
     * @return array<string, array{bool, bool}>
     */
    public static function halfStamps(): array
    {
        return [
            'successor without timestamp' => [true, false],
            'timestamp without successor' => [false, true],
        ];
    }

    #[Test]
    #[DataProvider('halfStamps')]
    public function half_a_supersession_is_refused_by_the_database(bool $successor, bool $timestamp): void
    {
        $original = AlertMessage::factory()->create(['identifier' => 'TJ-1']);
        $replacement = AlertMessage::factory()->update('TJ-1')->create();

        $this->assertRefused(
            static fn () => DB::table('alert_messages')->where('id', $original->id)->update([
                'superseded_by_id' => $successor ? $replacement->id : null,
                'superseded_at' => $timestamp ? '2026-02-01 00:00:00.000000' : null,
            ]),
            'The database accepted half a supersession stamp.',
        );

        $stored = $original->fresh();

        $this->assertNull($stored?->superseded_by_id);
        $this->assertNull($stored?->superseded_at);
    }

    #[Test]
    #[DataProvider('halfStamps')]
    public function a_row_cannot_be_inserted_with_half_a_supersession(bool $successor, bool $timestamp): void
    {
        $replacement = AlertMessage::factory()->create(['identifier' => 'TJ-1']);

        $this->assertRefused(
            static fn () => AlertMessage::factory()->create([
                'identifier' => 'TJ-2',
                'superseded_by_id' => $successor ? $replacement->id : null,
                'superseded_at' => $timestamp ? Carbon::parse('2026-02-01T00:00:00Z') : null,
            ]),
            'The database accepted a row inserted with half a supersession stamp.',
        );

        $this->assertSame(1, AlertMessage::query()->count());
    }

    #[Test]
    public function a_message_cannot_supersede_itself_through_the_model(): void
    {
        $message = AlertMessage::factory()->create(['identifier' => 'TJ-1']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot supersede itself');

        $message->update([
            'superseded_by_id' => $message->id,
            'superseded_at' => Carbon::parse('2026-02-01T00:00:00Z'),
        ]);
    }

    #[Test]
    public function a_message_cannot_supersede_itself_in_the_database(): void
    {
        $message = AlertMessage::factory()->create(['identifier' => 'TJ-1']);

        $this->assertRefused(
            static fn () => DB::table('alert_messages')->where('id', $message->id)->update([
                'superseded_by_id' => $message->id,
                'superseded_at' => '2026-02-01 00:00:00.000000',
            ]),
            'The database accepted a message superseding itself.',
        );

        $this->assertNull($message->fresh()?->superseded_by_id);
    }

    #[Test]
    public function an_existing_supersession_cannot_be_reassigned_through_the_model(): void
    {
        [$original, $replacement] = $this->supersededPair();
        $other = AlertMessage::factory()->update('TJ-1')->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('stamped once');

        $original->update(['superseded_by_id' => $other->id, 'superseded_at' => $original->superseded_at]);
        unset($replacement);
    }

    #[Test]
    public function an_existing_supersession_cannot_be_retimed_through_the_model(): void
    {
        [$original] = $this->supersededPair();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('stamped once');

        $original->update(['superseded_at' => Carbon::parse('2026-03-01T00:00:00Z')]);
    }

    #[Test]
    public function an_existing_supersession_cannot_be_retimed_in_the_database(): void
    {
        [$original, $replacement] = $this->supersededPair();

        $this->assertRefused(
            static fn () => DB::table('alert_messages')->where('id', $original->id)->update([
                'superseded_at' => '2026-03-01 00:00:00.000000',
            ]),
            'The database accepted a retimed supersession stamp.',
        );

        $this->assertSame($replacement->id, $original->fresh()?->superseded_by_id);
        $this->assertTrue(
            $original->fresh()?->superseded_at?->equalTo(Carbon::parse('2026-02-01T00:00:00Z')) ?? false,
        );
    }

    #[Test]
    public function an_existing_supersession_cannot_be_half_cleared_in_the_database(): void
    {
        [$original, $replacement] = $this->supersededPair();

        $this->assertRefused(
            static fn () => DB::table('alert_messages')->where('id', $original->id)->update([
                'superseded_at' => null,
            ]),
            'The database accepted the removal of half a supersession stamp.',
        );

        $stored = $original->fresh();

        $this->assertNotNull($stored);
        $this->assertSame($replacement->id, $stored->superseded_by_id);
        $this->assertNotNull($stored->superseded_at);
    }

    #[Test]
    public function an_existing_supersession_cannot_be_cleared_through_the_model(): void
    {
        [$original] = $this->supersededPair();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('stamped once');

        $original->update(['superseded_by_id' => null, 'superseded_at' => null]);
    }

    /**
     * The technical timestamp may ride along with the one permitted write, and
     * must not become a way around it.
     */
    #[Test]
    public function bumping_only_the_technical_timestamp_changes_no_supersession(): void
    {
        $message = AlertMessage::factory()->create(['identifier' => 'TJ-1']);

        DB::table('alert_messages')
            ->where('id', $message->id)
            ->update(['updated_at' => '2026-05-01 00:00:00.000000']);

        $stored = $message->fresh();

        $this->assertNull($stored?->superseded_by_id);
        $this->assertNull($stored?->superseded_at);
    }

    #[Test]
    public function an_existing_supersession_cannot_be_cleared_by_a_statement_that_loads_no_model(): void
    {
        [$original, $replacement] = $this->supersededPair();

        $this->assertRefused(
            static fn () => DB::table('alert_messages')
                ->where('id', $original->id)
                ->update(['superseded_by_id' => null, 'superseded_at' => null]),
            'The database accepted the removal of a supersession stamp.',
        );

        $this->assertSame($replacement->id, $original->fresh()?->superseded_by_id);
    }

    #[Test]
    public function an_existing_supersession_cannot_be_reassigned_by_a_statement_that_loads_no_model(): void
    {
        [$original, $replacement] = $this->supersededPair();
        $other = AlertMessage::factory()->update('TJ-1')->create();

        $this->assertRefused(
            static fn () => DB::table('alert_messages')
                ->where('id', $original->id)
                ->update(['superseded_by_id' => $other->id]),
            'The database accepted the reassignment of a supersession stamp.',
        );

        $this->assertSame($replacement->id, $original->fresh()?->superseded_by_id);
    }

    /**
     * The guards must not stand in the way of the one write the importer needs.
     */
    #[Test]
    public function the_importer_still_supersedes_through_the_guards(): void
    {
        $importer = app(AlertImporter::class);

        $importer->import(new FixtureAlertProvider(FixtureAlertScenario::Baseline));
        $result = $importer->import(new FixtureAlertProvider(FixtureAlertScenario::Lifecycle));

        $this->assertGreaterThan(0, $result->superseded);

        $stamped = AlertMessage::query()->whereNotNull('superseded_at')->get();

        $this->assertGreaterThan(0, $stamped->count());

        foreach ($stamped as $message) {
            // Both halves, every time: the guards would have refused anything
            // else, so this also proves the importer is not being let through
            // by an accident of ordering.
            $this->assertNotNull($message->superseded_by_id);
            $this->assertNotNull($message->superseded_at);
            $this->assertNotSame($message->id, $message->superseded_by_id);
        }

        // And no half-stamped row exists anywhere after a real import.
        $this->assertSame(
            0,
            AlertMessage::query()
                ->where(static fn ($query) => $query
                    ->where(static fn ($half) => $half->whereNotNull('superseded_by_id')->whereNull('superseded_at'))
                    ->orWhere(static fn ($half) => $half->whereNull('superseded_by_id')->whereNotNull('superseded_at')))
                ->count(),
        );
    }

    // --- Truncate --------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function appendOnlyTables(): array
    {
        return [
            'messages' => ['alert_messages'],
            'areas' => ['alert_areas'],
        ];
    }

    /**
     * Row triggers never fire for `TRUNCATE`, so the statement that empties a
     * table fastest needs its own statement-level guard.
     *
     * SQLite has no `TRUNCATE` at all, and its truncate optimisation for an
     * unqualified `DELETE` is disabled on a table carrying row triggers — so
     * there the equivalent assertion is that the unqualified delete is refused.
     * Both drivers are checked, neither is skipped.
     */
    #[Test]
    #[DataProvider('appendOnlyTables')]
    public function an_append_only_table_cannot_be_emptied_wholesale(string $table): void
    {
        $message = AlertMessage::factory()->create();
        AlertArea::factory()->create(['alert_message_id' => $message->id]);

        $postgres = DB::connection()->getDriverName() === 'pgsql';

        $this->assertRefused(
            static fn () => $postgres
                ? DB::statement('TRUNCATE TABLE '.$table.' CASCADE')
                : DB::table($table)->delete(),
            "The database emptied {$table}, which is append-only.",
        );

        $this->assertGreaterThan(0, DB::table($table)->count());
    }

    /**
     * The SQLite guard names its columns one by one, because SQLite cannot
     * compare a whole row the way PostgreSQL's `to_jsonb` does. Such a list is
     * a guarantee only while it matches the table, so this reads the trigger
     * SQLite actually holds and checks it against the table's real columns: a
     * column added later without a thought for immutability fails here instead
     * of quietly becoming editable.
     */
    #[Test]
    public function the_update_guard_covers_every_column_of_the_table(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // PostgreSQL compares the whole row with `to_jsonb`, so there is no
            // list to fall behind. What has to be true is that the comparison
            // exempts exactly the three permitted columns and nothing else.
            $body = DB::selectOne(
                "SELECT prosrc FROM pg_proc WHERE proname = 'hydromet_guard_alert_message_update'",
            );

            $this->assertNotNull($body);

            $source = (string) data_get($body, 'prosrc');

            $this->assertStringContainsString('to_jsonb(NEW)', $source);
            $this->assertStringContainsString('to_jsonb(OLD)', $source);

            // Exactly the three permitted columns are subtracted before the
            // rows are compared, each once per side. Anything else exempted
            // here would be a column quietly made editable.
            preg_match_all("/- '([a-z_]+)'/", $source, $matches);

            $exempted = array_count_values($matches[1]);
            ksort($exempted);

            $this->assertSame(
                ['superseded_at' => 2, 'superseded_by_id' => 2, 'updated_at' => 2],
                $exempted,
            );

            return;
        }

        $trigger = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'alert_messages_guard_update')
            ->value('sql');

        $this->assertIsString($trigger);

        // The three columns the rules permit to change, so they are the only
        // ones the guard may leave out.
        $permitted = ['superseded_by_id', 'superseded_at', 'updated_at'];
        $unguarded = [];

        foreach (Schema::getColumnListing('alert_messages') as $column) {
            if (in_array($column, $permitted, true)) {
                continue;
            }

            if (! str_contains($trigger, 'OLD."'.$column.'" IS NOT NEW."'.$column.'"')) {
                $unguarded[] = $column;
            }
        }

        $this->assertSame([], $unguarded, 'Columns of alert_messages the update guard does not check.');

        // The supersession rules live in their own triggers so each refusal can
        // say which rule it broke; the business-column guard must not also be
        // quietly deciding them.
        $this->assertStringNotContainsString('superseded_by_id', $trigger);

        $names = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'like', 'alert_messages_guard_supersession%')
            ->pluck('name')
            ->all();

        sort($names);

        $this->assertSame([
            'alert_messages_guard_supersession_final',
            'alert_messages_guard_supersession_insert',
            'alert_messages_guard_supersession_pair',
            'alert_messages_guard_supersession_self',
        ], $names);
    }

    /**
     * Assert a statement is refused by the database, without poisoning the test.
     *
     * PostgreSQL aborts the whole transaction on a failed statement, and
     * `RefreshDatabase` runs each test inside one, so a bare try/catch would
     * leave every later query in the test failing with "current transaction is
     * aborted". Running the statement in a nested transaction turns it into a
     * savepoint, which rolls back on its own and leaves the outer transaction
     * usable.
     *
     * @param  Closure(): mixed  $statement
     */
    private function assertRefused(Closure $statement, string $failure): void
    {
        try {
            DB::transaction(static function () use ($statement): void {
                $statement();
            });
        } catch (QueryException) {
            // The driver's wording differs between engines; what this asserts
            // is that the statement did not take effect.
            return;
        }

        $this->fail($failure);
    }

    /**
     * @return array{AlertMessage, AlertMessage}
     */
    private function supersededPair(): array
    {
        $original = AlertMessage::factory()->create(['identifier' => 'TJ-1']);
        $replacement = AlertMessage::factory()->update('TJ-1')->create();

        $original->update([
            'superseded_by_id' => $replacement->id,
            'superseded_at' => Carbon::parse('2026-02-01T00:00:00Z'),
        ]);

        return [$original->fresh() ?? $original, $replacement];
    }
}
