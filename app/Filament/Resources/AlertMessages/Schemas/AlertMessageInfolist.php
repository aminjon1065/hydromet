<?php

namespace App\Filament\Resources\AlertMessages\Schemas;

use App\Domain\Alerts\Enums\AlertCertainty;
use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Enums\AlertUrgency;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AlertMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('alerts.sections.identity'))
                ->columns(3)
                ->description(__('alerts.read_only_notice'))
                ->schema([
                    TextEntry::make('identifier')->label(__('alerts.fields.identifier')),
                    TextEntry::make('source')->label(__('alerts.fields.source'))->badge(),
                    TextEntry::make('sender')->label(__('alerts.fields.sender')),
                ]),

            Section::make(__('alerts.sections.classification'))
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label(__('alerts.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (AlertStatus $state): string => $state->label()),
                    TextEntry::make('message_type')
                        ->label(__('alerts.fields.message_type'))
                        ->badge()
                        ->formatStateUsing(fn (AlertMessageType $state): string => $state->label()),
                    TextEntry::make('scope')
                        ->label(__('alerts.fields.scope'))
                        ->badge()
                        ->formatStateUsing(fn (AlertScope $state): string => $state->label()),
                    TextEntry::make('event_code')->label(__('alerts.fields.event_code')),
                    TextEntry::make('severity')
                        ->label(__('alerts.fields.severity'))
                        ->badge()
                        ->formatStateUsing(fn (AlertSeverity $state): string => $state->label()),
                    TextEntry::make('urgency')
                        ->label(__('alerts.fields.urgency'))
                        ->formatStateUsing(fn (AlertUrgency $state): string => $state->label()),
                    TextEntry::make('certainty')
                        ->label(__('alerts.fields.certainty'))
                        ->formatStateUsing(fn (AlertCertainty $state): string => $state->label()),
                    TextEntry::make('categories')
                        ->label(__('alerts.fields.categories'))
                        ->state(fn (AlertMessage $record): string => implode(', ', $record->categories))
                        ->placeholder(__('alerts.not_supplied')),
                    TextEntry::make('references')
                        ->label(__('alerts.fields.references'))
                        ->state(fn (AlertMessage $record): string => implode(', ', $record->references))
                        ->placeholder(__('alerts.not_supplied')),
                ]),

            Section::make(__('alerts.sections.validity'))
                ->columns(4)
                ->schema([
                    TextEntry::make('sent_at')
                        ->label(__('alerts.fields.sent_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone')),
                    TextEntry::make('effective_at')
                        ->label(__('alerts.fields.effective_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone'))
                        ->placeholder(__('alerts.not_supplied')),
                    TextEntry::make('onset_at')
                        ->label(__('alerts.fields.onset_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone'))
                        ->placeholder(__('alerts.not_supplied')),
                    TextEntry::make('expires_at')
                        ->label(__('alerts.fields.expires_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone')),
                ]),

            // Every language is shown side by side: an operator checking a
            // warning has to be able to see that all three were supplied, which
            // is exactly what a fallback would hide.
            Section::make(__('alerts.sections.content'))
                ->columns(1)
                ->schema([
                    TextEntry::make('headline_tj')->label(__('alerts.fields.headline').' (tj)'),
                    TextEntry::make('headline_ru')->label(__('alerts.fields.headline').' (ru)'),
                    TextEntry::make('headline_en')->label(__('alerts.fields.headline').' (en)'),
                    TextEntry::make('description_tj')->label(__('alerts.fields.description').' (tj)'),
                    TextEntry::make('description_ru')->label(__('alerts.fields.description').' (ru)'),
                    TextEntry::make('description_en')->label(__('alerts.fields.description').' (en)'),
                    TextEntry::make('instruction_tj')
                        ->label(__('alerts.fields.instruction').' (tj)')
                        ->placeholder(__('alerts.not_supplied')),
                    TextEntry::make('instruction_ru')
                        ->label(__('alerts.fields.instruction').' (ru)')
                        ->placeholder(__('alerts.not_supplied')),
                    TextEntry::make('instruction_en')
                        ->label(__('alerts.fields.instruction').' (en)')
                        ->placeholder(__('alerts.not_supplied')),
                ]),

            Section::make(__('alerts.sections.areas'))
                ->columns(1)
                ->schema([
                    TextEntry::make('areas')
                        ->label(__('alerts.fields.areas'))
                        ->state(fn (AlertMessage $record): string => $record->areas
                            ->map(static fn (AlertArea $area): string => $area->description_en
                                .' ['.($area->isDrawable() ? ($area->geometry['type'] ?? '?') : 'geocode only').']')
                            ->implode(' · '))
                        ->placeholder(__('alerts.not_supplied')),
                    TextEntry::make('geocodes')
                        ->label(__('alerts.fields.geocodes'))
                        ->state(fn (AlertMessage $record): string => $record->areas
                            ->flatMap(static fn (AlertArea $area): array => array_map(
                                static fn (array $geocode): string => $geocode['name'].'='.$geocode['value'],
                                $area->geocodes,
                            ))
                            ->implode(', '))
                        ->placeholder(__('alerts.not_supplied')),
                ]),

            Section::make(__('alerts.sections.provenance'))
                ->columns(3)
                ->schema([
                    TextEntry::make('superseded_at')
                        ->label(__('alerts.fields.superseded_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone'))
                        ->placeholder(__('alerts.not_supplied')),
                    TextEntry::make('supersededBy.identifier')
                        ->label(__('alerts.fields.superseded_by'))
                        ->placeholder(__('alerts.not_supplied')),
                    TextEntry::make('imported_at')
                        ->label(__('alerts.fields.imported_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone')),
                ]),
        ]);
    }
}
