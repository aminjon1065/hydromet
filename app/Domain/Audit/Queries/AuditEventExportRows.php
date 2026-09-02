<?php

namespace App\Domain\Audit\Queries;

use App\Domain\Audit\Models\AuditEvent;
use App\Support\Csv\SpreadsheetSafeText;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Streams the immutable audit log as language-neutral CSV rows.
 *
 * Every column is the stored machine value, never a translated label: an export
 * taken by a Tajik-speaking administrator and one taken by a Russian-speaking
 * administrator must be byte-identical, and must still be readable after the
 * translation files change. Timestamps are UTC ISO 8601 for the same reason —
 * the portal displays Asia/Dushanbe, but evidence is exchanged in UTC.
 */
final class AuditEventExportRows
{
    /**
     * @var array<int, string>
     */
    public const HEADER = [
        'occurred_at_utc',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'actor_id',
        'actor_email',
        'changes_json',
    ];

    /**
     * Rows are read in id order in bounded chunks, so a log of any size streams
     * in constant memory.
     *
     * `$beforeId` is the exclusive upper bound, and it is an id rather than a
     * timestamp on purpose: `occurred_at` is stored to the second, so an entry
     * written in the same second as the export would be indistinguishable from
     * one written just before it. Bounding on the monotonic key makes the file
     * deterministic and keeps the export's own entry out of its own output.
     *
     * @return Generator<int, array<int, string>>
     */
    public function get(int $beforeId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Generator
    {
        $query = AuditEvent::query()
            ->with('actor:id,email')
            ->where('id', '<', $beforeId);

        if ($from instanceof CarbonImmutable) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to instanceof CarbonImmutable) {
            $query->where('occurred_at', '<=', $to);
        }

        foreach ($query->orderBy('id')->lazyById(500) as $event) {
            yield $this->row($event);
        }
    }

    /**
     * @return array<int, string>
     */
    private function row(AuditEvent $event): array
    {
        // A payload that cannot be re-encoded still has to produce a line: an
        // audit entry that silently disappears from the evidence is worse than
        // one whose detail column is empty.
        $changes = json_encode($event->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return array_map(SpreadsheetSafeText::cell(...), [
            $event->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            $event->action,
            $event->subject_type,
            $event->subject_id,
            $event->subject_label,
            $event->actor_id === null ? '' : (string) $event->actor_id,
            // The actor's name is deliberately absent: it is mutable and adds
            // no accountability the stable login identity does not already
            // carry, so the export holds one identifier fewer.
            $event->actor?->email,
            is_string($changes) ? $changes : '',
        ]);
    }
}
