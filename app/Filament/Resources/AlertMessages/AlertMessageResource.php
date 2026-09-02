<?php

namespace App\Filament\Resources\AlertMessages;

use App\Domain\Alerts\Models\AlertMessage;
use App\Filament\Concerns\ReadOnlyResource;
use App\Filament\Resources\AlertMessages\Pages\ListAlertMessages;
use App\Filament\Resources\AlertMessages\Pages\ViewAlertMessage;
use App\Filament\Resources\AlertMessages\Schemas\AlertMessageInfolist;
use App\Filament\Resources\AlertMessages\Tables\AlertMessagesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only view of the received warning messages and their lifecycle.
 *
 * Warnings are written only by the source import. The panel exposes no action
 * that could create, edit or withdraw one: issuing or cancelling a public
 * warning is an authority decision, and the role matrix and workflow that would
 * govern it are not approved
 * (docs/08-hydromet-input-checklist.md, sections 3 and 6). Adding a generic
 * Filament form would hand that authority to anyone who can open the panel.
 *
 * Only `index` and `view` are registered, so no create, edit or delete route
 * exists for this resource.
 */
class AlertMessageResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = AlertMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 41;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('alerts.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('alerts.message');
    }

    public static function getPluralModelLabel(): string
    {
        return __('alerts.messages');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'identifier';
    }

    public static function infolist(Schema $schema): Schema
    {
        return AlertMessageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertMessagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlertMessages::route('/'),
            'view' => ViewAlertMessage::route('/{record}'),
        ];
    }
}
