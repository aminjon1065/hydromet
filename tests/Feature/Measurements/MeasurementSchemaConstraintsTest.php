<?php

namespace Tests\Feature\Measurements;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Enums\RevisionOrigin;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema-level guarantees.
 *
 * Uniqueness, casts and generated-column behaviour run on every driver. The
 * CHECK-constraint tests are PostgreSQL-only, because SQLite cannot add table
 * constraints after creation; on SQLite the same rules are enforced by the
 * import service and covered by MeasurementImportTest.
 */
class MeasurementSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Parameter $parameter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->station = Station::factory()->create(['source' => 'test', 'external_id' => 'test-station-001']);
        $this->parameter = Parameter::factory()->create(['code' => 'PM25', 'canonical_unit' => 'ug/m3']);
    }

    #[Test]
    public function every_expected_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumns('measurements', [
            'source', 'source_measurement_id', 'station_id', 'parameter_id', 'sensor_no',
            'observed_at', 'received_at', 'original_value', 'original_quality', 'value',
            'unit', 'averaging_period', 'quality', 'quality_flags', 'revision',
            'is_manual', 'source_updated_at', 'created_at', 'updated_at', 'sensor_key',
        ]));

        $this->assertTrue(Schema::hasColumns('measurement_revisions', [
            'measurement_id', 'revision', 'previous_value', 'previous_quality',
            'corrected_value', 'corrected_quality', 'reason_code', 'reason_text',
            'change_origin', 'changed_by', 'source_updated_at', 'created_at', 'updated_at',
        ]));
    }

    #[Test]
    public function the_casts_return_the_declared_types(): void
    {
        $measurement = Measurement::factory()->create([
            'station_id' => $this->station->id,
            'parameter_id' => $this->parameter->id,
            'value' => 12.5,
            'original_value' => 12.5,
            'quality_flags' => ['a', 'b'],
            'observed_at' => Carbon::parse('2026-08-31T06:00:00Z'),
            'source_updated_at' => Carbon::parse('2026-08-31T06:02:00Z'),
        ]);

        $measurement->refresh();

        $this->assertInstanceOf(Carbon::class, $measurement->observed_at);
        $this->assertSame('2026-08-31 06:00:00', $measurement->observed_at->utc()->toDateTimeString());
        $this->assertInstanceOf(Carbon::class, $measurement->source_updated_at);
        $this->assertSame(MeasurementQuality::Valid, $measurement->quality);
        $this->assertSame(MeasurementQuality::Valid, $measurement->original_quality);
        $this->assertSame(['a', 'b'], $measurement->quality_flags);
        $this->assertSame('12.500000', $measurement->value);
        $this->assertSame('12.500000', $measurement->original_value);
        $this->assertIsInt($measurement->revision);
        $this->assertIsBool($measurement->is_manual);
        $this->assertFalse($measurement->is_manual);
    }

    #[Test]
    public function the_revision_casts_return_the_declared_types(): void
    {
        $revision = MeasurementRevision::factory()->create([
            'measurement_id' => $this->measurement()->id,
        ]);

        $revision->refresh();

        $this->assertSame(MeasurementQuality::Valid, $revision->previous_quality);
        $this->assertSame(MeasurementQuality::Corrected, $revision->corrected_quality);
        $this->assertSame(RevisionOrigin::Source, $revision->change_origin);
        $this->assertSame('12.500000', $revision->previous_value);
        $this->assertSame('13.750000', $revision->corrected_value);
    }

    #[Test]
    public function a_provider_measurement_id_is_unique_within_its_source(): void
    {
        $this->measurement(['source_measurement_id' => 'PROVIDER-1']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->measurement([
            'source_measurement_id' => 'PROVIDER-1',
            'observed_at' => Carbon::parse('2026-08-31T07:00:00Z'),
        ]);
    }

    #[Test]
    public function the_same_provider_measurement_id_may_be_reused_by_another_source(): void
    {
        $this->measurement(['source_measurement_id' => 'PROVIDER-1']);

        $other = Station::factory()->create(['source' => 'other', 'external_id' => 'other-station-001']);

        Measurement::factory()->create([
            'source' => 'other',
            'source_measurement_id' => 'PROVIDER-1',
            'station_id' => $other->id,
            'parameter_id' => $this->parameter->id,
        ]);

        $this->assertSame(2, Measurement::query()->count());
    }

    #[Test]
    public function many_rows_may_omit_the_provider_measurement_id(): void
    {
        $this->measurement(['source_measurement_id' => null, 'observed_at' => Carbon::parse('2026-08-31T05:00:00Z')]);
        $this->measurement(['source_measurement_id' => null, 'observed_at' => Carbon::parse('2026-08-31T06:00:00Z')]);

        $this->assertSame(2, Measurement::query()->count());
    }

    #[Test]
    public function the_natural_key_is_unique(): void
    {
        $this->measurement(['sensor_no' => '1']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->measurement(['sensor_no' => '1']);
    }

    #[Test]
    public function the_natural_key_still_holds_when_the_sensor_number_is_null(): void
    {
        // The case a plain unique index would miss: NULLs compare as distinct
        // in both PostgreSQL and SQLite, so a second sensor-less row would slip
        // through without the generated sensor_key column.
        $this->measurement(['sensor_no' => null]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->measurement(['sensor_no' => null]);
    }

    #[Test]
    public function a_null_sensor_number_does_not_collide_with_a_numbered_sensor(): void
    {
        $this->measurement(['sensor_no' => null]);
        $this->measurement(['sensor_no' => '1']);
        $this->measurement(['sensor_no' => '2']);

        $this->assertSame(3, Measurement::query()->count());
    }

    #[Test]
    public function the_generated_sensor_key_collapses_a_null_sensor_number(): void
    {
        $withSensor = $this->measurement(['sensor_no' => '1']);
        $withoutSensor = $this->measurement(['sensor_no' => null]);

        $keys = DB::table('measurements')
            ->orderBy('id')
            ->pluck('sensor_key', 'id')
            ->all();

        $this->assertSame('1', $keys[$withSensor->id]);
        $this->assertSame('', $keys[$withoutSensor->id]);
    }

    #[Test]
    public function the_same_observation_may_exist_for_two_sources(): void
    {
        $this->measurement();

        $other = Station::factory()->create(['source' => 'other', 'external_id' => 'other-station-001']);

        Measurement::factory()->create([
            'source' => 'other',
            'station_id' => $other->id,
            'parameter_id' => $this->parameter->id,
        ]);

        $this->assertSame(2, Measurement::query()->count());
    }

    #[Test]
    public function the_same_revision_cannot_be_recorded_twice_for_one_measurement(): void
    {
        $measurement = $this->measurement();

        MeasurementRevision::factory()->create(['measurement_id' => $measurement->id, 'revision' => 2]);

        $this->expectException(UniqueConstraintViolationException::class);

        MeasurementRevision::factory()->create(['measurement_id' => $measurement->id, 'revision' => 2]);
    }

    #[Test]
    public function deleting_a_measurement_removes_its_history(): void
    {
        $measurement = $this->measurement();
        MeasurementRevision::factory()->create(['measurement_id' => $measurement->id]);

        $measurement->delete();

        $this->assertSame(0, MeasurementRevision::query()->count());
    }

    #[Test]
    public function postgresql_rejects_a_revision_below_one(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement(['revision' => 0]));
    }

    #[Test]
    public function postgresql_rejects_an_unknown_quality(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement(['quality' => 'estimated']));
    }

    #[Test]
    public function postgresql_rejects_an_unknown_original_quality(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement(['original_quality' => 'estimated']));
    }

    #[Test]
    public function postgresql_rejects_a_missing_reading_that_carries_a_value(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement([
            'quality' => 'missing',
            'value' => 12.5,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_missing_original_reading_that_carries_a_value(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement([
            'original_quality' => 'missing',
            'original_value' => 12.5,
        ]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function qualitiesThatRequireAValue(): array
    {
        return [
            'valid' => ['valid'],
            'suspect' => ['suspect'],
            'invalid' => ['invalid'],
            'corrected' => ['corrected'],
        ];
    }

    #[Test]
    #[DataProvider('qualitiesThatRequireAValue')]
    public function postgresql_rejects_a_null_value_under_any_other_quality(string $quality): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement([
            'quality' => $quality,
            'value' => null,
        ]));
    }

    #[Test]
    #[DataProvider('qualitiesThatRequireAValue')]
    public function postgresql_rejects_a_null_original_value_under_any_other_quality(string $quality): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement([
            'original_quality' => $quality,
            'original_value' => null,
        ]));
    }

    #[Test]
    public function postgresql_accepts_a_null_value_reported_as_missing(): void
    {
        $this->requirePostgres();

        DB::table('measurements')->insert($this->rawMeasurement([
            'quality' => 'missing',
            'value' => null,
            'original_quality' => 'missing',
            'original_value' => null,
        ]));

        $this->assertSame(1, Measurement::query()->count());
    }

    #[Test]
    public function postgresql_rejects_a_blank_source_or_unit(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement(['unit' => '   ']));
    }

    #[Test]
    public function postgresql_rejects_quality_flags_that_are_not_a_json_array(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurements')->insert($this->rawMeasurement([
            'quality_flags' => '{"flag": "not-a-list"}',
        ]));
    }

    #[Test]
    public function postgresql_accepts_an_empty_quality_flag_list(): void
    {
        $this->requirePostgres();

        DB::table('measurements')->insert($this->rawMeasurement(['quality_flags' => '[]']));

        $this->assertSame(1, Measurement::query()->count());
    }

    #[Test]
    public function postgresql_stores_observed_at_with_a_time_zone(): void
    {
        $this->requirePostgres();

        $type = DB::selectOne(<<<'SQL'
            SELECT data_type
            FROM information_schema.columns
            WHERE table_name = 'measurements' AND column_name = 'observed_at'
        SQL);

        $this->assertNotNull($type);
        $this->assertSame('timestamp with time zone', $type->data_type);
    }

    #[Test]
    public function postgresql_reads_back_an_observation_at_the_instant_it_was_written(): void
    {
        $this->requirePostgres();

        $measurement = $this->measurement(['observed_at' => Carbon::parse('2026-08-31T06:00:00Z')]);

        $stored = DB::selectOne(
            "SELECT to_char(observed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS') AS utc FROM measurements WHERE id = ?",
            [$measurement->id],
        );

        $this->assertNotNull($stored);
        $this->assertSame('2026-08-31T06:00:00', $stored->utc);
    }

    #[Test]
    public function postgresql_rejects_an_unknown_change_origin(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurement_revisions')->insert($this->rawRevision(['change_origin' => 'robot']));
    }

    #[Test]
    public function postgresql_rejects_a_manual_revision_without_a_user(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurement_revisions')->insert($this->rawRevision([
            'change_origin' => 'manual',
            'changed_by' => null,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_blank_reason_code(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurement_revisions')->insert($this->rawRevision(['reason_code' => '  ']));
    }

    #[Test]
    public function postgresql_rejects_history_where_a_missing_reading_carries_a_value(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurement_revisions')->insert($this->rawRevision([
            'corrected_quality' => 'missing',
            'corrected_value' => 5.0,
        ]));
    }

    #[Test]
    public function postgresql_rejects_history_where_a_previous_missing_reading_carries_a_value(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurement_revisions')->insert($this->rawRevision([
            'previous_quality' => 'missing',
            'previous_value' => 5.0,
        ]));
    }

    #[Test]
    #[DataProvider('qualitiesThatRequireAValue')]
    public function postgresql_rejects_history_with_a_null_corrected_value_under_another_quality(string $quality): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurement_revisions')->insert($this->rawRevision([
            'corrected_quality' => $quality,
            'corrected_value' => null,
        ]));
    }

    #[Test]
    #[DataProvider('qualitiesThatRequireAValue')]
    public function postgresql_rejects_history_with_a_null_previous_value_under_another_quality(string $quality): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('measurement_revisions')->insert($this->rawRevision([
            'previous_quality' => $quality,
            'previous_value' => null,
        ]));
    }

    #[Test]
    public function postgresql_accepts_history_that_moves_between_missing_and_a_reading(): void
    {
        $this->requirePostgres();

        // One measurement, two revisions: rawRevision() creates a measurement
        // per call, and a second one would collide on the natural key.
        $measurementId = $this->measurement()->id;

        DB::table('measurement_revisions')->insert($this->rawRevision([
            'measurement_id' => $measurementId,
            'previous_quality' => 'missing',
            'previous_value' => null,
            'corrected_quality' => 'corrected',
            'corrected_value' => 5.0,
        ]));

        DB::table('measurement_revisions')->insert($this->rawRevision([
            'measurement_id' => $measurementId,
            'revision' => 3,
            'previous_quality' => 'corrected',
            'previous_value' => 5.0,
            'corrected_quality' => 'missing',
            'corrected_value' => null,
        ]));

        $this->assertSame(2, MeasurementRevision::query()->count());
    }

    #[Test]
    public function postgresql_stores_observed_at_with_microsecond_precision(): void
    {
        $this->requirePostgres();

        $measurement = $this->measurement([
            'observed_at' => Carbon::parse('2026-08-31T06:00:00.123456Z'),
            'received_at' => Carbon::parse('2026-08-31T06:02:00.654321Z'),
            'source_updated_at' => Carbon::parse('2026-08-31T06:02:00.111222Z'),
        ]);

        $stored = DB::selectOne(<<<'SQL'
            SELECT to_char(observed_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS observed,
                   to_char(received_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS received,
                   to_char(source_updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS source_updated
            FROM measurements WHERE id = ?
        SQL, [$measurement->id]);

        $this->assertNotNull($stored);
        $this->assertSame('2026-08-31 06:00:00.123456', $stored->observed);
        $this->assertSame('2026-08-31 06:02:00.654321', $stored->received);
        $this->assertSame('2026-08-31 06:02:00.111222', $stored->source_updated);
    }

    #[Test]
    public function postgresql_keeps_two_observations_from_the_same_second_apart(): void
    {
        $this->requirePostgres();

        $this->measurement(['observed_at' => Carbon::parse('2026-08-31T06:00:00.100000Z')]);
        $this->measurement(['observed_at' => Carbon::parse('2026-08-31T06:00:00.200000Z')]);

        $this->assertSame(2, Measurement::query()->count());

        $instants = DB::table('measurements')
            ->orderBy('observed_at')
            ->pluck('observed_at')
            ->map(fn (string $value): string => Carbon::parse($value)->utc()->format('H:i:s.u'))
            ->all();

        $this->assertSame(['06:00:00.100000', '06:00:00.200000'], $instants);
    }

    #[Test]
    public function postgresql_keeps_a_station_that_still_has_measurements(): void
    {
        $this->requirePostgres();

        $this->measurement();

        $this->expectException(QueryException::class);

        DB::table('stations')->where('id', $this->station->id)->delete();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function measurement(array $overrides = []): Measurement
    {
        return Measurement::factory()->create([
            'source' => 'test',
            'station_id' => $this->station->id,
            'parameter_id' => $this->parameter->id,
            ...$overrides,
        ]);
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
    private function rawMeasurement(array $overrides): array
    {
        return [
            'source' => 'test',
            'source_measurement_id' => null,
            'station_id' => $this->station->id,
            'parameter_id' => $this->parameter->id,
            'sensor_no' => null,
            'observed_at' => '2026-08-31 06:00:00',
            'received_at' => null,
            'original_value' => 12.5,
            'original_quality' => 'valid',
            'value' => 12.5,
            'unit' => 'ug/m3',
            'averaging_period' => 'PT1H',
            'quality' => 'valid',
            'quality_flags' => '[]',
            'revision' => 1,
            'is_manual' => false,
            'source_updated_at' => null,
            'created_at' => '2026-08-31 06:00:00',
            'updated_at' => '2026-08-31 06:00:00',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawRevision(array $overrides): array
    {
        return [
            // Only created when the caller did not supply one: every extra
            // measurement would collide on the natural key.
            'measurement_id' => $overrides['measurement_id'] ?? $this->measurement()->id,
            'revision' => 2,
            'previous_value' => 12.5,
            'previous_quality' => 'valid',
            'corrected_value' => 13.5,
            'corrected_quality' => 'corrected',
            'reason_code' => 'source_revision',
            'reason_text' => null,
            'change_origin' => 'source',
            'changed_by' => null,
            'source_updated_at' => null,
            'created_at' => '2026-08-31 06:00:00',
            'updated_at' => '2026-08-31 06:00:00',
            ...$overrides,
        ];
    }
}
