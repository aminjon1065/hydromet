<?php

namespace Database\Factories;

use App\Domain\Alerts\Data\AlertAreaRecord;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only affected areas. The polygons are invented rectangles and must never
 * be presented as Hydromet boundary data.
 *
 * @extends Factory<AlertArea>
 */
class AlertAreaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $geometry = [
            'type' => 'Polygon',
            'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8], [68.4, 38.3]]],
        ];
        $bbox = AlertAreaRecord::boundingBox($geometry);

        return [
            'alert_message_id' => AlertMessage::factory(),
            'description_tj' => 'Минтақаи озмоишӣ',
            'description_ru' => 'Тестовый регион',
            'description_en' => 'Test region',
            'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']],
            'geometry' => $geometry,
            'bbox_west' => $bbox['west'],
            'bbox_south' => $bbox['south'],
            'bbox_east' => $bbox['east'],
            'bbox_north' => $bbox['north'],
            'altitude_m' => null,
            'ceiling_m' => null,
        ];
    }

    /**
     * An area identified only by geocode. It cannot be drawn until Hydromet
     * supplies the administrative boundary dataset.
     */
    public function withoutGeometry(): static
    {
        return $this->state(fn (array $attributes): array => [
            'geometry' => null,
            'bbox_west' => null,
            'bbox_south' => null,
            'bbox_east' => null,
            'bbox_north' => null,
        ]);
    }

    /**
     * A rectangular area at a chosen extent, for bounding-box filter tests.
     *
     * @param  array{west: float, south: float, east: float, north: float}  $extent
     */
    public function at(array $extent): static
    {
        return $this->state(function (array $attributes) use ($extent): array {
            $geometry = [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$extent['west'], $extent['south']],
                    [$extent['east'], $extent['south']],
                    [$extent['east'], $extent['north']],
                    [$extent['west'], $extent['north']],
                    [$extent['west'], $extent['south']],
                ]],
            ];

            return [
                'geometry' => $geometry,
                'bbox_west' => $extent['west'],
                'bbox_south' => $extent['south'],
                'bbox_east' => $extent['east'],
                'bbox_north' => $extent['north'],
            ];
        });
    }
}
