<?php

namespace App\Domain\Alerts\Services;

use App\Domain\Alerts\Data\AlertAreaRecord;
use App\Domain\Alerts\Data\AlertRecord;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use BackedEnum;
use Illuminate\Support\Carbon;

/**
 * Decides whether an incoming record restates a stored message or contradicts
 * it.
 *
 * `source` + `identifier` is the published identity of a warning, so the answer
 * governs both idempotency and safety: a feed re-read must recognise yesterday's
 * warnings as unchanged, while the same identifier arriving with different
 * content must never overwrite what was published under it.
 *
 * The comparison is semantic, not textual. Four things are deliberately
 * normalised before comparing, because none of them carries meaning:
 *
 *   - the key order of a JSON object, which a serializer may reorder freely;
 *   - the element order of `categories` and `references`, which CAP defines as
 *     whitespace-delimited sets rather than sequences;
 *   - the order of a message's affected areas — a warning covers a set of
 *     areas, and which one a feed lists first is not information. The stored
 *     order is insertion order and the incoming order is the provider's, so
 *     comparing them positionally reported a re-read of an unchanged feed as a
 *     conflict;
 *   - the order of the geocodes inside one area, for the same reason.
 *
 * Normalising order is not the same as collapsing duplicates: the lists are
 * sorted, never keyed, so two identical areas still differ from one.
 *
 * Everything else is compared as given. Coordinate arrays in particular keep
 * their order: reversing a polygon ring is a different shape, not a different
 * spelling of the same one. Changing an area's description, geocode, geometry,
 * altitude or ceiling — or adding or removing an area — is a content change and
 * still conflicts.
 */
final class AlertMessageComparison
{
    /**
     * Fields that identify what was published and may never change under a
     * stored identifier. Supersession and `imported_at` are excluded: they are
     * the portal's own bookkeeping, not the sender's content.
     *
     * @var array<int, string>
     */
    private const IMMUTABLE_FIELDS = [
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
    ];

    /**
     * Fields whose element order is not meaningful.
     *
     * @var array<int, string>
     */
    private const UNORDERED_LISTS = ['categories', 'references'];

    /**
     * Whether the incoming record says exactly what the stored message says.
     *
     * The stored areas are read through the relation, so a caller must have
     * loaded them or accept one query; the importer holds a row lock while it
     * asks, which is where that query belongs anyway.
     */
    public static function restates(AlertMessage $stored, AlertRecord $record): bool
    {
        return self::messageFingerprint($stored) === self::recordFingerprint($record)
            && self::storedAreaFingerprint($stored) === self::recordAreaFingerprint($record);
    }

