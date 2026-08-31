<?php

namespace App\Domain\Stations\Data;

/**
 * One provider read of the station registry.
 *
 * The adapter reports both what it could map and what it could not: a row it
 * failed to read is carried here as an already-safe rejection rather than
 * thrown away, so the import service can report a complete `received` count.
 *
 * A whole batch is held in memory. The registry is a few hundred rows at most;
 * streaming can be introduced when a provider justifies it.
 */
final readonly class StationRegistryBatch
{
    /**
     * @param  list<StationRecord>  $records
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
