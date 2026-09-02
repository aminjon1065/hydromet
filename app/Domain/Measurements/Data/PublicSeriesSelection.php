<?php

namespace App\Domain\Measurements\Data;

use App\Domain\Measurements\Enums\PublicSeriesAggregation;
use App\Domain\Measurements\Enums\PublicSeriesTimezone;
use Carbon\CarbonImmutable;

final readonly class PublicSeriesSelection
{
    /**
     * @param  non-empty-list<string>  $parameters
     * @param  non-empty-list<string>  $qualities
     */
    public function __construct(
        public array $parameters,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public PublicSeriesAggregation $aggregation,
        public PublicSeriesTimezone $timezone,
        public array $qualities,
    ) {}
}
