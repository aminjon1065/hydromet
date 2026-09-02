<?php

namespace App\Filament\Resources\ContentItems\Tables;

use App\Domain\Content\Enums\ContentStatus;
use App\Domain\Content\Enums\ContentType;
use App\Domain\Content\Models\ContentItem;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContentItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('content.fields.title'))
                    ->state(fn (ContentItem $record): string => $record->localizedTitleIfPresent()
                        ?? __('content.not_supplied'))
                    ->searchable(['title_tj', 'title_ru', 'title_en'])
                    ->wrap(),
                TextColumn::make('slug')
                    ->label(__('content.fields.slug'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('content.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (ContentType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('content.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (ContentStatus $state): string => $state->label())
                    ->color(fn (ContentStatus $state): string => $state === ContentStatus::Published ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label(__('content.fields.published_at'))
                    ->dateTime('d M Y, H:i', config('app.display_timezone'))
                    ->placeholder(__('content.not_supplied'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('content.fields.updated_at'))
                    ->dateTime('d M Y, H:i', config('app.display_timezone'))
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('content.fields.type'))
                    ->options(self::typeOptions()),
                SelectFilter::make('status')
                    ->label(__('content.fields.status'))
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (ContentType::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (ContentStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
