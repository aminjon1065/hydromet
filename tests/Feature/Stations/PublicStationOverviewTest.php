<?php

namespace Tests\Feature\Stations;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use App\Domain\Stations\Queries\PublicStationOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicStationOverviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function invalid_latest_reading_is_excluded_in_favour_of_the_latest_publishable_one(): void
    {
        $station = Station::factory()->create(['code' => 'PUBLIC-001']);
        $parameter = Parameter::factory()->create(['code' => 'PM25', 'precision' => 1]);

        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-08-31T05:00:00Z'),
            'value' => 12.3,
        ]);
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $parameter->id,
            'observed_at' => Carbon::parse('2026-08-31T06:00:00Z'),
            'original_quality' => MeasurementQuality::Invalid,
            'quality' => MeasurementQuality::Invalid,
            'value' => 999,
        ]);

        $snapshot = (new PublicStationOverview)->get();

        $this->assertCount(1, $snapshot);
        $this->assertSame(12.3, $snapshot[0]['measurements'][0]['value']);
        $this->assertSame('valid', $snapshot[0]['measurements'][0]['quality']);
        $this->assertSame('2026-08-31T05:00:00.000000Z', $snapshot[0]['observedAt']);
    }

    #[Test]
    public function missing_reading_stays_null_and_suspect_quality_stays_visible(): void
    {
        $station = Station::factory()->create();
        $missing = Parameter::factory()->create(['code' => 'RH', 'canonical_unit' => '%']);
        $suspect = Parameter::factory()->create(['code' => 'TA', 'canonical_unit' => 'degC']);

        Measurement::factory()->missing()->create([
            'station_id' => $station->id,
            'parameter_id' => $missing->id,
            'unit' => '%',
        ]);
        Measurement::factory()->create([
            'station_id' => $station->id,
            'parameter_id' => $suspect->id,
            'unit' => 'degC',
            'original_quality' => MeasurementQuality::Suspect,
            'quality' => MeasurementQuality::Suspect,
        ]);

        $measurements = (new PublicStationOverview)->get()[0]['measurements'];

        $this->assertNull($measurements[0]['value']);
        $this->assertSame('missing', $measurements[0]['quality']);
        $this->assertSame('suspect', $measurements[1]['quality']);
    }

    #[Test]
    public function decommissioned_stations_are_not_public(): void
    {
        Station::factory()->create(['status' => StationStatus::Decommissioned]);
        Station::factory()->create(['status' => StationStatus::Active]);

        $this->assertCount(1, (new PublicStationOverview)->get());
    }
}
