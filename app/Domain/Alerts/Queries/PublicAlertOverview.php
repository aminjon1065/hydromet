<?php

namespace App\Domain\Alerts\Queries;

use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Support\Locale\SupportedLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The warnings a public client may see, in the shape both the Inertia map and
 * `/api/v1/alerts` render.
 *
 * Publication rules live in {@see AlertMessage::scopeActiveAt()} so the map,
 * the API and the admin panel cannot drift apart on what "active" means.
 *
 * Nothing here computes an index, a colour or a health recommendation: those
 * are Hydromet decisions that have not been made
 * (docs/08-hydromet-input-checklist.md, sections 3 and 4).
 *
 * @phpstan-type PublicAlertArea array{
 *     description: string,
 *     geometry: array<string, mixed>|null,
 *     geocodes: list<array{name: string, value: string}>
 * }
 * @phpstan-type PublicAlert array{
 *     identifier: string,
 *     source: string,
 *     isMock: bool,
 *     eventCode: string,
 *     severity: string,
 *     urgency: string,
 *     certainty: string,
 *     sender: string,
 *     headline: string,
 *     description: string,
 *     instruction: string|null,
 *     sentAt: string,
 *     effectiveAt: string|null,
 *     onsetAt: string|null,
 *     expiresAt: string,
 *     areas: list<PublicAlertArea>
 * }
 * @phpstan-type PublicAlertHistoryEntry array{
 *     identifier: string,
 *     messageType: string,
 *     severity: string,
 *     headline: string,
 *     sentAt: string,
 *     supersededAt: string|null
 * }
 * @phpstan-type PublicAlertDetail array{
 *     identifier: string,
 *     source: string,
 *     isMock: bool,
 *     eventCode: string,
 *     severity: string,
 *     urgency: string,
 *     certainty: string,
 *     sender: string,
 *     headline: string,
 *     description: string,
 *     instruction: string|null,
 *     sentAt: string,
 *     effectiveAt: string|null,
 *     onsetAt: string|null,
 *     expiresAt: string,
 *     areas: list<PublicAlertArea>,
 *     status: string,
 *     messageType: string,
 *     supersededAt: string|null,
 *     isActive: bool
 * }
 */
final class PublicAlertOverview
{
    /**
     * Upper bound on how many messages one chain may contain.
     *
     * Far above any real warning chain, and low enough that corrupted data
     * cannot turn one detail request into an unbounded walk.
     */
    private const MAX_CHAIN_MESSAGES = 200;

    /**
     * @param  array{west: float, south: float, east: float, north: float}|null  $bbox
     * @return list<PublicAlert>
     */
    public function active(
        ?Carbon $moment = null,
        ?SupportedLocale $locale = null,
        ?array $bbox = null,
        ?string $severity = null,
        ?string $eventCode = null,
    ): array {
        $moment ??= Carbon::now('UTC');
        $locale ??= SupportedLocale::current();

        $query = AlertMessage::query()
            ->activeAt($moment)
            ->with(['areas' => static fn ($relation) => $relation->orderBy('id')]);

        if ($severity !== null) {
            $query->where('severity', $severity);
        }

        if ($eventCode !== null) {
            $query->where('event_code', $eventCode);
        }

        if ($bbox !== null) {
            $this->applyBoundingBox($query, $bbox);
        }

        // Most urgent first, then most recent: someone scanning the list should
        // meet the worst warning before the newest one. The ranking comes from
        // the enum rather than the column, because sorting the stored strings
        // alphabetically puts Extreme after Severe and Minor before Moderate.
        [$rankSql, $rankBindings] = AlertSeverity::descendingRankOrder();

        $messages = $query
            ->orderByRaw($rankSql, $rankBindings)
            ->orderByDesc('sent_at')
            ->orderBy('identifier')
            // Last resort, so two messages that agree on everything above still
            // come back in the same order on every request and every driver.
            ->orderBy('id')
            ->get();

        return array_values(array_map(
            fn (AlertMessage $message): array => $this->present($message, $locale),
            $messages->all(),
        ));
    }

    /**
     * One warning with its full message chain, for the detail view.
     *
     * @return array{current: PublicAlertDetail, history: list<PublicAlertHistoryEntry>}|null
     */
    public function detail(string $source, string $identifier, ?SupportedLocale $locale = null): ?array
    {
        $locale ??= SupportedLocale::current();

        $message = AlertMessage::query()
            ->where('source', $source)
            ->where('identifier', $identifier)
            ->with(['areas' => static fn ($relation) => $relation->orderBy('id')])
            ->first();

        if ($message === null) {
            return null;
        }

        // Only public messages are addressable: a restricted or test message
        // must not become readable just because its identifier was guessed.
        if (! $message->status->isPubliclyVisible() || ! $message->scope->isPubliclyVisible()) {
            return null;
        }

        return [
            'current' => [
                ...$this->present($message, $locale),
                'status' => $message->status->value,
                'messageType' => $message->message_type->value,
                'supersededAt' => $message->superseded_at?->utc()->toIso8601ZuluString(),
                'isActive' => $message->isActiveAt(Carbon::now('UTC')),
            ],
            'history' => array_values(array_map(
                fn (AlertMessage $entry): array => [
                    'identifier' => $entry->identifier,
                    'messageType' => $entry->message_type->value,
                    'severity' => $entry->severity->value,
                    'headline' => $entry->localizedHeadline($locale),
                    'sentAt' => $entry->sent_at->utc()->toIso8601ZuluString(),
                    'supersededAt' => $entry->superseded_at?->utc()->toIso8601ZuluString(),
                ],
                $this->chain($message)->all(),
            )),
        ];
    }

