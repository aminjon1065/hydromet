<?php

namespace App\Domain\Measurements\Queries;

use App\Domain\Stations\Models\Station;
use InvalidArgumentException;

/**
 * Resolves a public comma-separated parameter filter against a station's
 * active catalogue. Request values never become column or SQL identifiers.
 */
final class PublicStationParameterSelection
{
    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function resolve(Station $station, mixed $requested): array
    {
        $available = $station->parameters()
            ->where('parameters.active', true)
            ->orderBy('parameters.code')
            ->pluck('parameters.code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->values()
            ->all();

        if ($requested === null) {
            return array_values($available);
        }

        if (! is_string($requested)) {
            throw new InvalidArgumentException('The parameters filter must be a comma-separated string.');
        }

        $selected = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $requested)),
            static fn (string $code): bool => $code !== '',
        )));

        if ($selected === [] || array_diff($selected, $available) !== []) {
            throw new InvalidArgumentException('The parameters filter contains an unavailable parameter.');
        }

        return $selected;
    }
}