    /**
     * @return array<string, mixed>
     */
    private static function messageFingerprint(AlertMessage $stored): array
    {
        $fingerprint = [];

        foreach (self::IMMUTABLE_FIELDS as $field) {
            $fingerprint[$field] = self::normalize($field, $stored->getAttribute($field));
        }

        return $fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    private static function recordFingerprint(AlertRecord $record): array
    {
        $fingerprint = [];
        $attributes = $record->attributes();

        foreach (self::IMMUTABLE_FIELDS as $field) {
            $fingerprint[$field] = self::normalize($field, $attributes[$field] ?? null);
        }

        return $fingerprint;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function storedAreaFingerprint(AlertMessage $stored): array
    {
        return self::inCanonicalOrder(array_values(array_map(
            static fn (AlertArea $area): array => self::areaFingerprint([
                'description_tj' => $area->description_tj,
                'description_ru' => $area->description_ru,
                'description_en' => $area->description_en,
                'geocodes' => $area->geocodes,
                'geometry' => $area->geometry,
                'altitude_m' => $area->altitude_m,
                'ceiling_m' => $area->ceiling_m,
            ]),
            // Read in insertion order so a debugging dump is stable; the sort
            // below is what makes the comparison independent of it.
            $stored->areas()->orderBy('id')->get()->all(),
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function recordAreaFingerprint(AlertRecord $record): array
    {
        return self::inCanonicalOrder(array_map(
            static fn (AlertAreaRecord $area): array => self::areaFingerprint([
                'description_tj' => $area->descriptionTj,
                'description_ru' => $area->descriptionRu,
                'description_en' => $area->descriptionEn,
                'geocodes' => $area->geocodes,
                'geometry' => $area->geometry,
                'altitude_m' => $area->altitudeM,
                'ceiling_m' => $area->ceilingM,
            ]),
            $record->areas,
        ));
    }

    /**
     * One affected area, reduced to a form that depends only on its content.
     *
     * @param  array<string, mixed>  $area
     * @return array<string, mixed>
     */
    private static function areaFingerprint(array $area): array
    {
        $geocodes = $area['geocodes'] ?? [];

        return [
            'description_tj' => $area['description_tj'] ?? null,
            'description_ru' => $area['description_ru'] ?? null,
            'description_en' => $area['description_en'] ?? null,
            // A geocode list is a set of labels for the same area; which label
            // the provider writes first is not information.
            'geocodes' => self::inCanonicalOrder(
                is_array($geocodes)
                    ? array_map(self::canonicalJson(...), array_values($geocodes))
                    : [],
            ),
            // Coordinates keep their order on purpose.
            'geometry' => self::canonicalJson($area['geometry'] ?? null),
            // Compared as fixed-scale strings on both sides: a decimal column
            // reads back as `50.00`, and `50` and `50.0` are the same altitude.
            'altitude_m' => self::decimal($area['altitude_m'] ?? null, 2),
            'ceiling_m' => self::decimal($area['ceiling_m'] ?? null, 2),
        ];
    }

    /**
     * Order a list by each element's canonical representation.
     *
     * Sorting a list rather than keying a map is what keeps duplicates: two
     * identical areas stay two entries, so a feed that drops one of them is
     * still a content change.
     *
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private static function inCanonicalOrder(array $items): array
    {
        $keyed = array_map(
            static fn (mixed $item): array => [self::canonicalKey($item), $item],
            $items,
        );

        usort($keyed, static fn (array $left, array $right): int => strcmp(
            (string) $left[0],
            (string) $right[0],
        ));

        return array_map(static fn (array $pair): mixed => $pair[1], $keyed);
    }

    /**
     * A deterministic sort key for one already-canonicalised value.
     *
     * `json_encode` is the canonical representation, and `serialize` is the
     * fallback for the one thing it cannot render — a string that is not valid
     * UTF-8. Falling back rather than returning an empty key matters: two
     * different areas that collapsed to the same key could sort into either
     * order, and the comparison would then report an unchanged feed as changed.
     */
    private static function canonicalKey(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : serialize($value);
    }

    private static function normalize(string $field, mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Carbon) {
            return $value->utc()->format(AlertMessage::TIMESTAMP_FORMAT);
        }

        if (in_array($field, self::UNORDERED_LISTS, true) && is_array($value)) {
            $values = array_map(static fn (mixed $item): string => (string) $item, array_values($value));
            sort($values, SORT_STRING);

            return $values;
        }

        if (is_array($value)) {
            return self::canonicalJson($value);
        }

        return $value;
    }

    /**
     * Sort object keys recursively so two spellings of the same document
     * compare equal, while leaving list order alone.
     *
     * Numbers are widened to float on the way, because JSON does not
     * distinguish `69` from `69.0` but PHP does: a coordinate read back from a
     * JSON column arrives as an int where the same coordinate parsed from the
     * feed arrived as a float. Comparing those strictly would report every
     * whole-degree vertex as a content change and quarantine a feed that had
     * not changed at all.
     */
    private static function canonicalJson(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalJson(...), $value);
        }

        $sorted = [];

        foreach ($value as $key => $item) {
            $sorted[$key] = self::canonicalJson($item);
        }

        ksort($sorted, SORT_STRING);

        return $sorted;
    }

    private static function decimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }
}
