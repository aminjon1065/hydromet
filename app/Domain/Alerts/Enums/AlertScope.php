<?php

namespace App\Domain\Alerts\Enums;

/**
 * CAP 1.2 `alert.scope`, docs/03-data-contracts.md section 7.
 *
 * `Restricted` and `Private` messages are addressed to named recipients, not to
 * the public. They are stored so an operator can see that they arrived, and are
 * excluded from every public read.
 */
enum AlertScope: string
{
    case Public = 'Public';
    case Restricted = 'Restricted';
    case Private = 'Private';

    public function label(): string
    {
        return __('alerts.scopes.'.$this->value);
    }

    public function isPubliclyVisible(): bool
    {
        return $this === self::Public;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
