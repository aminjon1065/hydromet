<?php

namespace App\Domain\Measurements\Queries;

use App\Domain\Measurements\Data\PublicSeriesSelection;
use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Enums\PublicSeriesAggregation;
use App\Domain\Measurements\Enums\PublicSeriesTimezone;
use App\Domain\Stations\Models\Station;
use App\Http\Api\ApiProblem;
use App\Support\Canonical\CanonicalReader;
use App\Support\Canonical\InvalidCanonicalRow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Strict parser and range-policy gate for the public series/CSV endpoints.
 */
final readonly class PublicSeriesSelectionFactory
{
    public function __construct(private PublicStationParameterSelection $parameterSelection) {}

    public function fromRequest(Request $request, Station $station): PublicSeriesSelection
    {
        try {
            $parameters = $this->parameterSelection->resolve(
                $station,
                $this->requiredString($request, 'parameters'),
            );
        } catch (InvalidArgumentException) {
            throw new ApiProblem(
                422,
                'invalid_parameter',
                'The parameters filter contains an unavailable station parameter.',
                ['field' => 'parameters'],
            );
        }

        if ($parameters === []) {
            throw new ApiProblem(
                422,
                'invalid_parameter',
                'At least one active station parameter is required.',
                ['field' => 'parameters'],
            );
        }

        $aggregation = PublicSeriesAggregation::tryFrom($this->requiredString($request, 'aggregation'));

        if ($aggregation === null) {
            throw new ApiProblem(
                422,
                'invalid_aggregation',
                'The aggregation must be raw, hour, day or month.',
                ['field' => 'aggregation'],
            );
        }

        $timezoneValue = $request->query('timezone', PublicSeriesTimezone::Dushanbe->value);
        $timezone = is_string($timezoneValue) ? PublicSeriesTimezone::tryFrom($timezoneValue) : null;

        if ($timezone === null) {
            throw new ApiProblem(
                422,
                'invalid_timezone',
                'The timezone must be Asia/Dushanbe or UTC.',
                ['field' => 'timezone'],
            );
        }

        $from = $this->dateTime($request, 'from');
        $to = $this->dateTime($request, 'to');
        $seconds = $to->getTimestamp() - $from->getTimestamp();

        if ($seconds <= 0 || $seconds > $aggregation->maximumRangeSeconds()) {
            throw new ApiProblem(
                422,
                'invalid_time_range',
                'The selected time range is not supported for this aggregation.',
                [
                    'aggregation' => $aggregation->value,
                    'maximum_seconds' => $aggregation->maximumRangeSeconds(),
                ],
            );
        }

        $qualities = $this->qualities($request->query('quality'));

        return new PublicSeriesSelection(
            $parameters,
            $from,
            $to,
            $aggregation,
            $timezone,
            $qualities,
        );
    }

    private function requiredString(Request $request, string $field): string
    {
        $value = $request->query($field);

        if (! is_string($value) || trim($value) === '') {
            throw new ApiProblem(
                422,
                'missing_query_parameter',
                "The {$field} query parameter is required.",
                ['field' => $field],
            );
        }

        return trim($value);
    }

    private function dateTime(Request $request, string $field): CarbonImmutable
    {
        $value = $this->requiredString($request, $field);

        try {
            return CarbonImmutable::instance((new CanonicalReader([$field => $value]))->dateTime($field));
        } catch (InvalidCanonicalRow) {
            throw new ApiProblem(
                422,
                'invalid_datetime',
                "The {$field} query parameter must be an ISO 8601 timestamp with an explicit timezone.",
                ['field' => $field],
            );
        }
    }

    /**
     * @return non-empty-list<string>
     */
    private function qualities(mixed $requested): array
    {
        if ($requested === null) {
            return [MeasurementQuality::Valid->value, MeasurementQuality::Corrected->value];
        }

        if (! is_string($requested) || trim($requested) === '') {
            throw new ApiProblem(
                422,
                'invalid_quality_filter',
                'The quality filter must be a comma-separated list.',
                ['field' => 'quality'],
            );
        }

        if (trim($requested) === 'all') {
            throw new ApiProblem(
                403,
                'quality_filter_forbidden',
                'The all-quality view is restricted to authorized operators.',
            );
        }

        $qualities = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $requested)),
            static fn (string $quality): bool => $quality !== '',
        )));
        $public = [
            MeasurementQuality::Valid->value,
            MeasurementQuality::Corrected->value,
            MeasurementQuality::Suspect->value,
            MeasurementQuality::Missing->value,
        ];

        if ($qualities === [] || array_diff($qualities, $public) !== []) {
            throw new ApiProblem(
                422,
                'invalid_quality_filter',
                'The quality filter contains an unsupported public quality.',
                ['field' => 'quality'],
            );
        }

        return $qualities;
    }
}
