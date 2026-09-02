<?php

namespace App\Filament\Concerns;

/**
 * Marks a Filament resource as a read-only view of imported reference data.
 *
 * Stations and parameters are owned by their source and written only by the
 * import service. Editing them in the panel would silently diverge from the
 * source and be overwritten by the next import, so every mutating ability is
 * denied here and no create or edit page is registered.
 *
 * Viewing is allowed for every account that can open the panel at all. Panel
 * access already requires an active user in an administrative role
 * (see App\Domain\Identity\Models\User::canAccessPanel). Per-action permissions
 * arrive with the first feature that actually needs to differentiate them.
 */
trait ImportedReferenceData
{
    use ReadOnlyResource;
}
