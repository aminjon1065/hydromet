<?php

namespace App\Domain\Stations\Data;

/**
 * Outcome of one import pass over one canonical collection.
 *
 * Counters mirror docs/03-data-contracts.md section 8.2 so this object can be
 * persisted as a synchronization run without reshaping once that table exists.
 * `unchanged` is reported separately because it is the evidence that a repeated
 * import was idempotent.
 *
 * received = accepted + rejected, and accepted = created + updated + unchanged.
 */
final readonly class ImportResult
{
    /**
     * @param  list<RejectedRow>  $rejections
     */
    private function __construct(
        public int $received,
        public int $created,
        public int $updated,
        public int $unchanged,
        public array $rejections,
    ) {}

    /**
     * @param  list<RejectedRow>  $rejections
     */
    public static function make(int $received, int $created, int $updated, int $unchanged, array $rejections): self
    {
        return new self($received, $created, $updated, $unchanged, $rejections);
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
     * @return array{received: int, accepted: int, created: int, updated: int, unchanged: int, rejected: int}
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
        ];
    }
}
