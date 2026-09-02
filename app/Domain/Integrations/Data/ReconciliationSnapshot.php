<?php

namespace App\Domain\Integrations\Data;

/**
 * Stable totals used to reconcile one imported source with its authority.
 *
 * This deliberately contains counts and time bounds only. It is safe to show
 * to an operator and does not retain provider payloads or credentials.
 */
final readonly class ReconciliationSnapshot
{
    /**
     * @param  list<array{station_external_id: string, parameter_code: string, count: int}>  $measurementCounts
     * @param  int  $activeAlertCount  Warnings in force at the reconciled
     *                                 moment, counted with the one publication
     *                                 rule in `AlertMessage::scopeActiveAt()`.
     *                                 Unlike every other total here this one is
     *                                 time-dependent, so the moment is an
     *                                 explicit input to the reconciler rather
     *                                 than whatever the clock said.
     */
    public function __construct(
        public string $source,
        public int $stationCount,
        public int $measurementCount,
        public array $measurementCounts,
        public ?string $firstObservedAt,
        public ?string $lastObservedAt,
        public int $missingValueCount,
        public int $invalidOrSuspectCount,
        public int $revisionCount,
        public int $activeAlertCount,
    ) {}

    /**
     * @return array{
     *     source: string,
     *     station_count: int,
     *     measurement_count: int,
     *     measurement_counts: list<array{station_external_id: string, parameter_code: string, count: int}>,
     *     first_observed_at: string|null,
     *     last_observed_at: string|null,
     *     missing_value_count: int,
     *     invalid_or_suspect_count: int,
     *     revision_count: int,
     *     active_alert_count: int
     * }
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'station_count' => $this->stationCount,
            'measurement_count' => $this->measurementCount,
            'measurement_counts' => $this->measurementCounts,
            'first_observed_at' => $this->firstObservedAt,
            'last_observed_at' => $this->lastObservedAt,
            'missing_value_count' => $this->missingValueCount,
            'invalid_or_suspect_count' => $this->invalidOrSuspectCount,
            'revision_count' => $this->revisionCount,
            'active_alert_count' => $this->activeAlertCount,
        ];
    }
}
