<?php

namespace Tests\Unit\Measurements;

use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureMeasurementScenario;
use App\Domain\Measurements\Data\MeasurementRecord;
use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Support\Canonical\InvalidCanonicalRow;
use App\Support\Canonical\RejectionReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CanonicalRows;
use Tests\TestCase;

class MeasurementRecordTest extends TestCase
{
    #[Test]
    public function a_complete_canonical_row_is_read_into_typed_values(): void
    {
        $record = MeasurementRecord::fromCanonical(CanonicalRows::measurement([
            'source_measurement_id' => 'PROVIDER-1',
            'sensor_no' => '2',
            'quality_flags' => ['flag_a', 'flag_b'],
        ]));

        $this->assertSame('test', $record->source);
        $this->assertSame('PROVIDER-1', $record->sourceMeasurementId);
        $this->assertSame('test-station-001', $record->stationExternalId);
        $this->assertSame('PM25', $record->parameterCode);
        $this->assertSame('2', $record->sensorNo);
        $this->assertSame(23.4, $record->value);
        $this->assertSame('ug/m3', $record->unit);
        $this->assertSame('PT1H', $record->averagingPeriod);
        $this->assertSame(MeasurementQuality::Valid, $record->quality);
        $this->assertSame(['flag_a', 'flag_b'], $record->qualityFlags);
        $this->assertSame(1, $record->revision);
        $this->assertFalse($record->isManual);
        $this->assertSame('2026-08-31 06:00:00', $record->observedAt->toDateTimeString());
        $this->assertSame('UTC', $record->observedAt->getTimezone()->getName());
        $this->assertSame('2026-08-31 06:02:00', $record->receivedAt?->toDateTimeString());
        $this->assertSame('2026-08-31 06:02:00', $record->sourceUpdatedAt?->toDateTimeString());
    }

    #[Test]
    public function an_absent_optional_timestamp_reads_as_null(): void
    {
        $record = MeasurementRecord::fromCanonical(CanonicalRows::measurement([
            'received_at' => null,
            'source_updated_at' => null,
            'averaging_period' => null,
            'source_measurement_id' => null,
            'sensor_no' => null,
        ]));

        $this->assertNull($record->receivedAt);
        $this->assertNull($record->sourceUpdatedAt);
        $this->assertNull($record->averagingPeriod);
        $this->assertNull($record->sourceMeasurementId);
        $this->assertNull($record->sensorNo);
    }

    #[Test]
    public function a_null_value_is_read_as_a_missing_reading_not_as_zero(): void
    {
        $record = MeasurementRecord::fromCanonical(CanonicalRows::measurement([
            'value' => null,
            'quality' => 'missing',
        ]));

        $this->assertNull($record->value);
        $this->assertNotSame(0.0, $record->value);
        $this->assertSame(MeasurementQuality::Missing, $record->quality);
    }

