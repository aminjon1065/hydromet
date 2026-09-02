<?php

namespace App\Filament\Resources\AuditEvents\Tables;

use App\Domain\Audit\Models\AuditEvent;
use App\Filament\Resources\AuditEvents\AuditEventLabels;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('audit.fields.occurred_at'))
                    ->dateTime('d M Y, H:i:s', config('app.display_timezone'))
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label(__('audit.fields.actor'))
                    ->placeholder(__('audit.system_actor'))
                    ->searchable(),
                TextColumn::make('action')
                    ->label(__('audit.fields.action'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AuditEventLabels::action($state))
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label(__('audit.fields.subject_type'))
                    ->formatStateUsing(fn (string $state): string => AuditEventLabels::subjectType($state))
                    ->sortable(),
                TextColumn::make('subject_label')
                    ->label(__('audit.fields.subject'))
                    ->placeholder(fn (AuditEvent $record): string => $record->subject_id)
                    ->searchable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            // The options come from what the log actually holds, so an action
            // recorded by a capability added later stays filterable.
            ->filters([
                SelectFilter::make('action')
                    ->label(__('audit.fields.action'))
                    ->options(AuditEventLabels::actionOptions(...)),
                SelectFilter::make('subject_type')
                    ->label(__('audit.fields.subject_type'))
                    ->options(AuditEventLabels::subjectTypeOptions(...)),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