    /**
     * The whole supersession chain this message belongs to, newest first.
     *
     * A warning is rarely two messages. `Alert → Update → Update → Cancel` is
     * ordinary, and a client asking about any one of them needs the same four:
     * looking only one step in each direction returns a window, not a history,
     * and silently loses the rest.
     *
     * Walked breadth-first in PHP rather than with a recursive CTE, so
     * PostgreSQL and SQLite behave identically, and guarded twice against
     * corrupted data: a visited set makes a reference cycle terminate, and
     * {@see MAX_CHAIN_MESSAGES} bounds the total. Reaching that bound is not
     * silent — it is logged with the identity of the message that hit it, so a
     * truncated history is investigable rather than merely wrong.
     *
     * Only messages sharing this one's status and scope are followed, so a
     * restricted or test message never becomes readable through a public one.
     *
     * @return Collection<int, AlertMessage>
     */
    private function chain(AlertMessage $message): Collection
    {
        $visited = [$message->id => $message];
        $frontier = [$message];

        while ($frontier !== []) {
            $ids = [];
            $successors = [];

            foreach ($frontier as $node) {
                $ids[] = $node->id;

                if ($node->superseded_by_id !== null) {
                    $successors[] = $node->superseded_by_id;
                }
            }

            $neighbours = AlertMessage::query()
                ->where('source', $message->source)
                ->where('status', $message->status)
                ->where('scope', $message->scope)
                ->where(static fn (Builder $builder): Builder => $builder
                    // Predecessors: messages this frontier replaced.
                    ->whereIn('superseded_by_id', $ids)
                    // Successors: the message that replaced each of them.
                    ->orWhereIn('id', $successors === [] ? [0] : $successors))
                ->orderBy('id')
                ->limit(self::MAX_CHAIN_MESSAGES + 1)
                ->get();

            $frontier = [];

            foreach ($neighbours as $neighbour) {
                if (isset($visited[$neighbour->id])) {
                    continue;
                }

                if (count($visited) >= self::MAX_CHAIN_MESSAGES) {
                    Log::warning('Alert message chain exceeded the traversal limit.', [
                        'source' => $message->source,
                        'identifier' => $message->identifier,
                        'limit' => self::MAX_CHAIN_MESSAGES,
                    ]);

                    $frontier = [];

                    break 2;
                }

                $visited[$neighbour->id] = $neighbour;
                $frontier[] = $neighbour;
            }
        }

        // Re-read in one ordered query so the history order is decided by the
        // database, not by the order the walk happened to discover nodes in.
        return AlertMessage::query()
            ->whereKey(array_keys($visited))
            ->orderByDesc('sent_at')
            ->orderBy('identifier')
            ->orderBy('id')
            ->get();
    }

    /**
     * Keep only warnings with at least one area overlapping the extent.
     *
     * The comparison uses the derived `bbox_*` columns rather than a spatial
     * predicate, so PostgreSQL and SQLite agree; see the `alert_areas`
     * migration. An area with no geometry cannot overlap anything and is
     * excluded, which is why a geocode-only warning needs Hydromet's boundary
     * dataset before it can be found on the map.
     *
     * @param  Builder<AlertMessage>  $query
     * @param  array{west: float, south: float, east: float, north: float}  $bbox
     */
    private function applyBoundingBox(Builder $query, array $bbox): void
    {
        $query->whereHas('areas', static fn (Builder $areas): Builder => $areas
            ->whereNotNull('bbox_west')
            // Two extents overlap unless one is entirely past the other.
            ->where('bbox_west', '<=', $bbox['east'])
            ->where('bbox_east', '>=', $bbox['west'])
            ->where('bbox_south', '<=', $bbox['north'])
            ->where('bbox_north', '>=', $bbox['south']));
    }

    /**
     * @return PublicAlert
     */
    private function present(AlertMessage $message, SupportedLocale $locale): array
    {
        return [
            'identifier' => $message->identifier,
            'source' => $message->source,
            'isMock' => $message->isMock(),
            'eventCode' => $message->event_code,
            'severity' => $message->severity->value,
            'urgency' => $message->urgency->value,
            'certainty' => $message->certainty->value,
            'sender' => $message->sender,
            'headline' => $message->localizedHeadline($locale),
            'description' => $message->localizedDescription($locale),
            'instruction' => $message->localizedInstruction($locale),
            'sentAt' => $message->sent_at->utc()->toIso8601ZuluString(),
            'effectiveAt' => $message->effective_at?->utc()->toIso8601ZuluString(),
            'onsetAt' => $message->onset_at?->utc()->toIso8601ZuluString(),
            'expiresAt' => $message->expires_at->utc()->toIso8601ZuluString(),
            'areas' => array_values(array_map(
                static fn (AlertArea $area): array => [
                    'description' => $area->localizedDescription($locale),
                    'geometry' => $area->geometry,
                    'geocodes' => $area->geocodes,
                ],
                $message->areas->all(),
            )),
        ];
    }
}
