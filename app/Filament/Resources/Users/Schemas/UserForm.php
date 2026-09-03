<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * The create and edit form for one account.
 *
 * The password fields are write-only in the strongest sense available: nothing
 * fills them from the record, and a blank box is dehydrated away so the stored
 * hash is not overwritten with an empty string. On an edit the confirmation is
 * required only once a new password is actually typed.
 *
 * Role and activation are disabled on your own account. That is a courtesy —
 * `UserAccountManager` refuses the same two changes whether or not the field
 * arrived — but a disabled control explains the rule before someone hits it.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('identity.sections.identity'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('identity.fields.name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label(__('identity.fields.email'))
                        ->helperText(__('identity.help.email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        // Normalised here as well as in the domain service, so
                        // the uniqueness rule above compares what will be
                        // stored rather than what was typed.
                        ->dehydrateStateUsing(
                            static fn (?string $state): string => mb_strtolower(trim((string) $state)),
                        ),
                ]),

            Section::make(__('identity.sections.access'))
                ->description(__('identity.notices.provisional_roles'))
                ->columns(2)
                ->schema([
                    Select::make('role')
                        ->label(__('identity.fields.role'))
                        ->options(self::roleOptions())
                        ->default(UserRole::Editor->value)
                        ->required()
                        ->disabled(static fn (?User $record): bool => self::isOwnAccount($record))
                        // A disabled field is not submitted, and the service
                        // reads an absent field as "unchanged" — which is
                        // exactly right for your own account.
                        ->helperText(static fn (?User $record): ?string => self::isOwnAccount($record)
                            ? self::line('identity.help.own_account')
                            : null),
                    Toggle::make('is_active')
                        ->label(__('identity.fields.is_active'))
                        ->helperText(__('identity.help.is_active'))
                        ->default(true)
                        ->disabled(static fn (?User $record): bool => self::isOwnAccount($record)),
                ]),

            Section::make(__('identity.sections.credentials'))
                ->description(__('identity.notices.credentials'))
                ->columns(2)
                ->schema([
                    TextInput::make('password')
                        ->label(__('identity.fields.password'))
                        ->helperText(static fn (?User $record): string => $record === null
                            ? self::line('identity.help.password_on_create')
                            : self::line('identity.help.password_on_edit'))
                        ->password()
                        ->revealable(false)
                        ->rule(Password::defaults())
                        ->same('password_confirmation')
                        ->live(onBlur: true)
                        // Required only when creating: on an edit, empty means
                        // "keep the current password".
                        ->required(static fn (?User $record): bool => $record === null)
                        // Never read back from the record, and never written
                        // when left blank.
                        ->dehydrated(static fn (?string $state): bool => filled($state))
                        ->maxLength(255),
                    TextInput::make('password_confirmation')
                        ->label(__('identity.fields.password_confirmation'))
                        ->password()
                        ->revealable(false)
                        ->required(static fn (?User $record, Get $get): bool => $record === null
                            || filled($get('password')))
                        // Confirmation is a form-only field: it must never
                        // reach the model or the domain service.
                        ->dehydrated(false)
                        ->maxLength(255),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        $options = [];

        foreach (UserRole::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /**
     * One translated line, narrowed to a string.
     *
     * `__()` may return an array when a key resolves to a group, and these
     * closures are typed. Falling back to the key rather than an empty string
     * makes a mistyped key visible in review instead of silently blank, which
     * is the same rule AuditEventLabels follows.
     */
    private static function line(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }

    private static function isOwnAccount(?User $record): bool
    {
        return $record !== null && $record->is(auth()->user());
    }
}
