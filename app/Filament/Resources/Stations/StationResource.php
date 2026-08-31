<?php

namespace App\Filament\Resources\Stations;

use App\Domain\Stations\Models\Station;
use App\Filament\Concerns\ImportedReferenceData;
use App\Filament\Resources\Stations\Pages\ListStations;
use App\Filament\Resources\Stations\Pages\ViewStation;
use App\Filament\Resources\Stations\Schemas\StationInfolist;
use App\Filament\Resources\Stations\Tables\StationsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only view of the imported station registry
 * (docs/03-data-contracts.md, section 3).
 *
 * Only `index` and `view` are registered, so no create, edit or delete route
 * exists for this resource.
 */
class StationResource extends Resource
{
    use ImportedReferenceData;

    protected static ?string $model = Station::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 10;

    /**
     * Resolved per request rather than as a static property, because a static
     * property is evaluated before the request locale is known.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('stations.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('stations.station');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stations.stations');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'code';
    }

    public static function infolist(Schema $schema): Schema
    {
        return StationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStations::route('/'),
            'view' => ViewStation::route('/{record}'),
        ];
    }
}
