<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\Identity\Enums\UserRole;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What an administrator may read about an account.
 *
 * Deliberately five fields. `password`, `remember_token`, `email_verified_at`
 * and anything session- or reset-token-shaped are absent: none of them helps
 * an administrator decide anything, and each of them is a credential or a way
 * to impersonate the person.
 */
class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('identity.sections.identity'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('identity.fields.name')),
                    TextEntry::make('email')->label(__('identity.fields.email'))->copyable(),
                ]),

            Section::make(__('identity.sections.access'))
                ->description(__('identity.notices.provisional_roles'))
                ->columns(2)
                ->schema([
                    TextEntry::make('role')
                        ->label(__('identity.fields.role'))
                        ->badge()
                        ->formatStateUsing(static fn (UserRole $state): string => $state->label()),
                    IconEntry::make('is_active')
                        ->label(__('identity.fields.is_active'))
                        ->boolean(),
                ]),

            Section::make(__('identity.sections.provenance'))
                ->description(__('identity.notices.no_deletion'))
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label(__('identity.fields.created_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                    TextEntry::make('updated_at')
                        ->label(__('identity.fields.updated_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                ]),
        ]);
    }
}
