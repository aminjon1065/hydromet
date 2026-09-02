<?php

namespace App\Filament\Resources\AuditEvents\Schemas;

use App\Domain\Audit\Models\AuditEvent;
use App\Filament\Resources\AuditEvents\AuditEventLabels;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('audit.sections.event'))
                ->columns(3)
                ->schema([
                    TextEntry::make('occurred_at')
                        ->label(__('audit.fields.occurred_at'))
                        ->dateTime('d M Y, H:i:s', config('app.display_timezone')),
                    TextEntry::make('actor.name')
                        ->label(__('audit.fields.actor'))
                        ->placeholder(__('audit.system_actor')),
                    TextEntry::make('action')
                        ->label(__('audit.fields.action'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => AuditEventLabels::action($state)),
                    TextEntry::make('subject_type')
                        ->label(__('audit.fields.subject_type'))
                        ->formatStateUsing(fn (string $state): string => AuditEventLabels::subjectType($state)),
                    TextEntry::make('subject_id')->label(__('audit.fields.subject_id')),
                    TextEntry::make('subject_label')
                        ->label(__('audit.fields.subject'))
                        ->placeholder(__('audit.not_supplied')),
                ]),

            Section::make(__('audit.sections.changes'))
                ->schema([
                    TextEntry::make('changed_fields')
                        ->label(__('audit.fields.changed_fields'))
                        ->state(fn (AuditEvent $record): array => self::changedFields($record))
                        ->badge(),
                    TextEntry::make('changes_before')
                        ->label(__('audit.fields.before'))
                        ->state(fn (AuditEvent $record): string => self::prettyJson($record->changes['before'] ?? []))
                        ->copyable(),
                    TextEntry::make('changes_after')
                        ->label(__('audit.fields.after'))
                        ->state(fn (AuditEvent $record): string => self::prettyJson($record->changes['after'] ?? []))
                        ->copyable(),
                ]),
        ]);
    }

    /** @return array<int, string> */
    private static function changedFields(AuditEvent $record): array
    {
        $fields = $record->changes['fields'] ?? [];

        return is_array($fields)
            ? array_values(array_filter($fields, 'is_string'))
            : [];
    }

    private static function prettyJson(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }
}
