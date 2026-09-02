<?php

namespace App\Domain\Integrations\Enums;

/**
 * The single word at the top of `/api/v1/system/status`.
 *
 * A deliberately different vocabulary from {@see SourceHealth}: one source is
 * `healthy`, the portal as a whole is `ok`. Keeping them apart stops the two
 * from being compared or assigned to each other by accident, and keeps the
 * published contract from drifting when a per-source state is added.
 *
 * Machine codes, never translated.
 */
enum SystemStatus: string
{
    /** Every tracked source is current, and nothing needs attention. */
    case Ok = 'ok';

    /** At least one source is stale, failing or has never succeeded. */
    case Degraded = 'degraded';

    /**
     * Nothing to report: no enabled source, or none with an approved staleness
     * threshold. Never a stand-in for "fine".
     */
    case Unknown = 'unknown';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
