<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Data\SynchronizationWindow;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Exceptions\SynchronizationWindowUnavailable;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Plans the next bounded request and overlaps the last completed cursor so a
 * late value or source correction is fetched again.
 */
final class SynchronizationWindowPlanner
{
    public function next(
        IntegrationSource $source,
        SynchronizationKind $kind,
        DateTimeInterface $requestedTo,
        ?DateTimeInterface $bootstrapFrom = null,
    ): SynchronizationWindow {
        if ($source->cursor_strategy === 'none') {
            throw new SynchronizationWindowUnavailable(
                "Integration source '{$source->code}' has no incremental cursor strategy."
            );
        }

        $latest = SynchronizationRun::query()
            ->where('source_id', $source->id)
            ->where('kind', $kind)
            ->whereIn('status', [
                SynchronizationStatus::Succeeded,
                SynchronizationStatus::Partial,
            ])
            ->whereNotNull('cursor_to')
            ->orderByDesc('cursor_to')
            ->first();

        $minimum = $bootstrapFrom === null
            ? null
            : CarbonImmutable::instance($bootstrapFrom)->utc();

        if ($latest?->cursor_to !== null) {
            $from = $latest->cursor_to->toImmutable()->subSeconds($source->overlap_seconds);

            if ($minimum !== null && $from->isBefore($minimum)) {
                $from = $minimum;
            }
        } elseif ($minimum !== null) {
            $from = $minimum;
        } else {
            throw new SynchronizationWindowUnavailable(
                "Integration source '{$source->code}' has no completed cursor; an explicit bootstrap start is required."
            );
        }

        return new SynchronizationWindow($from, $requestedTo);
    }
}
