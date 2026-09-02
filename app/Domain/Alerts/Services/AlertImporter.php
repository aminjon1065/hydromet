<?php

namespace App\Domain\Alerts\Services;

use App\Domain\Alerts\Data\AlertAreaRecord;
use App\Domain\Alerts\Data\AlertBatch;
use App\Domain\Alerts\Data\AlertImportResult;
use App\Domain\Alerts\Data\AlertRecord;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Integrations\Contracts\AlertProvider;
use App\Domain\Integrations\Data\SynchronizationWindow;
use App\Support\Canonical\RejectedRow;
use App\Support\Canonical\RejectionReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes canonical warnings into the portal's own tables.
 *
 * The only writer of `alert_messages` and `alert_areas`
 * (docs/02-architecture.md, section 4). Adapters hand it canonical records; it
 * decides what is storable and how the CAP lifecycle resolves.
 *
 * Identity is `source` + `identifier`: a CAP identifier is unique within its
 * sender, and re-reading a feed that still contains yesterday's warnings must
 * not duplicate them.
 *
 * Lifecycle rules (docs/06-testing-and-acceptance.md, ALERT-01..04):
 *   - an `Alert` is stored as a new message;
 *   - an `Update` is stored as a new message and stamps every message it
 *     references as superseded, so the predecessor leaves the active view while
 *     staying in the history;
 *   - a `Cancel` is stored and supersedes its references, but is never itself
 *     displayed;
 *   - nothing is ever deleted, and an already-stored message is never rewritten
 *     beyond re-resolving its supersession. Expiry needs no write at all: it is
 *     a comparison against `expires_at` at read time.
 *
 * A stored identifier that arrives again carrying different content is a
 * provider or feed fault, not an edit: it is quarantined as
 * `identifier_conflict` and nothing is written — not the message, not its
 * areas, and not the supersession of any other message. See
 * {@see AlertMessageComparison} for what "different" means here.
 *
 * Each message is written in its own transaction, so one rejected warning never
 * rolls back the warnings around it (docs/02-architecture.md, section 7).
 */
final class AlertImporter
{
    public function import(AlertProvider $provider, ?SynchronizationWindow $window = null): AlertImportResult
    {
        return $this->importBatch($provider->fetchAlerts($window));
    }

    public function importBatch(AlertBatch $batch): AlertImportResult
    {
        $rejections = $batch->rejections;
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $superseded = 0;
        $seenIdentifiers = [];

        // Sent order, so an Update that arrives in the same batch as the Alert
        // it replaces is applied after it. A feed is a snapshot, not a queue,
        // and nothing guarantees the array order matches the chain order.
        foreach ($this->inSentOrder($batch->records) as $record) {
            $reference = $record->identity();
            $failure = $this->validate($record, $batch->source, $seenIdentifiers);

            if ($failure !== null) {
                $rejections[] = RejectedRow::make($reference, $failure[0], $failure[1]);

                continue;
            }

            $seenIdentifiers[$record->identifier] = true;

            try {
                $outcome = $this->persist($record);
            } catch (QueryException) {
                // Reported without driver output: a database message can carry
                // schema details that do not belong in operator-facing text.
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::PersistenceConflict,
                    'The warning could not be stored because it conflicts with an existing message.',
                );

                continue;
            }

            if ($outcome['state'] === 'conflict') {
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::IdentifierConflict,
                    'This identifier is already stored with different content; the stored message was kept unchanged.',
                );

                continue;
            }

            match ($outcome['state']) {
                'created' => $created++,
                'updated' => $updated++,
                default => $unchanged++,
            };

