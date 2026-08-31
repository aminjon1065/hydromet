<?php

namespace App\Filament\Resources\Stations\Tables;

use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Enums\StationType;
use App\Domain\Stations\Models\Station;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('stations.fields.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('stations.fields.name'))
                    ->state(fn (Station $record): string => $record->localizedName())
                    // Sorting and searching follow the active locale's column
                    // rather than the rendered string.
                    ->searchable(['name_tj', 'name_ru', 'name_en'])
                    ->wrap(),

                TextColumn::make('source')
                    ->label(__('stations.fields.source'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('region_code')
                    ->label(__('stations.fields.region_code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('stations.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (StationStatus $state): string => $state->label())
                    ->color(fn (StationStatus $state): string => match ($state) {
                        StationStatus::Active => 'success',
                        StationStatus::Maintenance => 'warning',
                        StationStatus::Offline => 'danger',
                        StationStatus::Decommissioned => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('station_type')
                    ->label(__('stations.fields.station_type'))
                    ->formatStateUsing(fn (StationType $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('parameters_count')
                    ->label(__('stations.fields.parameters_count'))
                    ->counts('parameters')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('source_updated_at')
                    ->label(__('stations.fields.source_updated_at'))
                    // Stored in UTC, shown in the public timezone
                    // (docs/03-data-contracts.md, section 2).
                    ->dateTime('d M Y, H:i', config('app.display_timezone'))
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('source')
                    ->label(__('stations.fields.source'))
                    ->options(fn (): array => Station::query()
                        ->distinct()
                        ->orderBy('source')
                        ->pluck('source', 'source')
                        ->all()),

                SelectFilter::make('status')
                    ->label(__('stations.fields.status'))
                    ->multiple()
                    ->options(fn (): array => self::enumOptions(StationStatus::cases())),

                SelectFilter::make('station_type')
                    ->label(__('stations.fields.station_type'))
                    ->multiple()
                    ->options(fn (): array => self::enumOptions(StationType::cases())),

                SelectFilter::make('region_code')
                    ->label(__('stations.fields.region_code'))
                    ->multiple()
                    ->options(fn (): array => Station::query()
                        ->distinct()
                        ->orderBy('region_code')
                        ->pluck('region_code', 'region_code')
                        ->all()),
            ])
            // View is the only record action: imported reference data is not
            // edited in the panel.
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * @param  array<int, StationStatus|StationType>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
