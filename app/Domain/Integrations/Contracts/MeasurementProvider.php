<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Data\SynchronizationWindow;
use App\Domain\Measurements\Data\MeasurementBatch;
use App\Domain\Measurements\Services\MeasurementImporter;

/**
 * External supplier of observations.
 *
 * An implementation reads a provider's own format and returns canonical
 * records; it never touches the `measurements` or `measurement_revisions`
 * tables. Persistence belongs to {@see MeasurementImporter}
 * (docs/02-architecture.md, sections 4 and 5).
 *
 * Implementations must not throw for a single unreadable row: they report it in
 * the batch's rejections, so one bad row cannot discard a good batch. They may
 * throw only when the whole read failed.
 *
 * A null window requests the provider's complete configured batch (used by the
 * fixture-backed historical import). Incremental callers always pass a bounded
 * UTC window planned by the Integrations capability. A real adapter must apply
 * that range upstream rather than downloading unbounded history and filtering
 * it in the portal.
 */
interface MeasurementProvider
{
    /**
     * Provider key stored as `measurements.source`. Must match the `source` of
     * the stations the batch refers to, since a station is resolved by
     * `source` + `station_external_id`.
     */
    public function sourceKey(): string;

    /**
     * Human-readable origin shown in operator output. Must never contain
     * credentials or full URLs with query strings.
     */
    public function describeOrigin(): string;

    public function fetchMeasurements(?SynchronizationWindow $window = null): MeasurementBatch;
}
