<?php

namespace App\Filament\Resources\SynchronizationRuns\Schemas;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Support\Canonical\RejectionReason;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SynchronizationRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('integrations.sections.summary'))
                ->description(__('integrations.read_only_notice'))
                ->columns(3)
                ->schema([
                    TextEntry::make('id')->label(__('integrations.fields.id')),
                    TextEntry::make('source.code')
                        ->label(__('integrations.fields.source'))
                        ->badge(),
                    TextEntry::make('kind')
                        ->label(__('integrations.fields.kind'))
                        ->badge()
                        ->formatStateUsing(fn (SynchronizationKind $state): string => $state->label()),
                    TextEntry::make('status')
                        ->label(__('integrations.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (SynchronizationStatus $state): string => $state->label())
                        ->color(fn (SynchronizationStatus $state): string => self::statusColor($state)),
                    TextEntry::make('started_at')
                        ->label(__('integrations.fields.started_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone')),
                    TextEntry::make('finished_at')
                        ->label(__('integrations.fields.finished_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone'))
                        ->placeholder(__('integrations.not_supplied')),
                    TextEntry::make('duration_in_milliseconds')
                        ->label(__('integrations.fields.duration'))
                        ->state(fn (SynchronizationRun $record): ?float => $record->durationInMilliseconds())
                        ->formatStateUsing(fn (float $state): string => number_format($state / 1000, 3).' '.__('integrations.seconds'))
                        ->placeholder(__('integrations.not_supplied')),
                ]),

            Section::make(__('integrations.sections.counters'))
                ->columns(4)
                ->schema([
                    TextEntry::make('received_count')->label(__('integrations.fields.received_count'))->numeric(),
                    TextEntry::make('accepted_count')->label(__('integrations.fields.accepted_count'))->numeric(),
                    TextEntry::make('updated_count')->label(__('integrations.fields.updated_count'))->numeric(),
                    TextEntry::make('rejected_count')->label(__('integrations.fields.rejected_count'))->numeric(),
                ]),

            Section::make(__('integrations.sections.cursor'))
                ->columns(3)
                ->schema([
                    TextEntry::make('cursor_from')
                        ->label(__('integrations.fields.cursor_from'))
                        ->dateTime('d M Y, H:i:s', 'UTC')
                        ->placeholder(__('integrations.not_supplied')),
                    TextEntry::make('cursor_to')
                        ->label(__('integrations.fields.cursor_to'))
                        ->dateTime('d M Y, H:i:s', 'UTC')
                        ->placeholder(__('integrations.not_supplied')),
                    TextEntry::make('response_checksum')
                        ->label(__('integrations.fields.response_checksum'))
                        ->copyable()
                        ->placeholder(__('integrations.not_supplied')),
                ]),

            Section::make(__('integrations.sections.failure'))
                ->columns(2)
                ->schema([
                    TextEntry::make('error_code')
                        ->label(__('integrations.fields.error_code'))
                        ->placeholder(__('integrations.not_supplied')),
                    TextEntry::make('sanitized_error')
                        ->label(__('integrations.fields.sanitized_error'))
                        ->placeholder(__('integrations.not_supplied')),
                ]),

            Section::make(__('integrations.sections.rejected_rows'))
                ->schema([
                    RepeatableEntry::make('rejectedRows')
                        ->label('')
                        ->schema([
                            TextEntry::make('reference')
                                ->label(__('integrations.fields.reference')),
                            TextEntry::make('reason_code')
                                ->label(__('integrations.fields.reason_code'))
                                ->badge()
                                ->formatStateUsing(fn (RejectionReason $state): string => $state->value),
                            TextEntry::make('safe_detail')
                                ->label(__('integrations.fields.safe_detail')),
                        ])
                        ->columns(3)
                        ->placeholder(__('integrations.no_rejections')),
                ]),
        ]);
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
