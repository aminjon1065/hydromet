<?php

namespace App\Filament\Resources\Parameters;

use App\Domain\Stations\Models\Parameter;
use App\Filament\Concerns\ImportedReferenceData;
use App\Filament\Resources\Parameters\Pages\ListParameters;
use App\Filament\Resources\Parameters\Pages\ViewParameter;
use App\Filament\Resources\Parameters\Schemas\ParameterInfolist;
use App\Filament\Resources\Parameters\Tables\ParametersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only view of the parameter catalogue
 * (docs/03-data-contracts.md, section 4).
 *
 * Only `index` and `view` are registered, so no create, edit or delete route
 * exists for this resource.
 */
class ParameterResource extends Resource
{
    use ImportedReferenceData;

    protected static ?string $model = Parameter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 20;

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
        return __('stations.parameter');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stations.parameters');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'code';
    }

    public static function infolist(Schema $schema): Schema
    {
        return ParameterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ParametersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParameters::route('/'),
            'view' => ViewParameter::route('/{record}'),
        ];
    }
}
