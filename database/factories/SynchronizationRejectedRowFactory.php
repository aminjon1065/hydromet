<?php

namespace Database\Factories;

use App\Domain\Integrations\Models\SynchronizationRejectedRow;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Support\Canonical\RejectionReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only quarantined rows. The values are what a sanitized RejectedRow looks
 * like: one printable line, a stable reason code, no payload.
 *
 * @extends Factory<SynchronizationRejectedRow>
 */
class SynchronizationRejectedRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'synchronization_run_id' => SynchronizationRun::factory()->partial(),
            'reference' => 'test:test-station-001:PM25:2026-08-31T06:00:00.000000Z:-',
            'reason_code' => RejectionReason::UnknownStation,
            'safe_detail' => 'No station is registered for source "test" with identifier "test-station-001".',
        ];
    }
}
