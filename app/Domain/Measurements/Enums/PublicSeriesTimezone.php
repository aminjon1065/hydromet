<?php

namespace App\Domain\Measurements\Enums;

/**
 * Timezones allowed to define public aggregation bucket boundaries.
 */
enum PublicSeriesTimezone: string
{
    case Dushanbe = 'Asia/Dushanbe';
    case Utc = 'UTC';
}
