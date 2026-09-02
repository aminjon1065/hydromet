<?php

namespace App\Domain\Alerts\Data;

use App\Support\Canonical\RejectedRow;

/**
 * One provider read of a warning feed.
 *
 * The adapter reports both what it could map and what it could not: a row it
 * failed to read is carried here as an already-safe rejection rather than
 * thrown away, so the import service can report a complete `received` count and
 * one malformed warning cannot discard a good feed.
 */
final readonly class AlertBatch
{
    /**
     * @param  list<AlertRecord>  $records
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
