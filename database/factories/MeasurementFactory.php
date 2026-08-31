<?php

namespace Database\Factories;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Test-only observations. The values are invented and must never be presented
 * as Hydromet data.
 *
 * @extends Factory<Measurement>
 */
class MeasurementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'test',
            'source_measurement_id' => null,
            'station_id' => Station::factory(),
            'parameter_id' => Parameter::factory(),
            'sensor_no' => null,
            'observed_at' => Carbon::parse('2026-08-31T06:00:00Z'),
            'received_at' => null,
            'original_value' => 12.5,
            'original_quality' => MeasurementQuality::Valid,
            'value' => 12.5,
            'unit' => 'ug/m3',
            'averaging_period' => 'PT1H',
            'quality' => MeasurementQuality::Valid,
            'quality_flags' => [],
            'revision' => 1,
            'is_manual' => false,
            'source_updated_at' => null,
        ];
    }

    /**
     * A reading the source could not supply. Value and quality move together:
     * a missing observation never carries a number.
     */
    public function missing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'original_value' => null,
            'original_quality' => MeasurementQuality::Missing,
            'value' => null,
            'quality' => MeasurementQuality::Missing,
        ]);
    }

    public function forSensor(string $sensorNo): static
    {
        return $this->state(fn (array $attributes): array => [
            'sensor_no' => $sensorNo,
        ]);
    }
}
