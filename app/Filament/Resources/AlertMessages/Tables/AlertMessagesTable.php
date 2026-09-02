<?php

namespace App\Filament\Resources\AlertMessages\Tables;

use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Models\AlertMessage;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AlertMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('identifier')
                    ->label(__('alerts.fields.identifier'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('source')
                    ->label(__('alerts.fields.source'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('message_type')
                    ->label(__('alerts.fields.message_type'))
                    ->badge()
                    ->formatStateUsing(fn (AlertMessageType $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('alerts.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (AlertStatus $state): string => $state->label())
                    ->color(fn (AlertStatus $state): string => $state->isPubliclyVisible() ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('severity')
                    ->label(__('alerts.fields.severity'))
                    ->badge()
                    ->formatStateUsing(fn (AlertSeverity $state): string => $state->label())
                    ->color(fn (AlertSeverity $state): string => self::severityColor($state))
                    ->sortable(),

                TextColumn::make('event_code')
                    ->label(__('alerts.fields.event_code'))
                    ->searchable()
                    ->sortable(),

                // The one column an operator actually scans: whether this
                // message is in force, was replaced, or has run out.
                TextColumn::make('lifecycle')
                    ->label(__('alerts.fields.lifecycle'))
                    ->badge()
                    ->state(fn (AlertMessage $record): string => self::lifecycle($record))
                    ->formatStateUsing(fn (string $state): string => __('alerts.lifecycle.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'scheduled' => 'info',
                        'superseded' => 'warning',
                        'expired' => 'gray',
                        default => 'danger',
                    }),

                TextColumn::make('areas_count')
                    ->label(__('alerts.fields.area_count'))
                    ->counts('areas')
                    ->alignEnd(),

                TextColumn::make('sent_at')
                    ->label(__('alerts.fields.sent_at'))
                    ->dateTime('d M Y, H:i', config('app.display_timezone'))
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label(__('alerts.fields.expires_at'))
                    ->dateTime('d M Y, H:i', config('app.display_timezone'))
                    ->sortable(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('alerts.fields.status'))
                    ->multiple()
                    ->options(fn (): array => self::options(AlertStatus::cases())),

                SelectFilter::make('message_type')
                    ->label(__('alerts.fields.message_type'))
                    ->multiple()
                    ->options(fn (): array => self::options(AlertMessageType::cases())),

                SelectFilter::make('severity')
                    ->label(__('alerts.fields.severity'))
                    ->multiple()
                    ->options(fn (): array => self::options(AlertSeverity::cases())),

                SelectFilter::make('source')
                    ->label(__('alerts.fields.source'))
                    ->options(fn (): array => AlertMessage::query()
                        ->distinct()
                        ->orderBy('source')
                        ->pluck('source', 'source')
                        ->all()),

                Filter::make('in_force')
                    ->label(__('alerts.lifecycle.active'))
                    ->query(self::inForce(...)),

                Filter::make('scheduled')
                    ->label(__('alerts.lifecycle.scheduled'))
                    ->query(self::scheduled(...)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * The panel filter reuses the model's own publication rule, so an operator
     * checking "what is in force" sees exactly what the public sees.
     *
     * @param  Builder<AlertMessage>  $query
     * @return Builder<AlertMessage>
     */
    private static function inForce(Builder $query): Builder
    {
        return $query->activeAt(Carbon::now('UTC'));
    }

    /**
     * Stored and publishable, but its start has not arrived yet.
     *
     * @param  Builder<AlertMessage>  $query
     * @return Builder<AlertMessage>
     */
    private static function scheduled(Builder $query): Builder
    {
        return $query->scheduledAt(Carbon::now('UTC'));
    }

    /**
     * Which of the five states this message is in, evaluated in the order an
     * operator would ask: was it published at all, was it replaced, has it run
     * out, has it started yet, otherwise it is in force.
     *
     * A message whose start is still in the future is `scheduled`, never
     * `active`: the panel and the public list are the same rule
     * ({@see AlertMessage::isActiveAt()}), so an operator can trust that
     * "in force" here means the public can see it.
     */
    private static function lifecycle(AlertMessage $record): string
    {
        if (! $record->status->isPubliclyVisible() || ! $record->scope->isPubliclyVisible()
            || ! $record->message_type->isDisplayable()) {
            return 'withheld';
        }

        if ($record->isSuperseded()) {
            return 'superseded';
        }

        $now = Carbon::now('UTC');

        if ($record->isExpiredAt($now)) {
            return 'expired';
        }

        return $record->hasStartedAt($now) ? 'active' : 'scheduled';
    }

    /**
     * Panel-only badge colours. They are not the public severity palette and
     * carry no claim of being an approved national scale.
     */
    private static function severityColor(AlertSeverity $severity): string
    {
        return match ($severity) {
            AlertSeverity::Extreme, AlertSeverity::Severe => 'danger',
            AlertSeverity::Moderate => 'warning',
            AlertSeverity::Minor => 'info',
            AlertSeverity::Unknown => 'gray',
        };
    }

    /**
     * @param  array<int, AlertStatus|AlertMessageType|AlertSeverity|AlertScope>  $cases
     * @return array<string, string>
     */
    private static function options(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
