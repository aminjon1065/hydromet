<?php

namespace Tests\Unit\Support;

use App\Domain\Integrations\Models\IntegrationSource;
use App\Support\Casts\JsonObjectMap;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use UnexpectedValueException;

class JsonObjectMapTest extends TestCase
{
    private JsonObjectMap $cast;

    private IntegrationSource $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cast = new JsonObjectMap;
        $this->model = new IntegrationSource;
    }

    /**
     * @param  array<array-key, mixed>|null  $value
     */
    private function castSet(?array $value): string
    {
        return $this->cast->set($this->model, 'unit_mapping', $value, []);
    }

    private function castGet(mixed $value): mixed
    {
        return $this->cast->get($this->model, 'unit_mapping', $value, []);
    }

    #[Test]
    public function an_empty_mapping_is_written_as_a_json_object(): void
    {
        // The reason the cast exists: `[]` would encode as a JSON array and be
        // refused by the column's object CHECK.
        $this->assertSame('{}', $this->castSet([]));
        $this->assertSame('{}', $this->castSet(null));
    }

    #[Test]
    public function an_empty_json_object_reads_back_as_an_empty_mapping(): void
    {
        $this->assertSame([], $this->castGet('{}'));
    }

    #[Test]
    public function a_null_column_reads_back_as_an_empty_mapping(): void
    {
        // NULL is the absence of a mapping, which is a legitimate state.
        $this->assertSame([], $this->castGet(null));
    }

    #[Test]
    public function a_mapping_round_trips_unchanged(): void
    {
        $mapping = ['mkg/m3' => 'ug/m3', 'PM_2_5' => 'PM25'];

        $encoded = $this->castSet($mapping);

        $this->assertSame('{"mkg/m3":"ug/m3","PM_2_5":"PM25"}', $encoded);
        $this->assertSame($mapping, $this->castGet($encoded));
    }

    #[Test]
    public function unicode_and_slashes_are_written_readably(): void
    {
        $encoded = $this->castSet(['°C' => 'degC', 'a/b' => 'c/d']);

        $this->assertStringContainsString('°C', $encoded);
        $this->assertStringContainsString('a/b', $encoded);
        $this->assertSame(['°C' => 'degC', 'a/b' => 'c/d'], $this->castGet($encoded));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function nonStringValues(): array
    {
        return [
            'nested list' => [['PM25' => ['a', 'b']], 'array'],
            'nested object' => [['PM25' => ['to' => 'PM25']], 'array'],
            'integer' => [['PM25' => 25], 'int'],
            'float' => [['PM25' => 2.5], 'float'],
            'boolean' => [['PM25' => true], 'bool'],
            'null' => [['PM25' => null], 'null'],
        ];
    }

    #[Test]
    #[DataProvider('nonStringValues')]
    public function a_mapping_value_that_is_not_a_string_is_refused_on_write(mixed $mapping, string $type): void
    {
        // Coercing these would produce a mapping that silently points a
        // provider field at the wrong canonical name.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("holding {$type} where a string was expected");

        $this->castSet($mapping);
    }

    #[Test]
    public function a_value_that_is_not_an_array_is_refused_on_write(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an array of string mappings');

        $this->cast->set($this->model, 'unit_mapping', 'ug/m3', []);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function corruptStoredValues(): array
    {
        return [
            // The column always holds a JSON document, so blank text means the
            // row was written by something that bypassed this cast.
            'empty string' => ['', 'blank text where a JSON object was expected'],
            'whitespace only' => ['   ', 'blank text where a JSON object was expected'],
            'tab and newline only' => ["\t\n", 'blank text where a JSON object was expected'],
            'invalid json' => ['{not json', 'does not hold valid JSON'],
            // A list of strings is the trap: decoded associatively it would be
            // an ordinary PHP array of strings and pass every value check.
            'json list of strings' => ['["ug/m3"]', 'where an object was expected'],
            'empty json list' => ['[]', 'where an object was expected'],
            'json string' => ['"ug/m3"', 'where an object was expected'],
            'json number' => ['42', 'where an object was expected'],
            'json boolean' => ['true', 'where an object was expected'],
            'nested value' => ['{"PM25":{"to":"PM25"}}', 'where a string was expected'],
            'numeric value' => ['{"PM25":25}', 'where a string was expected'],
            'null value' => ['{"PM25":null}', 'where a string was expected'],
        ];
    }

    #[Test]
    #[DataProvider('corruptStoredValues')]
    public function a_corrupt_stored_value_is_surfaced_rather_than_silently_emptied(
        string $stored,
        string $expectedMessage,
    ): void {
        // Returning [] here would read as "this source maps nothing", which is
        // a valid configuration and would hide the corruption indefinitely.
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->castGet($stored);
    }

    #[Test]
    public function a_stored_value_of_the_wrong_php_type_is_surfaced(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('where a JSON object string was expected');

        $this->castGet(42);
    }

    #[Test]
    public function a_numeric_key_survives_the_round_trip(): void
    {
        // PHP decodes the JSON key "1" as int 1, so the cast must normalize it
        // back rather than refuse a mapping it wrote itself.
        $encoded = $this->castSet(['1' => 'PM10']);

        $this->assertSame('{"1":"PM10"}', $encoded);
        $this->assertSame(['1' => 'PM10'], $this->castGet($encoded));
    }
}
