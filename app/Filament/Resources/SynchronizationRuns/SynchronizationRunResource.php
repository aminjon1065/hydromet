<?php

namespace App\Filament\Resources\SynchronizationRuns;

use App\Domain\Integrations\Models\SynchronizationRun;
use App\Filament\Concerns\ReadOnlyResource;
use App\Filament\Resources\SynchronizationRuns\Pages\ListSynchronizationRuns;
use App\Filament\Resources\SynchronizationRuns\Pages\ViewSynchronizationRun;
use App\Filament\Resources\SynchronizationRuns\Schemas\SynchronizationRunInfolist;
use App\Filament\Resources\SynchronizationRuns\Tables\SynchronizationRunsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only journal of station and measurement synchronization attempts.
 *
 * Runs and their rejected-row summaries are written only by the synchronization
 * service. The panel intentionally exposes no action that could rewrite this
 * operational evidence.
 */
class SynchronizationRunResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = SynchronizationRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('integrations.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('integrations.run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('integrations.runs');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'id';
    }

    public static function infolist(Schema $schema): Schema
    {
        return SynchronizationRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SynchronizationRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSynchronizationRuns::route('/'),
            'view' => ViewSynchronizationRun::route('/{record}'),
        ];
    }
}
