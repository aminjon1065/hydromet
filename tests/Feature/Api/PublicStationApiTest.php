<?php

namespace Tests\Feature\Api;

use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicStationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function metadata_is_localized_and_does_not_publish_an_unapproved_aqi_scheme(): void
    {
        Carbon::setTestNow('2026-09-01T07:00:00Z');
        Parameter::factory()->create([
            'code' => 'PM25',
            'name_tj' => 'Заррачаҳои PM2.5',
            'name_ru' => 'Частицы PM2.5',
            'name_en' => 'PM2.5 particles',
        ]);
        Parameter::factory()->inactive()->create(['code' => 'HIDDEN']);

        $this->withHeader('Accept-Language', 'tg-TJ')
            ->getJson('/api/v1/metadata')
            ->assertOk()
            ->assertHeader('Content-Language', 'tg-TJ')
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('data.timezone', 'Asia/Dushanbe')
            ->assertJsonPath('data.languages.0.code', 'tj')
            ->assertJsonPath('data.parameters.0.code', 'PM25')
            ->assertJsonPath('data.parameters.0.name', 'Заррачаҳои PM2.5')
            ->assertJsonPath('data.aqi_available', false)
            ->assertJsonPath('data.aqi_schemes', [])
            ->assertJsonCount(1, 'data.parameters')
            ->assertJsonPath('meta.generated_at', '2026-09-01T07:00:00.000000Z');
    }

    #[Test]
    public function station_index_is_compact_filterable_and_excludes_decommissioned_stations(): void
    {
        Carbon::setTestNow('2026-09-01T07:00:00Z');
        $pm25 = Parameter::factory()->create(['code' => 'PM25']);
        $inside = Station::factory()->create([
            'code' => 'INSIDE',
            'region_code' => 'DUSHANBE',
            'latitude' => 38.55,
            'longitude' => 68.78,
            'name_en' => 'Inside station',
        ]);
        $outside = Station::factory()->offline()->create([
            'code' => 'OUTSIDE',
            'region_code' => 'SUGHD',
            'latitude' => 40.28,
            'longitude' => 69.62,
        ]);
        $decommissioned = Station::factory()->create([
            'status' => StationStatus::Decommissioned,
            'code' => 'HIDDEN',
        ]);
        $inside->parameters()->attach($pm25);
        $decommissioned->parameters()->attach($pm25);

        Measurement::factory()->create([
            'station_id' => $inside->id,
            'parameter_id' => $pm25->id,
            'observed_at' => Carbon::parse('2026-09-01T06:00:00Z'),
            'value' => 23.4,
        ]);
        Measurement::factory()->create([
            'station_id' => $inside->id,
            'parameter_id' => $pm25->id,
            'observed_at' => Carbon::parse('2026-09-01T06:30:00Z'),
            'original_quality' => MeasurementQuality::Invalid,
            'quality' => MeasurementQuality::Invalid,
            'value' => 999,
        ]);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/stations?bbox=68,38,69,39&region=DUSHANBE&status=active&parameter=PM25')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inside->id)
            ->assertJsonPath('data.0.name', 'Inside station')
            ->assertJsonPath('data.0.observed_at', '2026-09-01T06:00:00.000000Z')
            ->assertJsonPath('data.0.is_stale', null)
            ->assertJsonPath('data.0.aqi', null)
            ->assertJsonPath('data.0.measurements.PM25.value', 23.4)
            ->assertJsonMissing(['OUTSIDE'])
            ->assertJsonMissing(['HIDDEN'])
            ->assertJsonPath('meta.next_cursor', null);

        $this->getJson('/api/v1/stations?status=offline')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $outside->id);
    }

    #[Test]
    public function station_index_supports_strict_incremental_refresh_and_rejects_bad_bounds(): void
    {
        $old = Station::factory()->create(['code' => 'OLD']);
        $updatedByObservation = Station::factory()->create(['code' => 'OBS-UPDATED']);
        $updatedItself = Station::factory()->create(['code' => 'SELF-UPDATED']);
        $parameter = Parameter::factory()->create(['code' => 'PM25']);
        $updatedByObservation->parameters()->attach($parameter);
        $old->parameters()->attach($parameter);
        Measurement::factory()->create([
            'station_id' => $updatedByObservation->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-09-01T06:00:00Z'),
            'updated_at' => Carbon::parse('2026-09-01T06:30:00Z'),
        ]);
        // A stale measurement must not drag its station into the refresh.
        Measurement::factory()->create([
            'station_id' => $old->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-08-30T06:00:00Z'),
            'updated_at' => Carbon::parse('2026-08-30T06:30:00Z'),
        ]);
        Station::query()->whereKey([$old->id, $updatedByObservation->id])->update([
            'updated_at' => Carbon::parse('2026-08-31T00:00:00Z'),
        ]);
        Station::query()->whereKey($updatedItself->id)->update([
            'updated_at' => Carbon::parse('2026-09-01T08:00:00Z'),
        ]);

        $this->getJson('/api/v1/stations?updated_after=2026-09-01T06:00:00Z')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $updatedByObservation->id)
            ->assertJsonPath('data.1.id', $updatedItself->id)
            ->assertJsonMissing(['code' => 'OLD']);

        $this->getJson('/api/v1/stations?updated_after=tomorrow')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_datetime');
        $this->getJson('/api/v1/stations?bbox=69,39,68,38')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_bbox');
    }

    #[Test]
    public function the_incremental_refresh_filter_has_a_supporting_measurement_index(): void
    {
        $columns = collect(Schema::getIndexes('measurements'))
            ->firstWhere('name', 'measurements_station_updated_at_index');

        $this->assertNotNull($columns, 'measurements_station_updated_at_index is missing.');
        $this->assertSame(['station_id', 'updated_at'], $columns['columns']);
    }

    #[Test]
    public function station_detail_contains_localized_metadata_latest_values_and_measurement_sync(): void
    {
        $source = IntegrationSource::factory()->create(['code' => 'fixture']);
        SynchronizationRun::factory()->measurements()->create([
            'source_id' => $source->id,
            'started_at' => Carbon::parse('2026-09-01T06:00:00Z'),
            'finished_at' => Carbon::parse('2026-09-01T06:00:02Z'),
        ]);
        $station = Station::factory()->create([
            'source' => 'fixture',
            'code' => 'DETAIL',
            'name_ru' => 'Подробная станция',
        ]);
        $parameter = Parameter::factory()->create([
            'code' => 'PM25',
            'name_ru' => 'Мелкие частицы',
        ]);
        $station->parameters()->attach($parameter);
        Measurement::factory()->create([
            'source' => 'fixture',
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'value' => 12.3,
        ]);

        $this->withHeader('Accept-Language', 'ru')
            ->getJson("/api/v1/stations/{$station->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Подробная станция')
            ->assertJsonPath('data.parameters.0.name', 'Мелкие частицы')
            ->assertJsonPath('data.measurements.PM25.value', 12.3)
            ->assertJsonPath('data.source.code', 'fixture')
            ->assertJsonPath('data.source.is_mock', true)
            ->assertJsonPath('data.source.last_success_at', '2026-09-01T06:00:02.000000Z')
            ->assertJsonPath('data.source.stale_after_seconds', null)
            ->assertJsonPath('data.aqi', null);
    }

    #[Test]
    public function series_defaults_to_valid_and_corrected_and_can_request_other_public_qualities(): void
    {
        [$station, $parameter] = $this->stationWithParameter();

        foreach ([
            ['2026-09-01T04:00:00Z', 10, MeasurementQuality::Valid, 1],
            ['2026-09-01T05:00:00Z', 20, MeasurementQuality::Corrected, 2],
            ['2026-09-01T06:00:00Z', 30, MeasurementQuality::Suspect, 1],
            ['2026-09-01T06:30:00Z', 999, MeasurementQuality::Invalid, 1],
        ] as [$observedAt, $value, $quality, $revision]) {
            Measurement::factory()->create([
                'station_id' => $station->id,
                'parameter_id' => $parameter->id,
                'observed_at' => Carbon::parse($observedAt),
                'original_value' => $revision > 1 ? 18 : $value,
                'original_quality' => $revision > 1 ? MeasurementQuality::Valid : $quality,
                'value' => $value,
                'quality' => $quality,
                'revision' => $revision,
            ]);
        }
        Measurement::factory()->missing()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-09-01T06:45:00Z'),
        ]);
        $query = 'parameters=PM25&from=2026-09-01T03%3A00%3A00Z&to=2026-09-01T07%3A00%3A00Z&aggregation=raw';

        $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/v1/stations/{$station->id}/series?{$query}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('station.name', $station->name_en)
            ->assertJsonPath('range.timezone', 'Asia/Dushanbe')
            ->assertJsonCount(2, 'series.0.points')
            ->assertJsonPath('series.0.points.1.corrected', true)
            ->assertJsonPath('series.0.points.1.sample_count', 1);

        $this->getJson("/api/v1/stations/{$station->id}/series?{$query}&quality=suspect,missing")
            ->assertOk()
            ->assertJsonCount(2, 'series.0.points')
            ->assertJsonPath('series.0.points.0.quality', 'suspect')
            ->assertJsonPath('series.0.points.1.value', null);
    }

    #[Test]
    public function dushanbe_daily_buckets_are_returned_as_their_utc_instants(): void
    {
        [$station, $parameter] = $this->stationWithParameter();

        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-08-31T18:30:00Z'),
            'value' => 10,
        ]);
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-08-31T19:30:00Z'),
            'value' => 20,
        ]);

        $this->getJson(
            "/api/v1/stations/{$station->id}/series?parameters=PM25"
            .'&from=2026-08-31T18%3A00%3A00Z&to=2026-09-01T20%3A00%3A00Z'
            .'&aggregation=day&timezone=Asia%2FDushanbe',
        )
            ->assertOk()
            ->assertJsonCount(2, 'series.0.points')
            ->assertJsonPath('series.0.points.0.time', '2026-08-30T19:00:00.000000Z')
            ->assertJsonPath('series.0.points.1.time', '2026-08-31T19:00:00.000000Z');
    }

    #[Test]
    public function series_errors_use_the_stable_envelope_and_request_id(): void
    {
        [$station] = $this->stationWithParameter();

        $response = $this->getJson("/api/v1/stations/{$station->id}/series")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'missing_query_parameter')
            ->assertJsonPath('error.details.field', 'parameters')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        $this->assertSame($response->json('error.request_id'), $response->headers->get('X-Request-Id'));

        $base = "/api/v1/stations/{$station->id}/series?parameters=PM25"
            .'&from=2026-01-01T00%3A00%3A00Z&to=2026-09-01T00%3A00%3A00Z';
        $this->getJson("{$base}&aggregation=raw")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_time_range');
        $this->getJson("{$base}&aggregation=day&quality=all")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'quality_filter_forbidden');
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function api_csv_uses_the_same_range_parameter_and_quality_filters(): void
    {
        [$station, $parameter] = $this->stationWithParameter();
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-09-01T05:00:00Z'),
            'value' => 25.9,
        ]);
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-09-01T06:00:00Z'),
            'original_quality' => MeasurementQuality::Suspect,
            'quality' => MeasurementQuality::Suspect,
            'value' => 99,
        ]);
        $query = 'parameters=PM25&from=2026-09-01T04%3A00%3A00Z&to=2026-09-01T07%3A00%3A00Z&aggregation=raw';

        $response = $this->get("/api/v1/stations/{$station->id}/export.csv?{$query}")
            ->assertOk()
            ->assertDownload("station-{$station->code}-20260901-20260901.csv")
            ->assertHeader('Cache-Control', 'max-age=300, public');

        $content = $response->streamedContent();
        $this->assertStringContainsString('25.9', $content);
        $this->assertStringNotContainsString(',99,', $content);
    }

    #[Test]
    public function adjacent_series_windows_are_half_open_and_never_overlap(): void
    {
        [$station, $parameter] = $this->stationWithParameter();

        foreach (['04', '05', '06'] as $index => $hour) {
            Measurement::factory()->create([
                'station_id' => $station->id,
                'parameter_id' => $parameter->id,
                'observed_at' => Carbon::parse("2026-09-01T{$hour}:00:00Z"),
                'value' => 10 + $index,
            ]);
        }

        $series = fn (string $from, string $to): string => "/api/v1/stations/{$station->id}/series"
            ."?parameters=PM25&aggregation=raw&from={$from}&to={$to}";

        // [04:00, 05:00) then [05:00, 06:00): the 05:00 observation is returned
        // by the second window only.
        $this->getJson($series('2026-09-01T04%3A00%3A00Z', '2026-09-01T05%3A00%3A00Z'))
            ->assertOk()
            ->assertJsonCount(1, 'series.0.points')
            ->assertJsonPath('series.0.points.0.time', '2026-09-01T04:00:00.000000Z');

        $this->getJson($series('2026-09-01T05%3A00%3A00Z', '2026-09-01T06%3A00%3A00Z'))
            ->assertOk()
            ->assertJsonCount(1, 'series.0.points')
            ->assertJsonPath('series.0.points.0.time', '2026-09-01T05:00:00.000000Z');
    }

    #[Test]
    public function the_csv_window_excludes_the_upper_bound(): void
    {
        [$station, $parameter] = $this->stationWithParameter();

        foreach (['04', '05', '06'] as $hour) {
            Measurement::factory()->create([
                'station_id' => $station->id,
                'parameter_id' => $parameter->id,
                'observed_at' => Carbon::parse("2026-09-01T{$hour}:00:00Z"),
                'value' => 12.5,
            ]);
        }

        $content = $this->get(
            "/api/v1/stations/{$station->id}/export.csv?parameters=PM25&aggregation=raw"
            .'&from=2026-09-01T04%3A00%3A00Z&to=2026-09-01T06%3A00%3A00Z',
        )->assertOk()->streamedContent();

        $this->assertStringContainsString('2026-09-01T04:00:00.000000Z', $content);
        $this->assertStringContainsString('2026-09-01T05:00:00.000000Z', $content);
        $this->assertStringNotContainsString('2026-09-01T06:00:00.000000Z', $content);
    }

    /**
     * @return array{Station, Parameter}
     */
    private function stationWithParameter(): array
    {
        $station = Station::factory()->create(['code' => 'API-STATION']);
        $parameter = Parameter::factory()->create(['code' => 'PM25']);
        $station->parameters()->attach($parameter);

        return [$station, $parameter];
    }
}
