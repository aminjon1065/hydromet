<?php

namespace App\Domain\Alerts\Models;

use App\Domain\Alerts\Enums\AlertCertainty;
use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Enums\AlertUrgency;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Support\Casts\JsonObjectMap;
use App\Support\Locale\SupportedLocale;
use Database\Factories\AlertMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One received warning message, docs/03-data-contracts.md section 7.
 *
 * Written only by {@see AlertImporter}. A message
 * is never deleted and its content is never rewritten: an `Update` arrives as a
 * new row and stamps its predecessor as superseded, which is what keeps the
 * published history reconstructable.
 *
 * "Active" is therefore a query over this table, not a column that some job
 * has to keep true.
 *
 * @property int $id
 * @property string $source
 * @property string $identifier
 * @property string $sender
 * @property AlertStatus $status
 * @property AlertMessageType $message_type
 * @property AlertScope $scope
 * @property string $event_code
 * @property AlertSeverity $severity
 * @property AlertUrgency $urgency
 * @property AlertCertainty $certainty
 * @property list<string> $categories
 * @property list<string> $references
 * @property array<string, string> $parameters
 * @property Carbon $sent_at
 * @property Carbon|null $effective_at
 * @property Carbon|null $onset_at
 * @property Carbon $expires_at
 * @property string $headline_tj
 * @property string $headline_ru
 * @property string $headline_en
 * @property string $description_tj
 * @property string $description_ru
 * @property string $description_en
 * @property string|null $instruction_tj
 * @property string|null $instruction_ru
 * @property string|null $instruction_en
 * @property int|null $superseded_by_id
 * @property Carbon|null $superseded_at
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon $imported_at
 * @property-read Collection<int, AlertArea> $areas
 * @property-read AlertMessage|null $supersededBy
 * @property-read Collection<int, AlertMessage> $supersedes
 */
#[Fillable([
    'source',
    'identifier',
    'sender',
    'status',
    'message_type',
    'scope',
    'event_code',
    'severity',
    'urgency',
    'certainty',
    'categories',
    'references',
    'parameters',
    'sent_at',
    'effective_at',
    'onset_at',
    'expires_at',
    'headline_tj',
    'headline_ru',
    'headline_en',
    'description_tj',
    'description_ru',
    'description_en',
    'instruction_tj',
    'instruction_ru',
    'instruction_en',
    'superseded_by_id',
    'superseded_at',
    'imported_at',
])]
#[UseFactory(AlertMessageFactory::class)]
class AlertMessage extends Model
{
    /** @use HasFactory<AlertMessageFactory> */
    use HasFactory;

    /**
     * Microsecond storage, matching measurements: two messages of the same
     * chain can be sent inside one second, and ordering them decides which one
     * the public sees.
     */
    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    protected $dateFormat = self::TIMESTAMP_FORMAT;

    /**
     * The only columns a stored message may ever change, and only once.
     *
     * @var array<int, string>
     */
    private const SUPERSESSION_COLUMNS = ['superseded_by_id', 'superseded_at'];

