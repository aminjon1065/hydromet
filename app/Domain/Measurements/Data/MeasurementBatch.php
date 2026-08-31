<?php

namespace App\Domain\Measurements\Data;

use App\Support\Canonical\RejectedRow;

/**
 * One provider read of a measurement batch.
 *
 * The adapter reports both what it could map and what it could not: a row it
 * failed to read is carried here as an already-safe rejection rather than
 * thrown away, so the import service can report a complete `received` count.
 *
 * A whole batch is held in memory. Fixture batches are small; streaming and
 * cursor handling arrive with the incremental sync phase, not here.
 */
final readonly class MeasurementBatch
{
    /**
     * @param  list<MeasurementRecord>  $records
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
