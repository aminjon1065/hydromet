<?php

namespace App\Domain\Alerts\Enums;

/**
 * CAP 1.2 `alert.msgType`, docs/03-data-contracts.md section 7.
 *
 * The three the portal acts on are `Alert`, `Update` and `Cancel`. `Ack` and
 * `Error` are transport acknowledgements: they are accepted so a mixed feed
 * imports without loss, but they never become a public warning and they never
 * supersede anything.
 */
enum AlertMessageType: string
{
    case Alert = 'Alert';
    case Update = 'Update';
    case Cancel = 'Cancel';
    case Ack = 'Ack';
    case Error = 'Error';

    public function label(): string
    {
        return __('alerts.message_types.'.$this->value);
    }

    /**
     * Whether this message can itself be displayed as a warning.
     *
     * A `Cancel` withdraws its predecessors and is never shown in its own
     * right: showing it would put a "this warning is over" card on the map
     * exactly where the warning used to be.
     */
    public function isDisplayable(): bool
    {
        return $this === self::Alert || $this === self::Update;
    }

    /**
     * Whether this message supersedes the messages it references.
     */
    public function supersedesReferences(): bool
    {
        return $this === self::Update || $this === self::Cancel;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
