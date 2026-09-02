<?php

namespace Database\Factories;

use App\Domain\Integrations\Models\IntegrationSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only source configuration. Nothing here is a credential, and nothing
 * here describes a real Hydromet endpoint.
 *
 * @extends Factory<IntegrationSource>
 */
class IntegrationSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'test-'.Str::lower(Str::random(8)),
            'type' => 'fixture',
            'base_url' => null,
            'authentication_type' => 'none',
            'producer' => null,
            'timezone' => 'UTC',
            'enabled' => true,
            'polling_interval_seconds' => null,
            // Null by default, exactly like a source Hydromet has not yet ruled
            // on: the status endpoint must report it as unknown, not healthy.
            'stale_after_seconds' => null,
            'timeout_seconds' => 30,
            'cursor_strategy' => 'none',
            'overlap_seconds' => 0,
            'parameter_mapping' => [],
            'unit_mapping' => [],
        ];
    }

    /**
     * A source reached over HTTP. The URL carries no query string and no
     * userinfo, because a stored URL must never hold a credential.
     */
    public function http(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'http_json',
            'base_url' => 'https://example.test/observations',
            'authentication_type' => 'api_key',
            'producer' => 'test-producer',
            'polling_interval_seconds' => 900,
            'cursor_strategy' => 'observed_at',
            'overlap_seconds' => 300,
            'parameter_mapping' => ['PM_2_5' => 'PM25'],
            'unit_mapping' => ['mkg/m3' => 'ug/m3'],
        ]);
    }

    /**
     * A source with an approved staleness rule, for the cases that need one.
     */
    public function staleAfter(int $seconds): static
    {
        return $this->state(fn (array $attributes): array => [
            'stale_after_seconds' => $seconds,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }
}
