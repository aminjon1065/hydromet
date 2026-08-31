<?php

namespace App\Domain\Measurements\Data;

use App\Support\Canonical\RejectedRow;

/**
 * Outcome of one measurement import pass.
 *
 * Counters follow docs/03-data-contracts.md section 8.2, plus two the station
 * registry does not need: `unchanged`, which is the evidence that a repeated
 * import was idempotent, and `revisionsCreated`, which is the evidence that a
 * source correction was recorded rather than silently overwritten.
 *
 * received = accepted + rejected, and accepted = created + updated + unchanged.
 */
final readonly class MeasurementImportResult
{
    /**
     * @param  list<RejectedRow>  $rejections
     */
    private function __construct(
        public int $received,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $revisionsCreated,
        public array $rejections,
    ) {}

    /**
     * @param  list<RejectedRow>  $rejections
     */
    public static function make(
        int $received,
        int $created,
        int $updated,
        int $unchanged,
        int $revisionsCreated,
        array $rejections,
    ): self {
        return new self($received, $created, $updated, $unchanged, $revisionsCreated, $rejections);
    }

    public function accepted(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }

    public function rejected(): int
    {
        return count($this->rejections);
    }

    public function isPartial(): bool
    {
        return $this->rejections !== [];
    }

    /**
     * @return array{received: int, accepted: int, created: int, updated: int, unchanged: int, rejected: int, revisions_created: int}
     */
    public function counters(): array
    {
        return [
            'received' => $this->received,
            'accepted' => $this->accepted(),
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'rejected' => $this->rejected(),
            'revisions_created' => $this->revisionsCreated,
        ];
    }
}
