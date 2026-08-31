<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Stations\Data\ParameterCatalogueBatch;
use App\Domain\Stations\Data\StationRegistryBatch;
use App\Domain\Stations\Services\StationRegistryImporter;

/**
 * External supplier of the station registry.
 *
 * This is the only volatile edge the Stations capability is allowed to depend
 * on (docs/02-architecture.md, sections 4 and 5). An implementation reads a
 * provider's own format and returns canonical records; it never touches the
 * `stations`, `parameters` or `station_parameter` tables. Persistence belongs
 * to {@see StationRegistryImporter}.
 *
 * The catalogue is part of this contract because a station registry is
 * meaningless without the units and precision of the parameter codes it
 * references, and the portal must never infer them
 * (docs/03-data-contracts.md, section 4).
 *
 * Implementations must not throw for a single unreadable row: they report it in
 * the batch's rejections so one bad row cannot discard a good registry. They
 * may throw only when the whole read failed.
 */
interface StationRegistryProvider
{
    /**
     * Provider key stored as `stations.source`, for example `hydromet`.
     */
    public function sourceKey(): string;

    /**
     * Human-readable origin shown in operator output. Must never contain
     * credentials or full URLs with query strings.
     */
    public function describeOrigin(): string;

    public function fetchParameterCatalogue(): ParameterCatalogueBatch;

    public function fetchStationRegistry(): StationRegistryBatch;
}