            $superseded += $outcome['superseded'];
        }

        return AlertImportResult::make(
            $batch->received(),
            $created,
            $updated,
            $unchanged,
            $superseded,
            $rejections,
        );
    }

    /**
     * @param  list<AlertRecord>  $records
     * @return list<AlertRecord>
     */
    private function inSentOrder(array $records): array
    {
        $ordered = $records;

        usort(
            $ordered,
            static fn (AlertRecord $left, AlertRecord $right): int => $left->sentAt->equalTo($right->sentAt)
                ? strcmp($left->identifier, $right->identifier)
                : ($left->sentAt->lessThan($right->sentAt) ? -1 : 1),
        );

        return $ordered;
    }

    /**
     * @param  array<string, true>  $seenIdentifiers
     * @return array{RejectionReason, string}|null
     */
    private function validate(AlertRecord $record, string $batchSource, array $seenIdentifiers): ?array
    {
        if ($record->source !== $batchSource) {
            return [RejectionReason::MalformedRow, 'The message declares a different source than the batch it arrived in.'];
        }

        if (isset($seenIdentifiers[$record->identifier])) {
            return [RejectionReason::DuplicateInBatch, 'Another message in the same batch already uses this identifier.'];
        }

        if ($record->expiresAt->lessThanOrEqualTo($record->sentAt)) {
            return [
                RejectionReason::InvalidValidityWindow,
                'The warning expires at or before it was sent, so it was never in force.',
            ];
        }

        if ($record->effectiveAt !== null && $record->effectiveAt->lessThan($record->sentAt)) {
            return [
                RejectionReason::InvalidValidityWindow,
                'The warning becomes effective before it was sent.',
            ];
        }

        if ($record->onsetAt !== null && $record->effectiveAt !== null
            && $record->onsetAt->lessThan($record->effectiveAt)) {
            return [
                RejectionReason::InvalidValidityWindow,
                'The warning onset precedes the moment it becomes effective.',
            ];
        }

        // An Update or Cancel that names nothing cannot be resolved: the portal
        // would have no way to know which warning it replaces, and guessing
        // would silently leave a withdrawn warning on the map.
        if ($record->messageType->supersedesReferences() && $record->references === []) {
            return [
                RejectionReason::MissingReference,
                'An '.$record->messageType->value.' message must reference the message it replaces.',
            ];
        }

        // The instruction is optional as a whole, but never in one language
        // only: rendering it would need a fallback rule nobody has approved.
        $instructions = [$record->instructionTj, $record->instructionRu, $record->instructionEn];
        $present = count(array_filter($instructions, static fn (?string $value): bool => $value !== null));

        if ($present !== 0 && $present !== 3) {
            return [
                RejectionReason::IncompleteTranslation,
                'The instruction must be supplied in every application language or in none.',
            ];
        }

        return null;
    }

    /**
     * @return array{state: 'created'|'updated'|'unchanged'|'conflict', superseded: int}
     */
    private function persist(AlertRecord $record): array
    {
        return DB::transaction(function () use ($record): array {
            $existing = AlertMessage::query()
                ->where('source', $record->source)
                ->where('identifier', $record->identifier)
                // Locked so a concurrent import of the same feed cannot
                // interleave read and write and store the message twice.
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $message = AlertMessage::query()->create([
                    'source' => $record->source,
                    'identifier' => $record->identifier,
                    'imported_at' => Carbon::now('UTC'),
                    ...$record->attributes(),
                ]);

                $this->storeAreas($message, $record->areas);

                return ['state' => 'created', 'superseded' => $this->resolveSupersession($message)];
            }

            // A stored message is authoritative: CAP corrects a warning by
            // sending a new message with a new identifier, never by resending
            // the old one with different content. So an incoming record is
            // accepted only when it restates what is already stored.
            if (! AlertMessageComparison::restates($existing, $record)) {
                return ['state' => 'conflict', 'superseded' => 0];
            }

            // An identical repeat may still finish a supersession an earlier
            // run left half-done. It is resolved from the STORED message, never
            // from the record that arrived with it: taking the message type and
            // references from an untrusted repeat is exactly how a stored Alert
            // resent as a Cancel would withdraw warnings it never referenced.
            $newlySuperseded = $this->resolveSupersession($existing);

            return [
                'state' => $newlySuperseded > 0 ? 'updated' : 'unchanged',
                'superseded' => $newlySuperseded,
            ];
        });
    }

    /**
     * Stamp the messages this one replaces.
     *
     * Every input is read from the stored row, so the result depends only on
     * what the portal already accepted. Only an `Update` or `Cancel`
     * supersedes, and only messages that are not already superseded — so
     * re-importing the same feed changes nothing and an earlier withdrawal is
     * never re-attributed to a later message.
     */
    private function resolveSupersession(AlertMessage $message): int
    {
        $references = $message->references;

        if (! $message->message_type->supersedesReferences() || $references === []) {
            return 0;
        }

        return AlertMessage::query()
            ->where('source', $message->source)
            ->whereIn('identifier', $references)
            ->whereNull('superseded_at')
            // A message cannot withdraw itself, and the database refuses it.
            ->whereKeyNot($message->id)
            ->update([
                'superseded_by_id' => $message->id,
                // The moment the replacement was sent, not the moment the
                // portal read it: the history must say when the warning stopped
                // being in force, not when the importer happened to run.
                'superseded_at' => $message->sent_at->format(AlertMessage::TIMESTAMP_FORMAT),
                'updated_at' => Carbon::now('UTC')->format(AlertMessage::TIMESTAMP_FORMAT),
            ]);
    }

    /**
     * @param  list<AlertAreaRecord>  $areas
     */
    private function storeAreas(AlertMessage $message, array $areas): void
    {
        foreach ($areas as $area) {
            AlertArea::query()->create([
                'alert_message_id' => $message->id,
                'description_tj' => $area->descriptionTj,
                'description_ru' => $area->descriptionRu,
                'description_en' => $area->descriptionEn,
                'geocodes' => $area->geocodes,
                'geometry' => $area->geometry,
                'bbox_west' => $area->bbox['west'] ?? null,
                'bbox_south' => $area->bbox['south'] ?? null,
                'bbox_east' => $area->bbox['east'] ?? null,
                'bbox_north' => $area->bbox['north'] ?? null,
                'altitude_m' => $area->altitudeM,
                'ceiling_m' => $area->ceilingM,
            ]);
        }
    }
}
