<?php

namespace Tests\Feature\Stations;

use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationDetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_publishes_localized_metadata_selected_raw_series_and_measurement_sync_time(): void
    {
        Carbon::setTestNow('2026-09-01T07:00:00Z');
        $source = IntegrationSource::factory()->create(['code' => 'fixture']);
        SynchronizationRun::factory()->measurements()->create([
            'source_id' => $source->id,
            'started_at' => Carbon::parse('2026-09-01T06:30:00Z'),
            'finished_at' => Carbon::parse('2026-09-01T06:30:02Z'),
        ]);
        // A later registry import is not the observation freshness timestamp.
        SynchronizationRun::factory()->create([
            'source_id' => $source->id,
            'started_at' => Carbon::parse('2026-09-01T06:45:00Z'),
            'finished_at' => Carbon::parse('2026-09-01T06:45:02Z'),
        ]);

        $station = Station::factory()->create([
            'source' => 'fixture',
            'code' => 'FIXTURE-DETAIL',
            'name_en' => 'Fixture detail station',
        ]);
        $pm25 = Parameter::factory()->create([
            'code' => 'PM25',
            'name_en' => 'Fine particles',
            'precision' => 1,
        ]);
        $temperature = Parameter::factory()->meteorological()->create(['code' => 'TA']);
        $inactive = Parameter::factory()->inactive()->create(['code' => 'HIDDEN']);
        $station->parameters()->attach([$pm25->id, $temperature->id, $inactive->id]);

        Measurement::factory()->create([
            'source' => 'fixture',
            'station_id' => $station->id,
            'parameter_id' => $pm25->id,
            'observed_at' => Carbon::parse('2026-09-01T06:00:00Z'),
            'original_value' => 20.1,
            'value' => 21.4,
            'original_quality' => MeasurementQuality::Valid,
            'quality' => MeasurementQuality::Corrected,
            'revision' => 2,
        ]);
        Measurement::factory()->create([
            'source' => 'fixture',
            'station_id' => $station->id,
            'parameter_id' => $temperature->id,
            'observed_at' => Carbon::parse('2026-09-01T06:15:00Z'),
            'unit' => 'degC',
            'value' => 18.2,
        ]);
        Measurement::factory()->create([
            'source' => 'fixture',
            'station_id' => $station->id,
            'parameter_id' => $pm25->id,
            'observed_at' => Carbon::parse('2026-09-01T06:30:00Z'),
            'original_quality' => MeasurementQuality::Invalid,
            'quality' => MeasurementQuality::Invalid,
            'value' => 999,
        ]);

        $this->withSession(['locale' => 'en'])
            ->get("/stations/{$station->id}?period=24h&parameters=PM25")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('stations/show')
                ->where('station.code', 'FIXTURE-DETAIL')
                ->where('station.name', 'Fixture detail station')
                ->where('station.isMock', true)
                ->where('station.lastSynchronizationAt', '2026-09-01T06:30:02.000000Z')
                ->has('station.parameters', 2)
                ->where('station.parameters.0.code', 'PM25')
                ->where('range.period', '24h')
                ->where('range.aggregation', 'raw')
                ->has('range.series', 1)
                ->where('range.series.0.parameter', 'PM25')
                ->has('range.series.0.points', 1)
                ->where('range.series.0.points.0.value', 21.4)
                ->where('range.series.0.points.0.quality', 'corrected')
                ->where('range.series.0.points.0.corrected', true)
                ->where('selectedParameters', ['PM25'])
                ->where('periods', ['24h', '7d', '30d', '1y']));
    }

    #[Test]
    public function all_standard_periods_apply_the_required_server_side_aggregation(): void
    {
        Carbon::setTestNow('2026-09-01T07:00:00Z');
        [$station, $parameter] = $this->stationWithParameter();

        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-09-01T06:05:00Z'),
            'value' => 10,
        ]);
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-09-01T06:50:00Z'),
            'original_quality' => MeasurementQuality::Suspect,
            'quality' => MeasurementQuality::Suspect,
            'value' => 20,
        ]);

        foreach ([
            '24h' => ['raw', 2, 10.0],
            '7d' => ['raw', 2, 10.0],
            '30d' => ['hour', 1, 15.0],
            '1y' => ['day', 1, 15.0],
        ] as $period => [$aggregation, $pointCount, $firstValue]) {
            $this->get("/stations/{$station->id}?period={$period}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('range.period', $period)
                    ->where('range.aggregation', $aggregation)
                    ->has('range.series.0.points', $pointCount)
                    ->where(
                        'range.series.0.points.0.value',
                        static fn (mixed $value): bool => (float) $value === $firstValue,
                    ));
        }

        $this->get("/stations/{$station->id}?period=30d")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('range.series.0.points.0.quality', 'suspect')
                ->where('range.series.0.points.0.sampleCount', 2));
    }

    #[Test]
    public function missing_values_are_null_and_invalid_values_are_never_published(): void
    {
        Carbon::setTestNow('2026-09-01T07:00:00Z');
        [$station, $parameter] = $this->stationWithParameter();

        Measurement::factory()->missing()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-09-01T05:00:00Z'),
        ]);
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-09-01T06:00:00Z'),
            'original_quality' => MeasurementQuality::Invalid,
            'quality' => MeasurementQuality::Invalid,
            'value' => 999,
        ]);

        $this->get("/stations/{$station->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('range.series.0.points', 1)
                ->where('range.series.0.points.0.value', null)
                ->where('range.series.0.points.0.quality', 'missing'));
    }

    #[Test]
    public function unknown_period_parameter_and_decommissioned_station_are_not_public(): void
    {
        [$station] = $this->stationWithParameter();

        $this->get("/stations/{$station->id}?period=forever")->assertNotFound();
        $this->get("/stations/{$station->id}?parameters=UNKNOWN")->assertNotFound();
        $this->get("/stations/{$station->id}?parameters[]=PM25")->assertNotFound();

        $station->update(['status' => StationStatus::Decommissioned]);
        $this->get("/stations/{$station->id}")->assertNotFound();
        $this->get("/stations/{$station->id}/export.csv")->assertNotFound();
    }

    #[Test]
    public function csv_streams_only_selected_publishable_rows_and_keeps_missing_values_blank(): void
    {
        Carbon::setTestNow('2026-09-01T07:00:00Z');
        [$station, $pm25] = $this->stationWithParameter();
        $temperature = Parameter::factory()->meteorological()->create(['code' => 'TA']);
        $station->parameters()->attach($temperature);

        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $pm25->id,
            'observed_at' => Carbon::parse('2026-09-01T05:00:00Z'),
            'value' => 25.9,
        ]);
        Measurement::factory()->missing()->create([
            'station_id' => $station->id,
            'parameter_id' => $pm25->id,
            'observed_at' => Carbon::parse('2026-09-01T06:00:00Z'),
        ]);
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $pm25->id,
            'observed_at' => Carbon::parse('2026-09-01T06:15:00Z'),
            'original_quality' => MeasurementQuality::Invalid,
            'quality' => MeasurementQuality::Invalid,
            'value' => 999,
        ]);
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $temperature->id,
            'observed_at' => Carbon::parse('2026-09-01T06:30:00Z'),
            'unit' => 'degC',
            'value' => 18.4,
        ]);

        $response = $this->get("/stations/{$station->id}/export.csv?period=24h&parameters=PM25")
            ->assertOk()
            ->assertDownload("station-{$station->code}-24h.csv")
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = $this->csvRows($response->streamedContent());

        $this->assertSame([
            'station_code',
            'parameter',
            'observed_at_utc',
            'value',
            'unit',
            'quality',
            'revision',
            'corrected',
        ], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame('PM25', $rows[1][1]);
        $this->assertSame('25.9', rtrim(rtrim($rows[1][3], '0'), '.'));
        $this->assertSame('', $rows[2][3]);
        $this->assertSame('missing', $rows[2][5]);
        $this->assertSame(['PM25', 'PM25'], [$rows[1][1], $rows[2][1]]);
        $this->assertNotContains('999', array_column($rows, 3));
    }

    /**
     * @return array{Station, Parameter}
     */
    private function stationWithParameter(): array
    {
        $station = Station::factory()->create(['code' => 'PUBLIC-DETAIL']);
        $parameter = Parameter::factory()->create(['code' => 'PM25']);
        $station->parameters()->attach($parameter);

        return [$station, $parameter];
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $content): array
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_map(
                static fn (?string $cell): string => $cell ?? '',
                $row,
            );
        }

        fclose($stream);

        return $rows;
    }
}
