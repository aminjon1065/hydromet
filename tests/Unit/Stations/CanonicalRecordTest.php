<?php

namespace Tests\Unit\Stations;

use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Stations\Data\ParameterRecord;
use App\Domain\Stations\Data\StationRecord;
use App\Domain\Stations\Enums\ParameterKind;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Enums\StationType;
use App\Support\Canonical\InvalidCanonicalRow;
use App\Support\Canonical\RejectedRow;
use App\Support\Canonical\RejectionReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CanonicalRows;
use Tests\TestCase;

class CanonicalRecordTest extends TestCase
{
    #[Test]
    public function a_complete_canonical_station_row_is_read_into_typed_values(): void
    {
        $record = StationRecord::fromCanonical(CanonicalRows::station([
            'elevation_m' => 807,
            'district_code' => 'TEST-DISTRICT',
            'owner' => 'Test operator',
            'installed_at' => '2026-01-20',
            'parameters' => ['PM25', 'PM10'],
        ]));

        $this->assertSame('test', $record->source);
        $this->assertSame('test-station-001', $record->externalId);
        $this->assertSame('Test station 001', $record->nameEn);
        $this->assertSame(38.5, $record->latitude);
        $this->assertSame(807.0, $record->elevationM);
        $this->assertSame(StationStatus::Active, $record->status);
        $this->assertSame(StationType::AirQuality, $record->stationType);
        $this->assertSame(['PM25', 'PM10'], $record->parameterCodes);
        $this->assertSame('2026-01-20', $record->installedAt?->toDateString());
        // Exchanged with an offset, held in UTC.
        $this->assertSame('2026-08-31 06:00:00', $record->sourceUpdatedAt->toDateTimeString());
        $this->assertSame('UTC', $record->sourceUpdatedAt->getTimezone()->getName());
    }