    #[Test]
    public function an_omitted_value_key_is_a_malformed_row_rather_than_a_missing_reading(): void
    {
        // The contract requires the key and allows it to be null: leaving it
        // out is a mapping fault, not an observation that was not taken.
        $row = CanonicalRows::measurement();
        unset($row['value']);

        try {
            MeasurementRecord::fromCanonical($row);
            $this->fail('Expected an omitted value key to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::MalformedRow, $exception->reason);
            $this->assertStringContainsString('value', $exception->safeDetail());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'source' => ['source'],
            'station_external_id' => ['station_external_id'],
            'parameter_code' => ['parameter_code'],
            'observed_at' => ['observed_at'],
            'unit' => ['unit'],
            'quality' => ['quality'],
            'quality_flags' => ['quality_flags'],
            'revision' => ['revision'],
            'is_manual' => ['is_manual'],
        ];
    }

    #[Test]
    #[DataProvider('requiredFields')]
    public function a_missing_required_field_is_reported_as_a_malformed_row(string $field): void
    {
        $row = CanonicalRows::measurement();
        unset($row[$field]);

        try {
            MeasurementRecord::fromCanonical($row);
            $this->fail("Expected '{$field}' to be required.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::MalformedRow, $exception->reason);
            $this->assertStringContainsString($field, $exception->safeDetail());
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedTimestamps(): array
    {
        return [
            'utc designator' => ['2026-08-31T06:00:00Z', '2026-08-31 06:00:00'],
            'positive offset' => ['2026-08-31T11:00:00+05:00', '2026-08-31 06:00:00'],
            'fractional seconds' => ['2026-08-31T06:00:00.250Z', '2026-08-31 06:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedTimestamps')]
    public function an_observation_time_with_a_declared_zone_is_normalized_to_utc(
        string $supplied,
        string $expected,
    ): void {
        $record = MeasurementRecord::fromCanonical(CanonicalRows::measurement(['observed_at' => $supplied]));

        $this->assertSame($expected, $record->observedAt->toDateTimeString());
        $this->assertSame('UTC', $record->observedAt->getTimezone()->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedTimestamps(): array
    {
        return [
            'no timezone' => ['2026-08-31T06:00:00'],
            'space separator' => ['2026-08-31 06:00:00'],
            'date only' => ['2026-08-31'],
            'natural language' => ['tomorrow'],
            'impossible day' => ['2026-02-31T06:00:00Z'],
            'impossible hour' => ['2026-08-31T24:00:00Z'],
            'unix timestamp' => ['1788148800'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedTimestamps')]
    public function an_observation_time_the_portal_cannot_trust_is_rejected(string $supplied): void
    {
        try {
            MeasurementRecord::fromCanonical(CanonicalRows::measurement(['observed_at' => $supplied]));
            $this->fail("Expected '{$supplied}' to be rejected as observed_at.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::InvalidFieldType, $exception->reason);
            $this->assertStringContainsString('observed_at', $exception->safeDetail());
        }
    }

    #[Test]
    #[DataProvider('rejectedTimestamps')]
    public function an_optional_timestamp_is_held_to_the_same_rules(string $supplied): void
    {
        $this->expectException(InvalidCanonicalRow::class);

        MeasurementRecord::fromCanonical(CanonicalRows::measurement(['received_at' => $supplied]));
    }

    #[Test]
    public function an_unsupported_quality_is_reported_as_an_unknown_enum_value(): void
    {
        try {
            MeasurementRecord::fromCanonical(CanonicalRows::measurement(['quality' => 'estimated']));
            $this->fail('Expected an unsupported quality to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::UnknownEnumValue, $exception->reason);
        }
    }

    #[Test]
    public function quality_flags_must_be_a_list_of_strings(): void
    {
        $this->expectException(InvalidCanonicalRow::class);

        MeasurementRecord::fromCanonical(CanonicalRows::measurement([
            'quality_flags' => ['ok', 7],
        ]));
    }

    #[Test]
    public function quality_flags_supplied_as_an_object_are_rejected(): void
    {
        $this->expectException(InvalidCanonicalRow::class);

        MeasurementRecord::fromCanonical(CanonicalRows::measurement([
            'quality_flags' => ['flag' => 'value'],
        ]));
    }

    #[Test]
    public function a_numeric_value_supplied_as_a_string_is_rejected(): void
    {
        $this->expectException(InvalidCanonicalRow::class);

        MeasurementRecord::fromCanonical(CanonicalRows::measurement(['value' => '23.4']));
    }

    #[Test]
    public function a_revision_supplied_as_a_string_is_rejected(): void
    {
        $this->expectException(InvalidCanonicalRow::class);

        MeasurementRecord::fromCanonical(CanonicalRows::measurement(['revision' => '2']));
    }

    #[Test]
    public function the_row_reference_names_the_natural_key(): void
    {
        $record = MeasurementRecord::fromCanonical(CanonicalRows::measurement(['sensor_no' => '3']));

        // Six fractional digits even when the source stated none, so the
        // reference is comparable across rows.
        $this->assertSame('test:test-station-001:PM25:2026-08-31T06:00:00.000000Z:3', $record->identity());
    }

    #[Test]
    public function the_row_reference_distinguishes_observations_inside_one_second(): void
    {
        $first = MeasurementRecord::fromCanonical(
            CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.100000Z']),
        );
        $second = MeasurementRecord::fromCanonical(
            CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.200000Z']),
        );

        $this->assertNotSame($first->identity(), $second->identity());
        $this->assertStringEndsWith('.100000Z:-', $first->identity());
        $this->assertStringEndsWith('.200000Z:-', $second->identity());
        $this->assertNotSame($first->naturalKey(1, 1), $second->naturalKey(1, 1));
    }

    #[Test]
    public function fractional_seconds_survive_being_read(): void
    {
        $record = MeasurementRecord::fromCanonical(
            CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.123456Z']),
        );

        $this->assertSame('2026-08-31T06:00:00.123456Z', $record->observedAtIso());
        $this->assertSame(123456, (int) $record->observedAt->format('u'));
    }

    #[Test]
    public function fractional_seconds_are_kept_when_an_offset_is_converted_to_utc(): void
    {
        $record = MeasurementRecord::fromCanonical(
            CanonicalRows::measurement(['observed_at' => '2026-08-31T11:00:00.123456+05:00']),
        );

        $this->assertSame('2026-08-31T06:00:00.123456Z', $record->observedAtIso());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function unsupportedPrecisions(): array
    {
        return [
            'seven digits' => ['2026-08-31T06:00:00.1234567Z', 7],
            'eight digits' => ['2026-08-31T06:00:00.12345678Z', 8],
            'nine digits' => ['2026-08-31T06:00:00.123456789Z', 9],
            'nine digits with offset' => ['2026-08-31T11:00:00.123456789+05:00', 9],
        ];
    }

    #[Test]
    #[DataProvider('unsupportedPrecisions')]
    public function a_timestamp_finer_than_microseconds_is_refused_rather_than_truncated(
        string $supplied,
        int $digits,
    ): void {
        try {
            MeasurementRecord::fromCanonical(CanonicalRows::measurement(['observed_at' => $supplied]));
            $this->fail("Expected {$digits} fractional digits to be refused.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::UnsupportedTimestampPrecision, $exception->reason);
            $this->assertStringContainsString("{$digits} fractional second digits", $exception->safeDetail());
            $this->assertStringContainsString('observed_at', $exception->safeDetail());
        }
    }

    #[Test]
    public function exactly_six_fractional_digits_are_accepted(): void
    {
        $record = MeasurementRecord::fromCanonical(
            CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.999999Z']),
        );

        $this->assertSame(999999, (int) $record->observedAt->format('u'));
    }

    #[Test]
    public function optional_timestamps_are_held_to_the_same_precision_policy(): void
    {
        foreach (['received_at', 'source_updated_at'] as $field) {
            try {
                MeasurementRecord::fromCanonical(
                    CanonicalRows::measurement([$field => '2026-08-31T06:00:00.1234567Z']),
                );
                $this->fail("Expected {$field} to refuse seven fractional digits.");
            } catch (InvalidCanonicalRow $exception) {
                $this->assertSame(RejectionReason::UnsupportedTimestampPrecision, $exception->reason);
                $this->assertStringContainsString($field, $exception->safeDetail());
            }
        }
    }

    #[Test]
    public function the_base_fixture_maps_every_readable_row_and_stamps_its_own_source(): void
    {
        $batch = (new FixtureMeasurementProvider(FixtureMeasurementScenario::Base))->fetchMeasurements();

        $this->assertSame('fixture', $batch->source);
        // The deliberately broken row is structurally readable; it names a
        // station that does not exist, which only the import service can know.
        $this->assertSame([], $batch->rejections);
        $this->assertCount(8, $batch->records);

        foreach ($batch->records as $record) {
            $this->assertSame('fixture', $record->source);
            $this->assertFalse($record->isManual);
        }
    }

    #[Test]
    public function the_correction_fixture_restates_one_observation_at_a_higher_revision(): void
    {
        $batch = (new FixtureMeasurementProvider(FixtureMeasurementScenario::Correction))->fetchMeasurements();

        $this->assertCount(1, $batch->records);
        $this->assertSame(2, $batch->records[0]->revision);
        $this->assertSame(MeasurementQuality::Corrected, $batch->records[0]->quality);
    }

    #[Test]
    public function a_fixture_that_declares_another_scenario_is_refused(): void
    {
        // A readable file that is simply the wrong batch: without the scenario
        // check the importer would load it and misreport what it applied.
        $baseFixture = base_path(
            'app/Domain/Integrations/Fixtures/data/'.FixtureMeasurementScenario::Base->fileName(),
        );

        $this->assertFileExists($baseFixture);

        $provider = new FixtureMeasurementProvider(FixtureMeasurementScenario::Correction, $baseFixture);

        $this->expectExceptionMessage('does not declare the requested scenario');

        $provider->fetchMeasurements();
    }

    #[Test]
    public function a_missing_fixture_file_fails_the_whole_read(): void
    {
        $provider = new FixtureMeasurementProvider(
            FixtureMeasurementScenario::Base,
            __DIR__.'/does-not-exist.json',
        );

        $this->expectException(RuntimeException::class);

        $provider->fetchMeasurements();
    }
}
