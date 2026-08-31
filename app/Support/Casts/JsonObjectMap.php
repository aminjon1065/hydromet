<?php

namespace App\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

/**
 * A string-to-string lookup table stored as a JSON object.
 *
 * Eloquent's built-in `array` cast writes an empty PHP array as `[]`, which is
 * a JSON *array*. A mapping is a dictionary, and an empty dictionary is `{}`:
 * the difference matters because the column is checked to hold an object, so
 * `[]` would be rejected by the database for every source that declares no
 * mapping.
 *
 * Both directions are strict. A mapping decides how a provider's field names
 * are translated into canonical ones, so a value that is not a string — a
 * nested object, a list, a number, a boolean — is a configuration mistake, and
 * quietly coercing it to `"1"`, `""` or `"Array"` would produce a mapping that
 * silently sends measurements to the wrong parameter. Reading is strict for the
 * same reason: a corrupted row is surfaced, never flattened into an empty
 * mapping that would look like "this source maps nothing".
 *
 * The assignable type is `mixed` on purpose: Eloquent hands the cast whatever a
 * caller assigned, so the guards in {@see set()} are real checks rather than a
 * restatement of a type the runtime does not enforce.
 *
 * @implements CastsAttributes<array<string, string>, mixed>
 */
final class JsonObjectMap implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        // A NULL column is the absence of a mapping, which is a legitimate
        // state. An empty or blank string is not: the column always holds a
        // JSON document, so blank text means the row was written by something
        // that bypassed this cast.
        if ($value === null) {
            return [];
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException(
                "Attribute [{$key}] holds ".get_debug_type($value).' where a JSON object string was expected.'
            );
        }

        if (trim($value) === '') {
            throw new UnexpectedValueException(
                "Attribute [{$key}] holds blank text where a JSON object was expected."
            );
        }

        try {
            // Decoded as an object rather than associatively: a JSON list of
            // strings would otherwise arrive as a perfectly valid PHP array and
            // be accepted, even though the column is checked to hold an object.
            $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException("Attribute [{$key}] does not hold valid JSON.");
        }

        if (! $decoded instanceof stdClass) {
            throw new UnexpectedValueException(
                "Attribute [{$key}] holds a JSON ".($decoded === null && $value !== 'null' ? 'value' : get_debug_type($decoded))
                    .' where an object was expected.'
            );
        }

        return $this->assertStringMap((array) $decoded, $key, 'stored');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null) {
            $value = [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "Attribute [{$key}] must be an array of string mappings, ".get_debug_type($value).' given.'
            );
        }

        $map = $this->assertStringMap($value, $key, 'assigned');

        // Cast to object so an empty mapping encodes as `{}` rather than `[]`.
        return json_encode((object) $map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Every entry must map a key to a plain string.
     *
     * Keys are accepted as int or string and normalized, because PHP turns a
     * numeric JSON key such as `"1"` back into an int on decode; refusing those
     * would make a legitimate stored mapping unreadable.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<string, string>
     */
    private function assertStringMap(array $map, string $key, string $origin): array
    {
        $normalized = [];

        foreach ($map as $from => $to) {
            if (! is_string($to)) {
                throw $origin === 'stored'
                    ? new UnexpectedValueException(
                        "Attribute [{$key}] has a {$origin} entry '".$this->describeKey($from)
                            ."' holding ".get_debug_type($to).' where a string was expected.'
                    )
                    : new InvalidArgumentException(
                        "Attribute [{$key}] has an {$origin} entry '".$this->describeKey($from)
                            ."' holding ".get_debug_type($to).' where a string was expected.'
                    );
            }

            $normalized[(string) $from] = $to;
        }

        return $normalized;
    }

    private function describeKey(int|string $key): string
    {
        return mb_substr((string) $key, 0, 40);
    }
}