    #[Test]
    public function an_absent_optional_field_reads_as_null_not_zero(): void
    {
        $record = StationRecord::fromCanonical(CanonicalRows::station([
            'elevation_m' => null,
            'installed_at' => null,
        ]));

        $this->assertNull($record->elevationM);
        $this->assertNull($record->installedAt);
        $this->assertNotSame(0.0, $record->elevationM);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredStationFields(): array
    {
        return [
            'source' => ['source'],
            'external_id' => ['external_id'],
            'code' => ['code'],
            'name' => ['name'],
            'latitude' => ['latitude'],
            'longitude' => ['longitude'],
            'region_code' => ['region_code'],
            'timezone' => ['timezone'],
            'status' => ['status'],
            'station_type' => ['station_type'],
            'parameters' => ['parameters'],
            'updated_at' => ['updated_at'],
        ];
    }

    #[Test]
    #[DataProvider('requiredStationFields')]
    public function a_missing_required_field_is_reported_as_a_malformed_row(string $field): void
    {
        $row = CanonicalRows::station();
        unset($row[$field]);

        try {
            StationRecord::fromCanonical($row);
            $this->fail("Expected '{$field}' to be required.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::MalformedRow, $exception->reason);
            $this->assertStringContainsString($field, $exception->safeDetail());
        }
    }

    #[Test]
    public function a_numeric_field_supplied_as_a_string_is_rejected(): void
    {
        $this->expectException(InvalidCanonicalRow::class);

        StationRecord::fromCanonical(CanonicalRows::station(['latitude' => '38.5']));
    }

    #[Test]
    public function an_unsupported_status_is_reported_as_an_unknown_enum_value(): void
    {
        try {
            StationRecord::fromCanonical(CanonicalRows::station(['status' => 'retired']));
            $this->fail('Expected an unsupported status to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::UnknownEnumValue, $exception->reason);
        }
    }

    #[Test]
    public function a_name_missing_one_application_locale_is_rejected(): void
    {
        $this->expectException(InvalidCanonicalRow::class);

        StationRecord::fromCanonical(CanonicalRows::station([
            'name' => ['ru' => 'Тестовая станция', 'en' => 'Test station'],
        ]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedTimestamps(): array
    {
        return [
            'utc designator' => ['2026-08-31T06:00:00Z', '2026-08-31 06:00:00'],
            'positive offset' => ['2026-08-31T11:00:00+05:00', '2026-08-31 06:00:00'],
            'negative offset' => ['2026-08-31T02:00:00-04:00', '2026-08-31 06:00:00'],
            'fractional seconds with Z' => ['2026-08-31T06:00:00.123Z', '2026-08-31 06:00:00'],
            'fractional seconds with offset' => ['2026-08-31T11:00:00.123456+05:00', '2026-08-31 06:00:00'],
            'midnight' => ['2026-01-01T00:00:00Z', '2026-01-01 00:00:00'],
            'leap day' => ['2028-02-29T23:59:59Z', '2028-02-29 23:59:59'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedTimestamps')]
    public function an_iso_8601_timestamp_with_a_declared_zone_is_normalized_to_utc(
        string $supplied,
        string $expectedUtc,
    ): void {
        $record = StationRecord::fromCanonical(CanonicalRows::station(['updated_at' => $supplied]));

        $this->assertSame($expectedUtc, $record->sourceUpdatedAt->toDateTimeString());
        $this->assertSame('UTC', $record->sourceUpdatedAt->getTimezone()->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedTimestamps(): array
    {
        return [
            'no timezone at all' => ['2026-08-31T06:00:00'],
            'date only' => ['2026-08-31'],
            'space separator without zone' => ['2026-08-31 06:00:00'],
            'natural language' => ['tomorrow'],
            'natural language phrase' => ['next monday'],
            'relative expression' => ['+1 day'],
            'impossible day of month' => ['2026-02-31T06:00:00Z'],
            'impossible month' => ['2026-13-01T06:00:00Z'],
            'non-leap 29 february' => ['2027-02-29T06:00:00Z'],
            'impossible hour' => ['2026-08-31T24:00:00Z'],
            'impossible minute' => ['2026-08-31T06:60:00Z'],
            'impossible second' => ['2026-08-31T06:00:60Z'],
            'implausible offset' => ['2026-08-31T06:00:00+20:00'],
            'lowercase designator' => ['2026-08-31T06:00:00z'],
            'timezone name instead of offset' => ['2026-08-31T06:00:00 Asia/Dushanbe'],
            'unix timestamp as text' => ['1788148800'],
            'two digit year' => ['26-08-31T06:00:00Z'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedTimestamps')]
    public function a_timestamp_the_portal_cannot_trust_is_rejected(string $supplied): void
    {
        try {
            StationRecord::fromCanonical(CanonicalRows::station(['updated_at' => $supplied]));
            $this->fail("Expected '{$supplied}' to be rejected as updated_at.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::InvalidFieldType, $exception->reason);
            $this->assertStringContainsString('updated_at', $exception->safeDetail());
        }
    }

    #[Test]
    public function a_timestamp_without_a_timezone_is_never_read_as_local_time(): void
    {
        // The regression this guards: a permissive parser stamps the
        // application timezone onto a zone-less timestamp, so the row is stored
        // with a plausible but invented instant instead of being rejected.
        $this->expectException(InvalidCanonicalRow::class);

        StationRecord::fromCanonical(CanonicalRows::station(['updated_at' => '2026-08-31T06:00:00']));
    }

    #[Test]
    public function an_impossible_calendar_date_is_rejected_instead_of_rolling_forward(): void
    {
        try {
            StationRecord::fromCanonical(CanonicalRows::station(['updated_at' => '2026-02-31T06:00:00Z']));
            $this->fail('Expected 2026-02-31 to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertStringContainsString('does not exist in the calendar', $exception->safeDetail());
        }
    }

    #[Test]
    public function a_commissioning_date_is_read_from_an_exact_calendar_date(): void
    {
        $record = StationRecord::fromCanonical(CanonicalRows::station(['installed_at' => '2026-01-20']));

        $this->assertNotNull($record->installedAt);
        $this->assertSame('2026-01-20', $record->installedAt->toDateString());
        // A date, not a truncated timestamp.
        $this->assertSame('00:00:00', $record->installedAt->toTimeString());
        $this->assertSame('UTC', $record->installedAt->getTimezone()->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedDates(): array
    {
        return [
            'datetime instead of date' => ['2026-01-20T06:00:00Z'],
            'datetime without zone' => ['2026-01-20 06:00:00'],
            'impossible day of month' => ['2026-02-31'],
            'non-leap 29 february' => ['2027-02-29'],
            'impossible month' => ['2026-13-01'],
            'natural language' => ['tomorrow'],
            'unpadded month and day' => ['2026-1-5'],
            'slash separators' => ['2026/01/20'],
            'day first' => ['20-01-2026'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedDates')]
    public function a_commissioning_date_that_is_not_an_exact_calendar_date_is_rejected(string $supplied): void
    {
        try {
            StationRecord::fromCanonical(CanonicalRows::station(['installed_at' => $supplied]));
            $this->fail("Expected '{$supplied}' to be rejected as installed_at.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::InvalidFieldType, $exception->reason);
            $this->assertStringContainsString('installed_at', $exception->safeDetail());
        }
    }

    #[Test]
    public function a_rejected_timestamp_never_echoes_the_supplied_value(): void
    {
        try {
            StationRecord::fromCanonical(CanonicalRows::station([
                'updated_at' => 'tomorrow; DROP TABLE stations',
            ]));
            $this->fail('Expected the value to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertStringNotContainsString('DROP TABLE', $exception->safeDetail());
        }
    }

    #[Test]
    public function a_canonical_parameter_row_is_read_into_typed_values(): void
    {
        $record = ParameterRecord::fromCanonical(CanonicalRows::parameter([
            'code' => 'TA',
            'kind' => 'meteorological',
            'canonical_unit' => 'degC',
            'precision' => 1,
            'default_averaging_period' => null,
            'plausible_min' => -60,
            'plausible_max' => 60,
            'active' => false,
        ]));

        $this->assertSame('TA', $record->code);
        $this->assertSame(ParameterKind::Meteorological, $record->kind);
        $this->assertSame('degC', $record->canonicalUnit);
        $this->assertNull($record->defaultAveragingPeriod);
        $this->assertSame(-60.0, $record->plausibleMin);
        $this->assertFalse($record->active);
    }

    #[Test]
    public function the_fixture_provider_maps_every_readable_row_and_stamps_its_own_source(): void
    {
        $provider = new FixtureStationRegistryProvider;

        $catalogue = $provider->fetchParameterCatalogue();
        $registry = $provider->fetchStationRegistry();

        $this->assertSame('fixture', $provider->sourceKey());
        $this->assertSame([], $catalogue->rejections);
        $this->assertCount(5, $catalogue->records);

        // The invalid fixture row is structurally readable: its latitude is a
        // number, just not a possible one. The import service rejects it.
        $this->assertSame([], $registry->rejections);
        $this->assertCount(4, $registry->records);

        foreach ($registry->records as $record) {
            $this->assertSame('fixture', $record->source);
        }
    }

    #[Test]
    public function the_fixture_provider_never_reads_a_row_from_outside_the_fixture(): void
    {
        $provider = new FixtureStationRegistryProvider(
            __DIR__.'/does-not-exist.json',
        );

        $this->expectException(\RuntimeException::class);

        $provider->fetchStationRegistry();
    }

    #[Test]
    public function rejection_text_is_reduced_to_one_safe_printable_line(): void
    {
        $rejection = RejectedRow::make(
            "row\n1",
            RejectionReason::MalformedRow,
            "line one\r\nline two\ttabbed",
        );

        $this->assertSame('row 1', $rejection->reference);
        $this->assertSame('line one line two tabbed', $rejection->detail);
    }

    #[Test]
    public function rejection_text_is_truncated_rather_than_echoing_a_long_payload(): void
    {
        $rejection = RejectedRow::make(
            str_repeat('x', 500),
            RejectionReason::MalformedRow,
            str_repeat('y', 500),
        );

        $this->assertSame(80, mb_strlen($rejection->reference));
        $this->assertSame(200, mb_strlen($rejection->detail));
        $this->assertStringEndsWith('…', $rejection->detail);
    }
}
