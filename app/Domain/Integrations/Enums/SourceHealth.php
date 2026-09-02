<?php

namespace App\Domain\Integrations\Enums;

/**
 * What the public status endpoint says about one external source.
 *
 * These are machine codes and are never translated: a client switches on them,
 * and a localized value would make the contract depend on `Accept-Language`.
 * They describe the portal's *copy* of a source, not the source itself — the
 * portal cannot see whether Hydromet is up, only whether its own last import
 * succeeded and how long ago.
 */
enum SourceHealth: string
{
    /** A recent import succeeded and nothing has failed since. */
    case Healthy = 'healthy';

    /** A recent import succeeded, but something failed after it. */
    case Degraded = 'degraded';

    /** The last success is older than the approved staleness threshold. */
    case Stale = 'stale';

    /** A threshold is approved, but no import has ever succeeded. */
    case Unavailable = 'unavailable';

    /**
     * No staleness threshold is approved for this source, so the portal has no
     * basis for calling it healthy or stale. Deliberately not a synonym for
     * "fine": an unanswered question is reported as unanswered.
     */
    case Unknown = 'unknown';

    /**
     * Whether this state is something an operator should look at.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Degraded, self::Stale, self::Unavailable => true,
            self::Healthy, self::Unknown => false,
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
