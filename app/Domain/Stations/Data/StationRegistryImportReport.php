<?php

namespace App\Domain\Stations\Data;

use App\Support\Canonical\RejectedRow;

/**
 * Combined outcome of one registry import: the catalogue pass and the station
 * pass. Held in memory only — `synchronization_runs` is a later phase
 * (docs/03-data-contracts.md, section 8.2).
 */
final readonly class StationRegistryImportReport
{
    public function __construct(
        public string $source,
        public ImportResult $parameters,
        public ImportResult $stations,
    ) {}

    public function isPartial(): bool
    {
        return $this->parameters->isPartial() || $this->stations->isPartial();
    }

    /**
     * @return list<RejectedRow>
     */
    public function rejections(): array
    {
        return [...$this->parameters->rejections, ...$this->stations->rejections];
    }
}
