<?php

namespace App\Filament\Resources\IntegrationSources\Tables;

use App\Domain\Integrations\Models\IntegrationSource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class IntegrationSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('integrations.fields.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('integrations.fields.type'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('producer')
                    ->label(__('integrations.fields.producer'))
                    ->placeholder(__('integrations.not_supplied'))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('enabled')
                    ->label(__('integrations.fields.enabled'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('timezone')
                    ->label(__('integrations.fields.timezone'))
                    ->sortable(),

                TextColumn::make('synchronization_runs_count')
                    ->label(__('integrations.fields.runs_count'))
                    ->counts('synchronizationRuns')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label(__('integrations.fields.updated_at'))
                    ->dateTime('d M Y, H:i', config('app.display_timezone'))
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('integrations.fields.type'))
                    ->options(fn (): array => IntegrationSource::query()
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->all()),

                SelectFilter::make('enabled')
                    ->label(__('integrations.fields.enabled'))
                    ->options([
                        '1' => __('integrations.yes'),
                        '0' => __('integrations.no'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
