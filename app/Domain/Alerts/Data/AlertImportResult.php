<?php

namespace App\Domain\Alerts\Data;

use App\Support\Canonical\RejectedRow;

/**
 * Outcome of one warning import pass.
 *
 * Counters follow docs/03-data-contracts.md section 8.2, plus two the other
 * imports do not need: `unchanged`, the evidence that a repeated import was
 * idempotent, and `superseded`, the evidence that an Update or Cancel actually
 * withdrew its predecessors rather than silently adding another warning to the
 * map.
 *
 * received = accepted + rejected, and accepted = created + updated + unchanged.
 */
final readonly class AlertImportResult
{
    /**
     * @param  list<RejectedRow>  $rejections
     */
    private function __construct(
        public int $received,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $superseded,
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
        int $superseded,
        array $rejections,
    ): self {
        return new self($received, $created, $updated, $unchanged, $superseded, $rejections);
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
     * @return array{received: int, accepted: int, created: int, updated: int, unchanged: int, rejected: int, superseded: int}
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
            'superseded' => $this->superseded,
        ];
    }
}
