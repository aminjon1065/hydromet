<?php

namespace App\Filament\Resources\Users;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserAccountManager;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Account administration, for active administrators only.
 *
 * Every ability below asks {@see UserAccountManager}, which is also what
 * performs the writes: hiding a button is a courtesy, not a control, so the
 * same question is answered again when a request arrives. An operator or
 * editor sees no navigation entry and is refused at the URL.
 *
 * No delete ability is granted and no delete page is registered. Accounts are
 * deactivated instead, so their audit history keeps its actor; the model and
 * the database refuse deletion outright.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 95;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('identity.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('identity.account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('identity.accounts');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'email';
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * The navigation entry follows the same answer as the pages.
     *
     * Filament registers navigation from a property by default, which would
     * leave an operator looking at a link that refuses them. Asking the same
     * question keeps the menu honest about what it can open.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return self::currentUserMayManage();
    }

    public static function canViewAny(): bool
    {
        return self::currentUserMayManage();
    }

    public static function canView(Model $record): bool
    {
        return self::currentUserMayManage();
    }

    public static function canCreate(): bool
    {
        return self::currentUserMayManage();
    }

    public static function canEdit(Model $record): bool
    {
        return self::currentUserMayManage();
    }

    // --- Deletion is closed, in every shape Filament offers ----------------

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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * The one authorization question, asked of the domain service so the panel
     * and the write path cannot drift apart.
     */
    private static function currentUserMayManage(): bool
    {
        return app(UserAccountManager::class)->allows(auth()->user());
    }
}
