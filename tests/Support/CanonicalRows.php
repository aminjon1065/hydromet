<?php

namespace Tests\Support;

use App\Domain\Measurements\Data\MeasurementBatch;
use App\Domain\Measurements\Data\MeasurementRecord;
use App\Domain\Stations\Data\ParameterCatalogueBatch;
use App\Domain\Stations\Data\ParameterRecord;
use App\Domain\Stations\Data\StationRecord;
use App\Domain\Stations\Data\StationRegistryBatch;

/**
 * Canonical rows for tests, shaped exactly like docs/03-data-contracts.md.
 *
 * Values are invented and exist only so tests can state one deviation at a
 * time through `$overrides`.
 */
final class CanonicalRows
{
    public const SOURCE = 'test';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function station(array $overrides = []): array
    {
        return [
            'source' => self::SOURCE,
            'external_id' => 'test-station-001',
            'code' => 'TEST-001',
            'name' => [
                'tj' => 'Истгоҳи озмоишӣ 001',
                'ru' => 'Тестовая станция 001',
                'en' => 'Test station 001',
            ],
            'latitude' => 38.5,
            'longitude' => 68.7,
            'elevation_m' => 800.0,
            'region_code' => 'TEST-REGION-A',
            'district_code' => null,
            'timezone' => 'Asia/Dushanbe',
            'status' => 'active',
            'station_type' => 'air_quality',
            'owner' => null,
            'installed_at' => null,
            'parameters' => [],
            'updated_at' => '2026-08-31T06:00:00Z',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function parameter(array $overrides = []): array
    {
        return [
            'code' => 'PM25',
            'kind' => 'pollutant',
            'name' => [
                'tj' => 'Зарраҳои муаллақи PM2,5',
                'ru' => 'Взвешенные частицы PM2,5',
                'en' => 'Particulate matter PM2.5',
            ],
            'canonical_unit' => 'ug/m3',
            'precision' => 1,
            'default_averaging_period' => 'PT1H',
            'plausible_min' => 0,
            'plausible_max' => 2000,
            'active' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function stationBatch(array $rows): StationRegistryBatch
    {
        return new StationRegistryBatch(
            self::SOURCE,
            array_map(static fn (array $row): StationRecord => StationRecord::fromCanonical($row), $rows),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function parameterBatch(array $rows): ParameterCatalogueBatch
    {
        return new ParameterCatalogueBatch(
            self::SOURCE,
            array_map(static fn (array $row): ParameterRecord => ParameterRecord::fromCanonical($row), $rows),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function measurement(array $overrides = []): array
    {
        return [
            'source' => self::SOURCE,
            'source_measurement_id' => null,
            'station_external_id' => 'test-station-001',
            'parameter_code' => 'PM25',
            'sensor_no' => null,
            'observed_at' => '2026-08-31T06:00:00Z',
            'received_at' => '2026-08-31T06:02:00Z',
            'value' => 23.4,
            'unit' => 'ug/m3',
            'averaging_period' => 'PT1H',
            'quality' => 'valid',
            'quality_flags' => [],
            'revision' => 1,
            'is_manual' => false,
            'source_updated_at' => '2026-08-31T06:02:00Z',
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function measurementBatch(array $rows): MeasurementBatch
    {
        return new MeasurementBatch(
            self::SOURCE,
            array_map(static fn (array $row): MeasurementRecord => MeasurementRecord::fromCanonical($row), $rows),
        );
    }
}
