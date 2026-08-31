<?php

namespace App\Domain\Integrations\Enums;

/**
 * Lifecycle of one synchronization run, docs/03-data-contracts.md section 8.2.
 *
 * The three closing statuses answer different operator questions:
 *   - `Succeeded`  everything the provider sent was stored;
 *   - `Partial`    the run finished and some rows were quarantined, so the data
 *                  is usable but incomplete;
 *   - `Failed`     the run did not finish. Rows written before it stopped are
 *                  still stored, because each is written on its own.
 */
enum SynchronizationStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return __('integrations.statuses.'.$this->value);
    }

    public function isFinished(): bool
    {
        return $this !== self::Running;
    }

    /**
     * Whether the run stored everything it was given.
     */
    public function isClean(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
