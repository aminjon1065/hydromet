<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRejectedRow;
use App\Domain\Integrations\Models\SynchronizationRun;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Schema-level guarantees for the integration journal.
 *
 * Uniqueness, casts and relations run on every driver. The CHECK-constraint
 * tests are PostgreSQL-only, because SQLite cannot add table constraints after
 * creation; on SQLite the same rules are upheld by the runner and covered by
 * SynchronizationRunnerTest.
 */
class SynchronizationSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_expected_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumns('integration_sources', [
            'code', 'type', 'base_url', 'authentication_type', 'producer', 'timezone',
            'enabled', 'polling_interval_seconds', 'timeout_seconds', 'cursor_strategy',
            'overlap_seconds', 'parameter_mapping', 'unit_mapping', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('synchronization_runs', [
            'source_id', 'kind', 'started_at', 'finished_at', 'status', 'cursor_from', 'cursor_to',
            'received_count', 'accepted_count', 'updated_count', 'rejected_count',
            'error_code', 'sanitized_error', 'response_checksum', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('synchronization_rejected_rows', [
            'synchronization_run_id', 'reference', 'reason_code', 'safe_detail', 'created_at', 'updated_at',
        ]));
    }

    #[Test]
    public function the_source_table_holds_no_credential_column(): void
    {
        // A column named like a secret is the failure this guards against:
        // credentials belong in server-side secrets, never in this table
        // (docs/03-data-contracts.md, section 8.1).
        $columns = Schema::getColumnListing('integration_sources');

        foreach ($columns as $column) {
            foreach (['password', 'secret', 'token', 'api_key', 'credential', 'private_key'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $column,
                    "Column '{$column}' looks like it could hold a credential.",
                );
            }
        }
    }

    #[Test]
    public function a_source_code_is_unique(): void
    {
        IntegrationSource::factory()->create(['code' => 'hydromet']);

        $this->expectException(UniqueConstraintViolationException::class);

        IntegrationSource::factory()->create(['code' => 'hydromet']);
    }

    #[Test]
    public function the_casts_return_the_declared_types(): void
    {
        $source = IntegrationSource::factory()->http()->create();
        $source->refresh();

        $this->assertIsBool($source->enabled);
        $this->assertIsInt($source->timeout_seconds);
        $this->assertIsInt($source->overlap_seconds);
        $this->assertSame(['PM_2_5' => 'PM25'], $source->parameter_mapping);
        $this->assertSame(['mkg/m3' => 'ug/m3'], $source->unit_mapping);

        $run = SynchronizationRun::factory()->partial()->create();
        $run->refresh();

        $this->assertSame('partial', $run->status->value);
        $this->assertSame('station_registry', $run->kind->value);
        $this->assertIsInt($run->received_count);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->durationInMilliseconds());
    }

    #[Test]
    public function mappings_are_stored_as_json_objects_on_every_driver(): void
    {
        $source = IntegrationSource::factory()->create([
            'parameter_mapping' => ['PM_2_5' => 'PM25'],
            'unit_mapping' => [],
        ]);

        $source->refresh();

        $this->assertSame(['PM_2_5' => 'PM25'], $source->parameter_mapping);
        $this->assertSame([], $source->unit_mapping);

        // An empty mapping must be a JSON object, not `[]`, or the column's
        // object CHECK would refuse every source that declares no mapping.
        // Asserted structurally: PostgreSQL's jsonb re-renders the text it
        // stores, so comparing the literal would only pass on SQLite.
        $stored = DB::table('integration_sources')->where('id', $source->id)->first();
        $this->assertNotNull($stored);

        foreach (['parameter_mapping', 'unit_mapping'] as $column) {
            $this->assertIsString($stored->{$column});
            $this->assertInstanceOf(stdClass::class, json_decode($stored->{$column}));
        }

        $this->assertEquals(
            (object) ['PM_2_5' => 'PM25'],
            json_decode($stored->parameter_mapping),
        );
        $this->assertEquals(new stdClass, json_decode($stored->unit_mapping));
    }

    #[Test]
    public function a_mapping_with_a_non_string_value_is_refused_before_it_reaches_the_database(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IntegrationSource::factory()->create(['unit_mapping' => ['ug/m3' => 1]]);
    }

    #[Test]
    public function a_corrupt_stored_mapping_is_surfaced_when_it_is_read(): void
    {
        $source = IntegrationSource::factory()->create();

        // Written past the cast, the way a bad migration or a manual edit would.
        DB::table('integration_sources')
            ->where('id', $source->id)
            ->update(['unit_mapping' => '{"ug/m3":25}']);

        $reloaded = IntegrationSource::query()->whereKey($source->id)->sole();

        $this->expectException(UnexpectedValueException::class);

        $this->assertSame([], $reloaded->unit_mapping);
    }

    #[Test]
    public function a_running_run_has_no_duration_yet(): void
    {
        $run = SynchronizationRun::factory()->running()->create();

        $this->assertNull($run->finished_at);
        $this->assertNull($run->durationInMilliseconds());
    }

    #[Test]
    public function deleting_a_run_removes_its_quarantined_rows(): void
    {
        $row = SynchronizationRejectedRow::factory()->create();

        $row->run->delete();

        $this->assertSame(0, SynchronizationRejectedRow::query()->count());
    }

    #[Test]
    public function postgresql_keeps_a_source_that_still_has_runs(): void
    {
        $this->requirePostgres();

        $run = SynchronizationRun::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('integration_sources')->where('id', $run->source_id)->delete();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedSourceValues(): array
    {
        return [
            'unknown type' => ['type'],
            'unknown authentication' => ['authentication_type'],
            'unknown cursor strategy' => ['cursor_strategy'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedSourceValues')]
    public function postgresql_rejects_an_unsupported_source_enumeration(string $column): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('integration_sources')->insert($this->rawSource([$column => 'not-a-supported-value']));
    }

    #[Test]
    public function postgresql_rejects_a_base_url_carrying_a_query_string(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('integration_sources')->insert($this->rawSource([
            'base_url' => 'https://example.test/observations?api_key=s3cr3t',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_base_url_carrying_userinfo(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('integration_sources')->insert($this->rawSource([
            'base_url' => 'https://svc:s3cr3t@example.test/observations',
        ]));
    }

    #[Test]
    public function postgresql_accepts_a_plain_base_url(): void
    {
        $this->requirePostgres();

        DB::table('integration_sources')->insert($this->rawSource([
            'base_url' => 'https://example.test/observations',
        ]));

        $this->assertSame(1, IntegrationSource::query()->count());
    }

    #[Test]
    public function postgresql_rejects_a_mapping_that_is_not_an_object(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('integration_sources')->insert($this->rawSource(['unit_mapping' => '["ug/m3"]']));
    }

    #[Test]
    public function postgresql_rejects_an_implausible_timeout(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('integration_sources')->insert($this->rawSource(['timeout_seconds' => 0]));
    }

    #[Test]
    public function postgresql_rejects_an_unknown_run_status(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun(['status' => 'cancelled']));
    }

    #[Test]
    public function postgresql_rejects_an_unknown_run_kind(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        // Not a capability the portal has an import service for. 'alerts' used
        // to serve as the example here and became a real kind, which is exactly
        // the drift this test exists to catch.
        DB::table('synchronization_runs')->insert($this->rawRun(['kind' => 'air_quality_index']));
    }

    #[Test]
    public function postgresql_accepts_every_kind_the_application_can_produce(): void
    {
        $this->requirePostgres();

        // The database vocabulary and the enum must not drift apart: a kind the
        // application can emit but the constraint refuses would fail only in
        // production, and only for that one import.
        foreach (SynchronizationKind::cases() as $kind) {
            DB::table('synchronization_runs')->insert($this->rawRun([
                'kind' => $kind->value,
                'source_id' => IntegrationSource::factory()->create()->id,
            ]));
        }

        $this->assertSame(
            count(SynchronizationKind::cases()),
            SynchronizationRun::query()->count(),
        );
    }

    #[Test]
    public function postgresql_rejects_a_running_run_that_claims_to_have_finished(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'status' => 'running',
            'finished_at' => '2026-09-02 06:00:02',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_finished_run_with_no_finish_time(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'status' => 'succeeded',
            'finished_at' => null,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_run_that_finished_before_it_started(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'started_at' => '2026-09-02 06:00:10',
            'finished_at' => '2026-09-02 06:00:00',
        ]));
    }

    #[Test]
    public function postgresql_rejects_counters_that_exceed_what_was_received(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'received_count' => 3,
            'accepted_count' => 3,
            'rejected_count' => 1,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_run_that_received_more_than_it_accounted_for(): void
    {
        $this->requirePostgres();

        // A gap means a row went missing with nothing reporting it, so the
        // totals must match exactly rather than merely not overflow.
        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'status' => 'partial',
            'received_count' => 9,
            'accepted_count' => 3,
            'rejected_count' => 1,
        ]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function counterColumns(): array
    {
        return [
            'received_count' => ['received_count'],
            'accepted_count' => ['accepted_count'],
            'updated_count' => ['updated_count'],
            'rejected_count' => ['rejected_count'],
        ];
    }

    #[Test]
    #[DataProvider('counterColumns')]
    public function postgresql_rejects_a_negative_counter(string $column): void
    {
        $this->requirePostgres();

        // PostgreSQL has no unsigned integer, so `unsignedInteger()` alone
        // would let a negative counter through.
        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'received_count' => 0,
            'accepted_count' => 0,
            'updated_count' => 0,
            'rejected_count' => 0,
            $column => -1,
        ]));
    }

    #[Test]
    public function postgresql_rejects_more_updates_than_accepted_rows(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'received_count' => 5,
            'accepted_count' => 2,
            'updated_count' => 3,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_succeeded_run_that_quarantined_rows(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'status' => 'succeeded',
            'received_count' => 4,
            'accepted_count' => 3,
            'rejected_count' => 1,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_partial_run_that_quarantined_nothing(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'status' => 'partial',
            'rejected_count' => 0,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_failed_run_with_no_error_code(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'status' => 'failed',
            'error_code' => null,
        ]));
    }

    #[Test]
    public function postgresql_rejects_an_error_on_a_run_that_did_not_fail(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'status' => 'succeeded',
            'error_code' => 'unexpected_error',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_cursor_range_that_runs_backwards(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_runs')->insert($this->rawRun([
            'cursor_from' => '2026-09-02 06:00:00',
            'cursor_to' => '2026-09-02 05:00:00',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_quarantined_row_with_a_line_break(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_rejected_rows')->insert($this->rawRejectedRow([
            'safe_detail' => "line one\nline two",
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_blank_quarantined_row(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('synchronization_rejected_rows')->insert($this->rawRejectedRow(['reference' => '   ']));
    }

    #[Test]
    public function postgresql_stores_run_timestamps_with_a_time_zone(): void
    {
        $this->requirePostgres();

        foreach (['started_at', 'finished_at', 'cursor_from', 'cursor_to'] as $column) {
            $type = DB::selectOne(<<<'SQL'
                SELECT data_type FROM information_schema.columns
                WHERE table_name = 'synchronization_runs' AND column_name = ?
            SQL, [$column]);

            $this->assertNotNull($type);
            $this->assertSame('timestamp with time zone', $type->data_type, "Column {$column}");
        }
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints and timestamptz storage are verified on PostgreSQL only.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawSource(array $overrides): array
    {
        return [
            'code' => 'constraint-check',
            'type' => 'fixture',
            'base_url' => null,
            'authentication_type' => 'none',
            'producer' => null,
            'timezone' => 'UTC',
            'enabled' => false,
            'polling_interval_seconds' => null,
            'timeout_seconds' => 30,
            'cursor_strategy' => 'none',
            'overlap_seconds' => 0,
            'parameter_mapping' => '{}',
            'unit_mapping' => '{}',
            'created_at' => '2026-09-02 06:00:00',
            'updated_at' => '2026-09-02 06:00:00',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawRun(array $overrides): array
    {
        return [
            'source_id' => IntegrationSource::factory()->create()->id,
            'kind' => 'station_registry',
            'started_at' => '2026-09-02 06:00:00',
            'finished_at' => '2026-09-02 06:00:02',
            'status' => 'succeeded',
            'cursor_from' => null,
            'cursor_to' => null,
            'received_count' => 4,
            'accepted_count' => 4,
            'updated_count' => 0,
            'rejected_count' => 0,
            'error_code' => null,
            'sanitized_error' => null,
            'response_checksum' => null,
            'created_at' => '2026-09-02 06:00:00',
            'updated_at' => '2026-09-02 06:00:02',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawRejectedRow(array $overrides): array
    {
        return [
            'synchronization_run_id' => $overrides['synchronization_run_id']
                ?? SynchronizationRun::factory()->partial()->create()->id,
            'reference' => 'fixture:row-1',
            'reason_code' => 'unknown_station',
            'safe_detail' => 'No station is registered for this identifier.',
            'created_at' => '2026-09-02 06:00:00',
            'updated_at' => '2026-09-02 06:00:00',
            ...$overrides,
        ];
    }
}
