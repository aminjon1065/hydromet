<?php

namespace Tests\Feature\Stations;

use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use App\Domain\Stations\Services\StationRegistryImporter;
use App\Support\Canonical\RejectionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CanonicalRows;
use Tests\TestCase;

class StationRegistryImportTest extends TestCase
{
    use RefreshDatabase;

    private StationRegistryImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = new StationRegistryImporter;
    }

    #[Test]
    public function the_fixture_import_stores_every_valid_station_and_parameter(): void
    {
        $report = $this->importer->import(new FixtureStationRegistryProvider);

        $this->assertSame(FixtureStationRegistryProvider::SOURCE_KEY, $report->source);
        $this->assertSame(5, $report->parameters->received);
        $this->assertSame(5, $report->parameters->created);
        $this->assertSame(0, $report->parameters->rejected());

        // Four rows arrive, one of them is deliberately broken.
        $this->assertSame(4, $report->stations->received);
        $this->assertSame(3, $report->stations->created);
        $this->assertSame(1, $report->stations->rejected());

        $this->assertSame(5, Parameter::query()->count());
        $this->assertSame(3, Station::query()->count());
    }

    #[Test]
    public function repeating_the_same_fixture_import_does_not_add_stations(): void
    {
        $this->importer->import(new FixtureStationRegistryProvider);
        $countAfterFirstRun = Station::query()->count();

        $second = $this->importer->import(new FixtureStationRegistryProvider);

        $this->assertSame($countAfterFirstRun, Station::query()->count());
        $this->assertSame(0, $second->stations->created);
        $this->assertSame(0, $second->stations->updated);
        $this->assertSame(3, $second->stations->unchanged);
        $this->assertSame(0, $second->parameters->created);
        $this->assertSame(0, $second->parameters->updated);
        $this->assertSame(5, $second->parameters->unchanged);
    }

    #[Test]
    public function repeating_the_same_fixture_import_does_not_duplicate_parameter_links(): void
    {
        $this->importer->import(new FixtureStationRegistryProvider);
        $linksAfterFirstRun = DB::table('station_parameter')->count();

        $this->importer->import(new FixtureStationRegistryProvider);

        $this->assertSame(8, $linksAfterFirstRun);
        $this->assertSame($linksAfterFirstRun, DB::table('station_parameter')->count());

        $duplicates = DB::table('station_parameter')
            ->select('station_id', 'parameter_id')
            ->groupBy('station_id', 'parameter_id')
            ->havingRaw('count(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates);
    }

    #[Test]
    public function a_changed_canonical_record_updates_the_existing_station(): void
    {
        $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([CanonicalRows::station()]),
        );

        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station([
                    'name' => [
                        'tj' => 'Истгоҳи озмоишӣ 001 (нав)',
                        'ru' => 'Тестовая станция 001 (обновлено)',
                        'en' => 'Test station 001 (renamed)',
                    ],
                    'status' => 'maintenance',
                    'elevation_m' => 815.5,
                    'updated_at' => '2026-09-01T06:00:00Z',
                ]),
            ]),
        );

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, Station::query()->count());

        $station = Station::query()->sole();
        $this->assertSame('Test station 001 (renamed)', $station->name_en);
        $this->assertSame(StationStatus::Maintenance, $station->status);
        $this->assertSame('815.50', $station->elevation_m);
    }

    #[Test]
    public function changing_only_the_parameter_list_counts_as_an_update(): void
    {
        $this->importer->importParameterCatalogue(
            CanonicalRows::parameterBatch([
                CanonicalRows::parameter(),
                CanonicalRows::parameter(['code' => 'PM10']),
            ]),
        );

        $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([CanonicalRows::station(['parameters' => ['PM25']])]),
        );

        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([CanonicalRows::station(['parameters' => ['PM25', 'PM10']])]),
        );

        $this->assertSame(1, $result->updated);
        $this->assertSame(2, Station::query()->sole()->parameters()->count());
    }

    #[Test]
    public function one_invalid_row_does_not_discard_the_valid_rows_around_it(): void
    {
        $report = $this->importer->import(new FixtureStationRegistryProvider);

        $this->assertTrue($report->isPartial());
        $this->assertSame(3, Station::query()->count());
        $this->assertNull(
            Station::query()->where('external_id', 'fixture-station-004')->first(),
        );

        $rejections = $report->stations->rejections;
        $this->assertCount(1, $rejections);
        $this->assertSame(RejectionReason::LatitudeOutOfRange, $rejections[0]->reason);
        $this->assertSame('fixture:fixture-station-004', $rejections[0]->reference);
        $this->assertStringContainsString('outside -90..90', $rejections[0]->detail);
    }

    #[Test]
    public function a_rejection_carries_no_stack_trace_or_file_path(): void
    {
        $report = $this->importer->import(new FixtureStationRegistryProvider);

        foreach ($report->rejections() as $rejection) {
            $this->assertStringNotContainsString('#0 ', $rejection->detail);
            $this->assertStringNotContainsString('.php', $rejection->detail);
            $this->assertStringNotContainsString(base_path(), $rejection->detail);
            $this->assertDoesNotMatchRegularExpression('/\R/', $rejection->detail);
        }
    }

    #[Test]
    public function a_latitude_outside_the_allowed_range_is_rejected(): void
    {
        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station(['latitude' => 90.000001]),
                CanonicalRows::station([
                    'external_id' => 'test-station-002',
                    'code' => 'TEST-002',
                    'latitude' => -90.000001,
                ]),
            ]),
        );

        $this->assertSame(0, Station::query()->count());
        $this->assertSame(2, $result->rejected());

        foreach ($result->rejections as $rejection) {
            $this->assertSame(RejectionReason::LatitudeOutOfRange, $rejection->reason);
        }
    }

    #[Test]
    public function a_longitude_outside_the_allowed_range_is_rejected(): void
    {
        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station(['longitude' => 180.000001]),
                CanonicalRows::station([
                    'external_id' => 'test-station-002',
                    'code' => 'TEST-002',
                    'longitude' => -180.000001,
                ]),
            ]),
        );

        $this->assertSame(0, Station::query()->count());
        $this->assertSame(2, $result->rejected());

        foreach ($result->rejections as $rejection) {
            $this->assertSame(RejectionReason::LongitudeOutOfRange, $rejection->reason);
        }
    }

    #[Test]
    public function the_extreme_valid_coordinates_are_accepted(): void
    {
        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station(['latitude' => -90, 'longitude' => -180]),
                CanonicalRows::station([
                    'external_id' => 'test-station-002',
                    'code' => 'TEST-002',
                    'latitude' => 90,
                    'longitude' => 180,
                ]),
            ]),
        );

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->rejected());
    }

    #[Test]
    public function a_station_referencing_an_unknown_parameter_code_is_rejected(): void
    {
        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station(['parameters' => ['NOT_IN_CATALOGUE']]),
            ]),
        );

        $this->assertSame(0, Station::query()->count());
        $this->assertSame(RejectionReason::UnknownParameterCode, $result->rejections[0]->reason);
    }

    #[Test]
    public function a_station_with_an_unknown_timezone_is_rejected(): void
    {
        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station(['timezone' => 'Mars/Olympus']),
            ]),
        );

        $this->assertSame(0, Station::query()->count());
        $this->assertSame(RejectionReason::UnknownTimezone, $result->rejections[0]->reason);
    }

    #[Test]
    public function a_missing_optional_value_is_stored_as_null_and_never_as_zero(): void
    {
        $this->importer->importParameterCatalogue(
            CanonicalRows::parameterBatch([
                CanonicalRows::parameter([
                    'default_averaging_period' => null,
                    'plausible_min' => null,
                    'plausible_max' => null,
                ]),
            ]),
        );

        $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station([
                    'elevation_m' => null,
                    'district_code' => null,
                    'owner' => null,
                    'installed_at' => null,
                ]),
            ]),
        );

        $station = Station::query()->sole();
        $this->assertNull($station->elevation_m);
        $this->assertNull($station->district_code);
        $this->assertNull($station->owner);
        $this->assertNull($station->installed_at);

        $parameter = Parameter::query()->sole();
        $this->assertNull($parameter->default_averaging_period);
        $this->assertNull($parameter->plausible_min);
        $this->assertNull($parameter->plausible_max);

        // Guard against a null that silently became a falsy scalar.
        $row = DB::table('stations')->where('id', $station->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->elevation_m);
        $this->assertNull($row->district_code);
    }

    #[Test]
    public function a_second_row_with_the_same_station_code_is_rejected_without_losing_the_first(): void
    {
        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station(),
                CanonicalRows::station(['external_id' => 'test-station-002']),
            ]),
        );

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->rejected());
        $this->assertSame(RejectionReason::DuplicateInBatch, $result->rejections[0]->reason);
        $this->assertSame(1, Station::query()->count());
    }

    #[Test]
    public function a_station_declaring_a_different_source_than_its_batch_is_rejected(): void
    {
        $result = $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([CanonicalRows::station(['source' => 'somewhere-else'])]),
        );

        $this->assertSame(0, Station::query()->count());
        $this->assertSame(RejectionReason::MalformedRow, $result->rejections[0]->reason);
    }

    #[Test]
    public function stations_absent_from_a_later_batch_are_kept(): void
    {
        $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([
                CanonicalRows::station(),
                CanonicalRows::station(['external_id' => 'test-station-002', 'code' => 'TEST-002']),
            ]),
        );

        $this->importer->importStationRegistry(
            CanonicalRows::stationBatch([CanonicalRows::station()]),
        );

        $this->assertSame(2, Station::query()->count());
    }

    #[Test]
    public function a_parameter_with_reversed_plausible_bounds_is_rejected(): void
    {
        $result = $this->importer->importParameterCatalogue(
            CanonicalRows::parameterBatch([
                CanonicalRows::parameter(['plausible_min' => 100, 'plausible_max' => 10]),
            ]),
        );

        $this->assertSame(0, Parameter::query()->count());
        $this->assertSame(RejectionReason::ImplausibleBounds, $result->rejections[0]->reason);
    }
}
