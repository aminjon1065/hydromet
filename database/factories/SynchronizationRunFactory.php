<?php

namespace Database\Factories;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Test-only journal entries.
 *
 * The default is a clean finished run, because that is the state most tests
 * need as a starting point. Each state below keeps the counters and the status
 * consistent, so a factory row never violates the table's own rules.
 *
 * @extends Factory<SynchronizationRun>
 */
class SynchronizationRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = Carbon::parse('2026-09-02T06:00:00Z');

        return [
            'source_id' => IntegrationSource::factory(),
            'kind' => SynchronizationKind::StationRegistry,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addSeconds(2),
            'status' => SynchronizationStatus::Succeeded,
            'cursor_from' => null,
            'cursor_to' => null,
            'received_count' => 4,
            'accepted_count' => 4,
            'updated_count' => 0,
            'rejected_count' => 0,
            'error_code' => null,
            'sanitized_error' => null,
            'response_checksum' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SynchronizationStatus::Running,
            'finished_at' => null,
            'received_count' => 0,
            'accepted_count' => 0,
            'updated_count' => 0,
            'rejected_count' => 0,
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SynchronizationStatus::Partial,
            'received_count' => 4,
            'accepted_count' => 3,
            'rejected_count' => 1,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SynchronizationStatus::Failed,
            'received_count' => 0,
            'accepted_count' => 0,
            'updated_count' => 0,
            'rejected_count' => 0,
            'error_code' => SynchronizationRun::ERROR_UNEXPECTED,
            'sanitized_error' => 'The synchronization stopped on an unexpected error.',
        ]);
    }

    public function measurements(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => SynchronizationKind::Measurements,
        ]);
    }
}
