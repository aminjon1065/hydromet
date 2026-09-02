<?php

namespace App\Filament\Resources\AuditEvents;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Concerns\ReadOnlyResource;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Filament\Resources\AuditEvents\Schemas\AuditEventInfolist;
use App\Filament\Resources\AuditEvents\Tables\AuditEventsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AuditEventResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('audit.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('audit.event');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit.events');
    }

    public static function canViewAny(): bool
    {
        return self::currentUserIsAdministrator();
    }

    public static function canView(Model $record): bool
    {
        return self::currentUserIsAdministrator();
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'action';
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }

    private static function currentUserIsAdministrator(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role === UserRole::Administrator;
    }
}
