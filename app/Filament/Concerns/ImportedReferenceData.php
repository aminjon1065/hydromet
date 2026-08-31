<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

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
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(Model $record): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }
}
