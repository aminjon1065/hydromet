<?php

namespace App\Domain\Integrations\Fixtures;

/**
 * Which synthetic warning feed to read.
 *
 * A closed set rather than a free-text path: the operator picks a named
 * scenario, and no argument can point the importer at an arbitrary file.
 */
enum FixtureAlertScenario: string
{
    /** The feed every other scenario builds on. */
    case Baseline = 'baseline';

    /** An Update and a Cancel against messages from the baseline feed. */
    case Lifecycle = 'lifecycle';

    public function fileName(): string
    {
        return match ($this) {
            self::Baseline => 'alerts-baseline.fixture.json',
            self::Lifecycle => 'alerts-lifecycle.fixture.json',
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Baseline => 'baseline warning feed',
            self::Lifecycle => 'update and cancel feed',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
