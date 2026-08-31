<?php

namespace App\Domain\Stations\Data;

use App\Support\Canonical\RejectedRow;

/**
 * One provider read of the parameter catalogue.
 *
 * @see StationRegistryBatch for the rejection-carrying rationale.
 */
final readonly class ParameterCatalogueBatch
{
    /**
     * @param  list<ParameterRecord>  $records
     * @param  list<RejectedRow>  $rejections
     */
    public function __construct(
        public string $source,
        public array $records,
        public array $rejections = [],
    ) {}

    public function received(): int
    {
        return count($this->records) + count($this->rejections);
    }
}
