<?php

namespace App\Domain\Alerts\Enums;

/**
 * CAP 1.2 `alert.status`, docs/03-data-contracts.md section 7.
 *
 * Only `Actual` is ever shown publicly. The remaining values exist so a feed
 * that mixes exercises, system messages and drafts into one stream can be
 * imported truthfully and then filtered, rather than being silently dropped at
 * the adapter where nobody could audit the decision.
 */
enum AlertStatus: string
{
    case Actual = 'Actual';
    case Exercise = 'Exercise';
    case System = 'System';
    case Test = 'Test';
    case Draft = 'Draft';

    public function label(): string
    {
        return __('alerts.statuses.'.$this->value);
    }

    /**
     * Whether a message with this status may reach the public portal.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Actual;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
