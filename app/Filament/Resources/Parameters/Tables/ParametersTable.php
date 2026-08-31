<?php

namespace App\Filament\Resources\Parameters\Tables;

use App\Domain\Stations\Enums\ParameterKind;
use App\Domain\Stations\Models\Parameter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ParametersTable
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
                    ->state(fn (Parameter $record): string => $record->localizedName())
                    ->searchable(['name_tj', 'name_ru', 'name_en'])
                    ->wrap(),

                TextColumn::make('kind')
                    ->label(__('stations.fields.kind'))
                    ->badge()
                    ->formatStateUsing(fn (ParameterKind $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('canonical_unit')
                    ->label(__('stations.fields.canonical_unit')),

                TextColumn::make('precision')
                    ->label(__('stations.fields.precision'))
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('active')
                    ->label(__('stations.fields.active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('stations.fields.kind'))
                    ->multiple()
                    ->options(fn (): array => self::kindOptions()),

                TernaryFilter::make('active')
                    ->label(__('stations.fields.active')),
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
        $options = [];

        foreach (ParameterKind::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
