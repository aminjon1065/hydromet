<?php

namespace Database\Factories;

use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Enums\StationType;
use App\Domain\Stations\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Test-only station rows. The generated identifiers, names and coordinates are
 * invented and must never be presented as Hydromet data.
 *
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $suffix = Str::upper(Str::random(6));

        return [
            'source' => 'test',
            'external_id' => 'test-station-'.Str::lower($suffix),
            'code' => 'TEST-'.$suffix,
            'name_tj' => 'Истгоҳи озмоишӣ '.$suffix,
            'name_ru' => 'Тестовая станция '.$suffix,
            'name_en' => 'Test station '.$suffix,
            'latitude' => 38.5,
            'longitude' => 68.7,
            'elevation_m' => 800,
            'region_code' => 'TEST-REGION',
            'district_code' => null,
            // Explicit rather than relying on the column default, so a test
            // that changes the display timezone cannot silently change data.
            'timezone' => 'Asia/Dushanbe',
            'status' => StationStatus::Active,
            'station_type' => StationType::AirQuality,
            'owner' => null,
            'installed_at' => null,
            'source_updated_at' => Carbon::parse('2026-08-31T06:00:00Z'),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StationStatus::Offline,
        ]);
    }

    public function meteorological(): static
    {
        return $this->state(fn (array $attributes): array => [
            'station_type' => StationType::Meteorological,
        ]);
    }
}
