<?php

namespace App\Domain\Measurements\Enums;

use Carbon\CarbonImmutable;

/**
 * Fixed public chart periods from docs/01-product-scope.md, section 3.1.
 */
enum PublicSeriesPeriod: string
{
    case Hours24 = '24h';
    case Days7 = '7d';
    case Days30 = '30d';
    case Year1 = '1y';

    public function startsAt(CarbonImmutable $to): CarbonImmutable
    {
        return match ($this) {
            self::Hours24 => $to->subHours(24),
            self::Days7 => $to->subDays(7),
            self::Days30 => $to->subDays(30),
            self::Year1 => $to->subYear(),
        };
    }

    public function aggregation(): PublicSeriesAggregation
    {
        return match ($this) {
            self::Hours24, self::Days7 => PublicSeriesAggregation::Raw,
            self::Days30 => PublicSeriesAggregation::Hour,
            self::Year1 => PublicSeriesAggregation::Day,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
