<?php

namespace App\Domain\Integrations\Fixtures;

use App\Domain\Integrations\Models\IntegrationSource;

/**
 * The `integration_sources` row the fixture providers synchronize under.
 *
 * A synchronization run belongs to a source, so the fixture commands need one
 * before they can journal anything. It is provisioned on demand rather than
 * seeded, so a developer who only ever runs the fixture commands never has to
 * remember a separate seeding step, and re-running is a no-op.
 *
 * Its configuration is deliberately inert: disabled, no base URL, no
 * authentication, no polling interval and no cursor strategy, because a
 * checked-in JSON file has none of those things. That also means the row cannot
 * be mistaken for a real Hydromet source if one is added later under its own
 * code, and it cannot be picked up by the scheduler that a later phase adds.
 *
 * The row is created once and never rewritten afterwards, so an operator who
 * edits it keeps their change.
 */
final class FixtureIntegrationSource
{
    public static function ensure(): IntegrationSource
    {
        return IntegrationSource::query()->firstOrCreate(
            ['code' => FixtureStationRegistryProvider::SOURCE_KEY],
            [
                'type' => 'fixture',
                'base_url' => null,
                'authentication_type' => 'none',
                'producer' => null,
                'timezone' => 'UTC',
                // Never enabled. `enabled` is what the scheduler phase will
                // read to decide what to poll, and a source backed by a
                // checked-in JSON file must never be picked up automatically.
                // The fixture commands do not consult the flag: they are
                // explicit, manual operator actions and keep working regardless.
                'enabled' => false,
                'polling_interval_seconds' => null,
                'timeout_seconds' => 30,
                'cursor_strategy' => 'none',
                'overlap_seconds' => 0,
                'parameter_mapping' => [],
                'unit_mapping' => [],
            ],
        );
    }
}
