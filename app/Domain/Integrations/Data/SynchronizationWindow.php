<?php

namespace App\Domain\Integrations\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Inclusive UTC interval requested from an incremental measurement provider.
 */
final readonly class SynchronizationWindow
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(DateTimeInterface $from, DateTimeInterface $to)
    {
        $normalizedFrom = CarbonImmutable::instance($from)->utc();
        $normalizedTo = CarbonImmutable::instance($to)->utc();

        if ($normalizedTo->isBefore($normalizedFrom)) {
            throw new InvalidArgumentException('A synchronization window cannot end before it starts.');
        }

        $this->from = $normalizedFrom;
        $this->to = $normalizedTo;
    }

    public function contains(DateTimeInterface $moment): bool
    {
        $candidate = CarbonImmutable::instance($moment)->utc();

        return $candidate->betweenIncluded($this->from, $this->to);
    }
}
