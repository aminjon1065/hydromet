<?php

namespace App\Filament\Resources\SynchronizationRuns\Tables;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SynchronizationRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('integrations.fields.id'))
                    ->sortable(),

                TextColumn::make('source.code')
                    ->label(__('integrations.fields.source'))
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kind')
                    ->label(__('integrations.fields.kind'))
                    ->badge()
                    ->formatStateUsing(fn (SynchronizationKind $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('integrations.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (SynchronizationStatus $state): string => $state->label())
                    ->color(fn (SynchronizationStatus $state): string => self::statusColor($state))
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label(__('integrations.fields.started_at'))
                    ->dateTime('d M Y, H:i:s', config('app.display_timezone'))
                    ->sortable(),

                TextColumn::make('duration_in_milliseconds')
                    ->label(__('integrations.fields.duration'))
                    ->state(fn (SynchronizationRun $record): ?float => $record->durationInMilliseconds())
                    ->formatStateUsing(fn (float $state): string => number_format($state / 1000, 3).' '.__('integrations.seconds'))
                    ->placeholder(__('integrations.not_supplied')),

                TextColumn::make('received_count')
                    ->label(__('integrations.fields.received_count'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('accepted_count')
                    ->label(__('integrations.fields.accepted_count'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('rejected_count')
                    ->label(__('integrations.fields.rejected_count'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('source_id')
                    ->label(__('integrations.fields.source'))
                    ->options(fn (): array => IntegrationSource::query()
                        ->orderBy('code')
                        ->pluck('code', 'id')
                        ->all()),

                SelectFilter::make('kind')
                    ->label(__('integrations.fields.kind'))
                    ->options(self::kindOptions()),

                SelectFilter::make('status')
                    ->label(__('integrations.fields.status'))
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<string, string>
     */
    private static function kindOptions(): array
    {
        return collect(SynchronizationKind::cases())
            ->mapWithKeys(fn (SynchronizationKind $kind): array => [$kind->value => $kind->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(SynchronizationStatus::cases())
            ->mapWithKeys(fn (SynchronizationStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    private static function statusColor(SynchronizationStatus $status): string
    {
        return match ($status) {
            SynchronizationStatus::Running => 'info',
            SynchronizationStatus::Succeeded => 'success',
            SynchronizationStatus::Partial => 'warning',
            SynchronizationStatus::Failed => 'danger',
        };
    }
}
