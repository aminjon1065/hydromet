<?php

namespace App\Domain\Integrations\Data;

use App\Domain\Integrations\Exceptions\InvalidSynchronizationOutcome;
use App\Domain\Measurements\Data\MeasurementImportResult;
use App\Domain\Stations\Data\StationRegistryImportReport;
use App\Support\Canonical\RejectedRow;

/**
 * What an import service reported, reduced to the counters
 * docs/03-data-contracts.md section 8.2 stores.
 *
 * Each capability's own result object keeps the detail it needs — the station
 * registry reports two collections, measurements report revisions created —
 * and this is the shape they have in common. Converting here rather than in the
 * runner keeps the runner free of any knowledge of what it was importing.
 */
final readonly class SynchronizationOutcome
{
    /**
     * Every construction path runs through here, so an outcome that reaches the
     * runner is already coherent and the same rules hold on every driver.
     *
     * These are the invariants the `synchronization_runs` CHECK constraints
     * encode. Enforcing them here as well is not duplication for its own sake:
     * SQLite cannot carry those constraints, so without this a miscounted
     * import would be caught only on PostgreSQL, and only after the run row had
     * been written.
     *
     * @param  list<RejectedRow>  $rejections
     *
     * @throws InvalidSynchronizationOutcome
     */
    private function __construct(
        public int $received,
        public int $accepted,
        public int $updated,
        public array $rejections,
        public ?string $responseChecksum = null,
    ) {
        $rejected = count($rejections);

        if ($received < 0 || $accepted < 0 || $updated < 0) {
            throw new InvalidSynchronizationOutcome(
                'Synchronization counters cannot be negative: '
                ."received {$received}, accepted {$accepted}, updated {$updated}."
            );
        }

        // Every row a provider sent was either stored or quarantined. A gap
        // means the import lost track of a row, which is worse than a rejection
        // because nothing would report it.
        if ($received !== $accepted + $rejected) {
            throw new InvalidSynchronizationOutcome(
                "Synchronization counters do not add up: received {$received}, "
                ."accepted {$accepted} plus rejected {$rejected}."
            );
        }

        if ($updated > $accepted) {
            throw new InvalidSynchronizationOutcome(
                "More rows were updated than accepted: updated {$updated}, accepted {$accepted}."
            );
        }
    }

    /**
     * @param  list<RejectedRow>  $rejections
     */
    public static function make(
        int $received,
        int $accepted,
        int $updated,
        array $rejections,
        ?string $responseChecksum = null,
    ): self {
        return new self($received, $accepted, $updated, $rejections, $responseChecksum);
    }

    /**
     * The registry import runs two passes over one provider read, so the run
     * journals their sum.
     */
    public static function fromStationRegistry(StationRegistryImportReport $report): self
    {
        return new self(
            received: $report->parameters->received + $report->stations->received,
            accepted: $report->parameters->accepted() + $report->stations->accepted(),
            updated: $report->parameters->updated + $report->stations->updated,
            rejections: $report->rejections(),
        );
    }

    public static function fromMeasurements(MeasurementImportResult $result): self
    {
        return new self(
            received: $result->received,
            accepted: $result->accepted(),
            updated: $result->updated,
            rejections: $result->rejections,
        );
    }

    public function rejected(): int
    {
        return count($this->rejections);
    }

    public function isPartial(): bool
    {
        return $this->rejections !== [];
    }
}