    /**
     * Append-only at the Eloquent boundary, matching the database triggers in
     * `2026_09_02_120011_add_alert_history_immutability_guards`.
     *
     * Both boundaries are needed and neither replaces the other: model events
     * catch the mistake that is easy to make — `$message->severity = ...;
     * $message->save()` — with an error that names the rule, while the triggers
     * catch the mass update or raw statement that never loads a model at all.
     * `AlertImporter` stamps supersession through the query builder, so that
     * path is checked by the database rather than here.
     */
    protected static function booted(): void
    {
        static::deleting(static function (): never {
            throw new LogicException(
                'Alert messages are never deleted: the published history has to stay reconstructable.',
            );
        });

        static::updating(static function (AlertMessage $message): void {
            $rewritten = array_diff(
                array_keys($message->getDirty()),
                [...self::SUPERSESSION_COLUMNS, $message->getUpdatedAtColumn()],
            );

            if ($rewritten !== []) {
                throw new LogicException(
                    'Alert message content is immutable; a correction arrives as a new message. Rewritten: '
                    .implode(', ', $rewritten).'.',
                );
            }

            $wasStamped = $message->getOriginal('superseded_by_id') !== null
                || $message->getOriginal('superseded_at') !== null;

            if ($wasStamped) {
                if ($message->isDirty(self::SUPERSESSION_COLUMNS)) {
                    throw new LogicException(
                        'An alert supersession is stamped once and cannot be cleared, reassigned or retimed.',
                    );
                }

                return;
            }

            $successor = $message->superseded_by_id;
            $stampedAt = $message->superseded_at;

            if ($successor === null && $stampedAt === null) {
                return;
            }

            // Half a stamp says a warning was withdrawn at no particular time,
            // or at a time by nobody. Both halves move together or neither
            // does — the same rule the database triggers enforce.
            if ($successor === null || $stampedAt === null) {
                throw new LogicException(
                    'An alert supersession sets superseded_by_id and superseded_at together or not at all.',
                );
            }

            if ($successor === $message->getKey()) {
                throw new LogicException('An alert message cannot supersede itself.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AlertStatus::class,
            'message_type' => AlertMessageType::class,
            'scope' => AlertScope::class,
            'severity' => AlertSeverity::class,
            'urgency' => AlertUrgency::class,
            'certainty' => AlertCertainty::class,
            'categories' => 'array',
            'references' => 'array',
            // A dictionary, not a list: Eloquent's `array` cast writes an empty
            // PHP array as `[]`, which is a JSON array and is refused by
            // `alert_messages_json_shapes_check`. Every warning that carries no
            // source parameters would otherwise fail to store — and only on
            // PostgreSQL, because SQLite has no such constraint.
            'parameters' => JsonObjectMap::class,
            'sent_at' => 'datetime',
            'effective_at' => 'datetime',
            'onset_at' => 'datetime',
            'expires_at' => 'datetime',
            'superseded_at' => 'datetime',
            'imported_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return HasMany<AlertArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(AlertArea::class);
    }

    /**
     * @return BelongsTo<AlertMessage, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * @return HasMany<AlertMessage, $this>
     */
    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    /**
     * Messages the public portal may show at `$moment`.
     *
     * The six conditions are the whole publication rule, and each removes a
     * category the portal must never display:
     *   - non-`Actual` status is an exercise, test, draft or system message;
     *   - non-`Public` scope is addressed to named recipients;
     *   - a `Cancel` withdraws a warning and is not itself a warning;
     *   - a superseded message has been replaced by a later one in its chain;
     *   - a message that has not started yet is scheduled, not in force;
     *   - a message past its expiry is no longer in force.
     *
     * The start of the window is {@see startsAt()}: `effective_at` when the
     * sender supplied one, otherwise `sent_at`. Treating an absent
     * `effective_at` as "already started" would publish a warning the sender
     * dated into the future.
     *
     * The SQL below spells the same rule out as a branch rather than a
     * `COALESCE`, so PostgreSQL and SQLite compare the identical expression and
     * neither driver has to cast a literal in its own way.
     *
     * @param  Builder<AlertMessage>  $query
     * @return Builder<AlertMessage>
     */
    public function scopeActiveAt(Builder $query, Carbon $moment): Builder
    {
        $at = $moment->format(self::TIMESTAMP_FORMAT);

        return $query
            ->where('status', AlertStatus::Actual)
            ->where('scope', AlertScope::Public)
            ->whereIn('message_type', [AlertMessageType::Alert, AlertMessageType::Update])
            ->whereNull('superseded_at')
            ->where('expires_at', '>', $at)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where(static fn (Builder $withEffective): Builder => $withEffective
                    ->whereNotNull('effective_at')
                    ->where('effective_at', '<=', $at))
                ->orWhere(static fn (Builder $withoutEffective): Builder => $withoutEffective
                    ->whereNull('effective_at')
                    ->where('sent_at', '<=', $at)));
    }

    /**
     * Messages already stored but not yet in force at `$moment`.
     *
     * Everything a published message needs except a start that has arrived, so
     * an operator can see what is queued rather than wondering why a stored
     * warning is missing from the public list.
     *
     * @param  Builder<AlertMessage>  $query
     * @return Builder<AlertMessage>
     */
    public function scopeScheduledAt(Builder $query, Carbon $moment): Builder
    {
        $at = $moment->format(self::TIMESTAMP_FORMAT);

        return $query
            ->where('status', AlertStatus::Actual)
            ->where('scope', AlertScope::Public)
            ->whereIn('message_type', [AlertMessageType::Alert, AlertMessageType::Update])
            ->whereNull('superseded_at')
            ->where('expires_at', '>', $at)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where(static fn (Builder $withEffective): Builder => $withEffective
                    ->whereNotNull('effective_at')
                    ->where('effective_at', '>', $at))
                ->orWhere(static fn (Builder $withoutEffective): Builder => $withoutEffective
                    ->whereNull('effective_at')
                    ->where('sent_at', '>', $at)));
    }

    /**
     * The single definition of when this message starts being in force.
     *
     * CAP makes `effective` optional and says the message takes effect when it
     * was sent if it is absent, so `sent_at` is the fallback, never "always".
     */
    public function startsAt(): Carbon
    {
        return $this->effective_at ?? $this->sent_at;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function isExpiredAt(Carbon $moment): bool
    {
        return $this->expires_at->lessThanOrEqualTo($moment);
    }

    public function hasStartedAt(Carbon $moment): bool
    {
        return $this->startsAt()->lessThanOrEqualTo($moment);
    }

    public function isActiveAt(Carbon $moment): bool
    {
        return $this->isPublishable()
            && $this->hasStartedAt($moment)
            && ! $this->isExpiredAt($moment);
    }

    /**
     * Not yet in force, but nothing except time stands in the way.
     */
    public function isScheduledAt(Carbon $moment): bool
    {
        return $this->isPublishable()
            && ! $this->hasStartedAt($moment)
            && ! $this->isExpiredAt($moment);
    }

    /**
     * Everything the publication rule checks apart from the clock.
     */
    public function isPublishable(): bool
    {
        return $this->status->isPubliclyVisible()
            && $this->scope->isPubliclyVisible()
            && $this->message_type->isDisplayable()
            && ! $this->isSuperseded();
    }

    /**
     * Whether this message was produced by a development fixture rather than a
     * real provider. Public screens use it to label demonstration data.
     *
     * The key comes from the fixture adapter itself, so this is a statement
     * about which provider wrote the row rather than a literal repeated across
     * the code — a real feed is recognised by not being that provider.
     */
    public function isMock(): bool
    {
        return $this->source === FixtureStationRegistryProvider::SOURCE_KEY;
    }

    public function localizedHeadline(?SupportedLocale $locale = null): string
    {
        return $this->localized('headline', $locale) ?? '';
    }

    public function localizedDescription(?SupportedLocale $locale = null): string
    {
        return $this->localized('description', $locale) ?? '';
    }

    public function localizedInstruction(?SupportedLocale $locale = null): ?string
    {
        return $this->localized('instruction', $locale);
    }

    /**
     * Read one translated field.
     *
     * There is deliberately no fallback to another language: no approved rule
     * says which language may stand in for a missing one, and a warning shown
     * in the wrong language is worse than one shown as unavailable
     * (CLAUDE.md, engineering rules).
     */
    private function localized(string $field, ?SupportedLocale $locale): ?string
    {
        $locale ??= SupportedLocale::current();
        $value = $this->getAttribute($field.'_'.$locale->value);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
