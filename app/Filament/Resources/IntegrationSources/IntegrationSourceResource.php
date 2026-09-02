<?php

namespace App\Filament\Resources\IntegrationSources;

use App\Domain\Integrations\Models\IntegrationSource;
use App\Filament\Concerns\ReadOnlyResource;
use App\Filament\Resources\IntegrationSources\Pages\ListIntegrationSources;
use App\Filament\Resources\IntegrationSources\Pages\ViewIntegrationSource;
use App\Filament\Resources\IntegrationSources\Schemas\IntegrationSourceInfolist;
use App\Filament\Resources\IntegrationSources\Tables\IntegrationSourcesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only operator view of integration source configuration.
 *
 * Credentials are deliberately absent from the model and from this resource.
 * Source changes will get a dedicated validated workflow when real Hydromet
 * endpoints are supplied; generic Filament mutation is not allowed.
 */
class IntegrationSourceResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = IntegrationSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('integrations.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('integrations.source');
    }

    public static function getPluralModelLabel(): string
    {
        return __('integrations.sources');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'code';
    }

    public static function infolist(Schema $schema): Schema
    {
        return IntegrationSourceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IntegrationSourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationSources::route('/'),
            'view' => ViewIntegrationSource::route('/{record}'),
        ];
    }
}
