<?php

namespace App\Filament\Resources\Users\Tables;

use App\Domain\Identity\Enums\UserRole;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The account list.
 *
 * Sorted by e-mail by default because it is unique: any other column can tie,
 * and a list that reorders itself between two identical renders is a list an
 * administrator cannot trust when they are about to change someone's access.
 *
 * No delete action and no bulk actions at all.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('identity.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('identity.fields.email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label(__('identity.fields.role'))
                    ->badge()
                    ->formatStateUsing(static fn (UserRole $state): string => $state->label())
                    ->color(static fn (UserRole $state): string => match ($state) {
                        UserRole::Administrator => 'danger',
                        UserRole::Editor => 'info',
                        UserRole::Operator => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('identity.fields.is_active'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('identity.fields.created_at'))
                    ->dateTime('d M Y, H:i', config('app.display_timezone'))
                    ->sortable(),
            ])
            ->defaultSort('email', 'asc')
            ->filters([
                SelectFilter::make('role')
                    ->label(__('identity.fields.role'))
                    ->multiple()
                    ->options(static fn (): array => self::roleOptions()),

                TernaryFilter::make('is_active')
                    ->label(__('identity.filters.is_active'))
                    ->trueLabel(__('identity.filters.only_active'))
                    ->falseLabel(__('identity.filters.only_inactive')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            // Empty on purpose: a bulk action here could only ever be a bulk
            // deletion, and accounts are deactivated one at a time, knowingly.
            ->toolbarActions([]);
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
}
