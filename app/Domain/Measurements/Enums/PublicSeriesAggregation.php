<?php

namespace App\Domain\Measurements\Enums;

/**
 * Public aggregation levels from docs/05-api-contract.md.
 */
enum PublicSeriesAggregation: string
{
    case Raw = 'raw';
    case Hour = 'hour';
    case Day = 'day';
    case Month = 'month';

    public function maximumRangeSeconds(): int
    {
        return match ($this) {
            self::Raw => 7 * 86_400,
            self::Hour => 31 * 86_400,
            self::Day => 366 * 86_400,
            self::Month => 5 * 366 * 86_400,
        };
    }
}
