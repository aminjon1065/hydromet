<?php

namespace App\Filament\Resources\IntegrationSources\Schemas;

use App\Domain\Integrations\Models\IntegrationSource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IntegrationSourceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('integrations.sections.identity'))
                ->columns(3)
                ->schema([
                    TextEntry::make('code')->label(__('integrations.fields.code'))->badge(),
                    TextEntry::make('type')->label(__('integrations.fields.type')),
                    TextEntry::make('producer')
                        ->label(__('integrations.fields.producer'))
                        ->placeholder(__('integrations.not_supplied')),
                    IconEntry::make('enabled')
                        ->label(__('integrations.fields.enabled'))
                        ->boolean(),
                    TextEntry::make('timezone')->label(__('integrations.fields.timezone')),
                    TextEntry::make('base_url')
                        ->label(__('integrations.fields.base_url'))
                        ->placeholder(__('integrations.not_supplied')),
                ]),

            Section::make(__('integrations.sections.polling'))
                ->columns(3)
                ->schema([
                    TextEntry::make('authentication_type')->label(__('integrations.fields.authentication_type')),
                    TextEntry::make('polling_interval_seconds')
                        ->label(__('integrations.fields.polling_interval_seconds'))
                        ->placeholder(__('integrations.not_supplied')),
                    TextEntry::make('timeout_seconds')->label(__('integrations.fields.timeout_seconds')),
                    TextEntry::make('cursor_strategy')->label(__('integrations.fields.cursor_strategy')),
                    TextEntry::make('overlap_seconds')->label(__('integrations.fields.overlap_seconds')),
                    TextEntry::make('synchronization_runs_count')
                        ->label(__('integrations.fields.runs_count'))
                        ->state(fn (IntegrationSource $record): int => $record->synchronizationRuns()->count()),
                ]),

            Section::make(__('integrations.sections.mappings'))
                ->columns(2)
                ->schema([
                    KeyValueEntry::make('parameter_mapping')
                        ->label(__('integrations.fields.parameter_mapping'))
                        ->keyLabel(__('integrations.fields.provider_value'))
                        ->valueLabel(__('integrations.fields.canonical_value')),
                    KeyValueEntry::make('unit_mapping')
                        ->label(__('integrations.fields.unit_mapping'))
                        ->keyLabel(__('integrations.fields.provider_value'))
                        ->valueLabel(__('integrations.fields.canonical_value')),
                ]),

            Section::make(__('integrations.sections.provenance'))
                ->description(__('integrations.read_only_notice'))
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label(__('integrations.fields.created_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                    TextEntry::make('updated_at')
                        ->label(__('integrations.fields.updated_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                ]),
        ]);
    }
}
