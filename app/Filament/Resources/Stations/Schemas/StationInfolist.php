<?php

namespace App\Filament\Resources\Stations\Schemas;

use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Enums\StationType;
use App\Domain\Stations\Models\Station;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('stations.sections.identity'))
                ->columns(2)
                ->schema([
                    TextEntry::make('code')->label(__('stations.fields.code')),
                    TextEntry::make('name')
                        ->label(__('stations.fields.name'))
                        ->state(fn (Station $record): string => $record->localizedName()),
                    TextEntry::make('name_tj')->label(__('stations.fields.name').' (tj)'),
                    TextEntry::make('name_ru')->label(__('stations.fields.name').' (ru)'),
                    TextEntry::make('name_en')->label(__('stations.fields.name').' (en)'),
                    TextEntry::make('station_type')
                        ->label(__('stations.fields.station_type'))
                        ->formatStateUsing(fn (StationType $state): string => $state->label()),
                ]),

            Section::make(__('stations.sections.location'))
                ->columns(3)
                ->schema([
                    TextEntry::make('latitude')->label(__('stations.fields.latitude')),
                    TextEntry::make('longitude')->label(__('stations.fields.longitude')),
                    // A missing elevation is missing, never 0
                    // (docs/03-data-contracts.md, section 2).
                    TextEntry::make('elevation_m')
                        ->label(__('stations.fields.elevation_m'))
                        ->placeholder(__('stations.not_supplied')),
                    TextEntry::make('region_code')->label(__('stations.fields.region_code')),
                    TextEntry::make('district_code')
                        ->label(__('stations.fields.district_code'))
                        ->placeholder(__('stations.not_supplied')),
                    TextEntry::make('timezone')->label(__('stations.fields.timezone')),
                ]),

            Section::make(__('stations.sections.lifecycle'))
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label(__('stations.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (StationStatus $state): string => $state->label()),
                    TextEntry::make('owner')
                        ->label(__('stations.fields.owner'))
                        ->placeholder(__('stations.not_supplied')),
                    TextEntry::make('installed_at')
                        ->label(__('stations.fields.installed_at'))
                        ->date('d M Y')
                        ->placeholder(__('stations.not_supplied')),
                ]),

            Section::make(__('stations.parameters'))
                ->schema([
                    TextEntry::make('parameters.code')
                        ->label(__('stations.parameters'))
                        ->badge()
                        ->placeholder(__('stations.not_supplied')),
                ]),

            Section::make(__('stations.sections.provenance'))
                ->columns(4)
                ->description(__('stations.read_only_notice'))
                ->schema([
                    TextEntry::make('source')->label(__('stations.fields.source'))->badge(),
                    TextEntry::make('external_id')->label(__('stations.fields.external_id')),
                    TextEntry::make('source_updated_at')
                        ->label(__('stations.fields.source_updated_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                    TextEntry::make('updated_at')
                        ->label(__('stations.fields.updated_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                ]),
        ]);
    }
}
