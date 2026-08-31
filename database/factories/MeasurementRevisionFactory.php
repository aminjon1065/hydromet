<?php

namespace Database\Factories;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Enums\RevisionOrigin;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only revision history.
 *
 * @extends Factory<MeasurementRevision>
 */
class MeasurementRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'measurement_id' => Measurement::factory(),
            'revision' => 2,
            'previous_value' => 12.5,
            'previous_quality' => MeasurementQuality::Valid,
            'corrected_value' => 13.75,
            'corrected_quality' => MeasurementQuality::Corrected,
            'reason_code' => MeasurementRevision::REASON_SOURCE_REVISION,
            'reason_text' => null,
            'change_origin' => RevisionOrigin::Source,
            'changed_by' => null,
            'source_updated_at' => null,
        ];
    }
}
