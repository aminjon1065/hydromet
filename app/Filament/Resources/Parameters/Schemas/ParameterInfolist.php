<?php

namespace App\Filament\Resources\Parameters\Schemas;

use App\Domain\Stations\Enums\ParameterKind;
use App\Domain\Stations\Models\Parameter;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ParameterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('stations.sections.catalogue'))
                ->columns(2)
                ->description(__('stations.read_only_notice'))
                ->schema([
                    TextEntry::make('code')->label(__('stations.fields.code')),
                    TextEntry::make('name')
                        ->label(__('stations.fields.name'))
                        ->state(fn (Parameter $record): string => $record->localizedName()),
                    TextEntry::make('name_tj')->label(__('stations.fields.name').' (tj)'),
                    TextEntry::make('name_ru')->label(__('stations.fields.name').' (ru)'),
                    TextEntry::make('name_en')->label(__('stations.fields.name').' (en)'),
                    TextEntry::make('kind')
                        ->label(__('stations.fields.kind'))
                        ->badge()
                        ->formatStateUsing(fn (ParameterKind $state): string => $state->label()),
                    TextEntry::make('canonical_unit')->label(__('stations.fields.canonical_unit')),
                    TextEntry::make('precision')->label(__('stations.fields.precision')),
                    IconEntry::make('active')
                        ->label(__('stations.fields.active'))
                        ->boolean(),
                ]),

            Section::make(__('stations.sections.quality_control'))
                ->columns(3)
                // Plausibility bounds are quality-control aids, never legal
                // thresholds (docs/03-data-contracts.md, section 4).
                ->schema([
                    TextEntry::make('default_averaging_period')
                        ->label(__('stations.fields.default_averaging_period'))
                        ->placeholder(__('stations.not_supplied')),
                    TextEntry::make('plausible_min')
                        ->label(__('stations.fields.plausible_min'))
                        ->placeholder(__('stations.not_supplied')),
                    TextEntry::make('plausible_max')
                        ->label(__('stations.fields.plausible_max'))
                        ->placeholder(__('stations.not_supplied')),
                ]),

            Section::make(__('stations.sections.provenance'))
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label(__('stations.fields.imported_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                    TextEntry::make('updated_at')
                        ->label(__('stations.fields.updated_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                ]),
        ]);
    }
}
