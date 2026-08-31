<?php

namespace App\Support\Canonical;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Type-safe reader over one untrusted canonical row.
 *
 * Adapters produce arrays shaped like docs/03-data-contracts.md; this reader is
 * the only place that turns those loose values into typed PHP. It answers the
 * structural question "is this row readable at all", not the domain question
 * "is this row acceptable" — range, catalogue and revision rules belong to each
 * capability's import service.
 *
 * It lives in Support rather than in a capability because more than one import
 * reads the same canonical conventions. It carries no business rules, so
 * sharing it does not couple Stations and Measurements to each other.
 */
final readonly class CanonicalReader
{
    /**
     * Fractional second digits the portal can carry without loss: PHP's
     * DateTime holds microseconds, and the timestamp columns are declared
     * `timestamp(6)` to match.
     */
    public const MAX_FRACTIONAL_DIGITS = 6;

    /**
     * @param  array<array-key, mixed>  $row
     */
    public function __construct(private array $row) {}

    public function string(string $key): string
    {
        $value = $this->present($key);

        if (! is_string($value)) {
            throw $this->typeError($key, 'a string');
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidCanonicalRow(
                RejectionReason::MalformedRow,
                "Field '{$key}' is empty.",
            );
        }

        return $trimmed;
    }

    public function nullableString(string $key): ?string
    {
        if (! $this->filled($key)) {
            return null;
        }

        return $this->string($key);
    }

    public function float(string $key): float
    {
        $value = $this->present($key);

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw $this->typeError($key, 'a number');
    }

    public function nullableFloat(string $key): ?float
    {
        if (! $this->filled($key)) {
            return null;
        }

        return $this->float($key);
    }

    /**
     * A number the provider must state, and may state as `null`.
     *
     * Distinct from {@see nullableFloat()}: there, a missing key and an explicit
     * `null` mean the same thing. Here the key carries meaning — `null` is how
     * the contract says "no reading" — so omitting it is a malformed row rather
     * than a missing observation.
     */
    public function requiredNullableFloat(string $key): ?float
    {
        if (! array_key_exists($key, $this->row)) {
            throw new InvalidCanonicalRow(
                RejectionReason::MalformedRow,
                "Required field '{$key}' is missing.",
            );
        }

        return $this->nullableFloat($key);
    }

    public function integer(string $key): int
    {
        $value = $this->present($key);

        if (! is_int($value)) {
            throw $this->typeError($key, 'an integer');
        }

        return $value;
    }

    public function boolean(string $key): bool
    {
        $value = $this->present($key);

        if (! is_bool($value)) {
            throw $this->typeError($key, 'a boolean');
        }

        return $value;
    }

    /**
     * Localized object with all three required application locales.
     *
     * @return array{tj: string, ru: string, en: string}
     */
    public function localized(string $key): array
    {
        $value = $this->present($key);

        if (! is_array($value)) {
            throw $this->typeError($key, 'a localized object');
        }

        $nested = new self($value);

        return [
            'tj' => $nested->string('tj'),
            'ru' => $nested->string('ru'),
            'en' => $nested->string('en'),
        ];
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->present($key);

        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->typeError($key, 'an array of strings');
        }

        $items = [];

        foreach ($value as $index => $item) {
            if (! is_string($item) || trim($item) === '') {
                throw $this->typeError("{$key}[{$index}]", 'a non-empty string');
            }

            $items[] = trim($item);
        }

        return $items;
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     */
    public function enum(string $key, string $enum): BackedEnum
    {
        $value = $this->string($key);
        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw new InvalidCanonicalRow(
                RejectionReason::UnknownEnumValue,
                "Field '{$key}' uses unsupported value '".RejectedRow::sanitize($value, 40)."'.",
            );
        }

        return $case;
    }

    /**
     * ISO 8601 timestamp carrying a UTC designator or an explicit offset,
     * normalized to UTC (docs/03-data-contracts.md, section 2).
     *
     * The shape is checked before the value becomes a moment in time, because a
     * permissive parser is the wrong tool for untrusted input: it reads
     * "tomorrow" as a date, rolls 2026-02-31 forward into March, and silently
     * stamps the application's own timezone onto a timestamp that declared
     * none — turning a provider's mistake into a plausible-looking observation
     * time instead of a rejected row.
     *
     * Accepted: `2026-08-31T06:00:00Z`, `2026-08-31T06:00:00.123Z`,
     * `2026-08-31T06:00:00.123456Z`, `2026-08-31T11:00:00+05:00`.
     *
     * Fractional seconds are kept, up to the six digits PHP's DateTime and the
     * `timestamp(6)` columns can both represent. A seventh digit is refused
     * rather than truncated: silently dropping precision would turn two
     * distinct observations into one, and the portal would have no way to tell
     * an operator that it had done so.
     */
    public function dateTime(string $key): Carbon
    {
        $value = $this->string($key);

        $matched = preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/',
            $value,
            $parts,
        );

        if ($matched !== 1) {
            throw $this->typeError(
                $key,
                "an ISO 8601 timestamp with 'Z' or an explicit offset, such as 2026-08-31T06:00:00Z",
            );
        }

        // Matched with up to nine digits so this case can be named precisely
        // instead of arriving as a generic shape mismatch.
        $fractionDigits = $parts[7] === '' ? 0 : strlen($parts[7]) - 1;

        if ($fractionDigits > self::MAX_FRACTIONAL_DIGITS) {
            throw new InvalidCanonicalRow(
                RejectionReason::UnsupportedTimestampPrecision,
                "Field '{$key}' states {$fractionDigits} fractional second digits; the portal stores at most "
                    .self::MAX_FRACTIONAL_DIGITS.' and will not silently drop the rest.',
            );
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw $this->calendarError($key);
        }

        if ((int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            throw $this->typeError($key, 'an ISO 8601 timestamp with a valid clock time');
        }

        $offset = $parts[8];

        if ($offset !== 'Z' && ((int) substr($offset, 1, 2) > 14 || (int) substr($offset, 4, 2) > 59)) {
            throw $this->typeError($key, 'an ISO 8601 timestamp with a real UTC offset');
        }

        try {
            return Carbon::instance(new DateTimeImmutable($value))->utc();
        } catch (Throwable) {
            throw $this->typeError($key, 'an ISO 8601 timestamp');
        }
    }

    /**
     * Optional timestamp, read under exactly the rules of {@see dateTime()}.
     *
     * A field the provider did not supply is absent, which is not the same as a
     * field it supplied badly: the first returns null, the second is rejected.
     */
    public function nullableDateTime(string $key): ?Carbon
    {
        if (! $this->filled($key)) {
            return null;
        }

        return $this->dateTime($key);
    }

    /**
     * Calendar date in exactly `YYYY-MM-DD` form.
     *
     * A commissioning date is a date, so a timestamp is a mapping error rather
     * than something to truncate silently.
     */
    public function nullableDate(string $key): ?Carbon
    {
        if (! $this->filled($key)) {
            return null;
        }

        $value = $this->string($key);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            throw $this->typeError($key, 'a calendar date in YYYY-MM-DD form');
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw $this->calendarError($key);
        }

        try {
            // Anchored to UTC midnight, like every other timestamp the portal
            // holds, so the value does not depend on the process timezone.
            return Carbon::instance(new DateTimeImmutable($value.'T00:00:00', new DateTimeZone('UTC')));
        } catch (Throwable) {
            throw $this->typeError($key, 'a calendar date in YYYY-MM-DD form');
        }
    }

    /**
     * Whether the key exists with a non-null value. A missing optional field
     * and an explicit `null` mean the same thing: no value was supplied.
     */
    private function filled(string $key): bool
    {
        return array_key_exists($key, $this->row) && $this->row[$key] !== null;
    }

    private function present(string $key): mixed
    {
        if (! $this->filled($key)) {
            throw new InvalidCanonicalRow(
                RejectionReason::MalformedRow,
                "Required field '{$key}' is missing.",
            );
        }

        return $this->row[$key];
    }

    private function typeError(string $key, string $expected): InvalidCanonicalRow
    {
        return new InvalidCanonicalRow(
            RejectionReason::InvalidFieldType,
            "Field '{$key}' must be {$expected}.",
        );
    }

    private function calendarError(string $key): InvalidCanonicalRow
    {
        return new InvalidCanonicalRow(
            RejectionReason::InvalidFieldType,
            "Field '{$key}' names a date that does not exist in the calendar.",
        );
    }
}
