<?php

namespace App\Domain\Integrations\Fixtures;

use App\Domain\Integrations\Data\ReconciliationSnapshot;
use App\Domain\Integrations\Exceptions\InvalidReconciliationFixture;
use JsonException;

/**
 * Loads the deterministic expected totals for the synthetic fixture dataset.
 *
 * The file is fixed by the application and cannot be selected by a console
 * argument, so the fixture command can never be turned into an arbitrary-file
 * reader.
 */
final class FixtureReconciliationExpectation
{
    public static function load(?string $path = null): ReconciliationSnapshot
    {
        $path ??= __DIR__.'/data/reconciliation.fixture.json';

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidReconciliationFixture('Reconciliation fixture is missing or unreadable.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidReconciliationFixture('Reconciliation fixture could not be read.');
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidReconciliationFixture('Reconciliation fixture is not valid JSON.');
        }

        if (! is_array($document) || array_is_list($document)) {
            throw new InvalidReconciliationFixture('Reconciliation fixture must be a JSON object.');
        }

        return new ReconciliationSnapshot(
            source: self::string($document, 'source'),
            stationCount: self::integer($document, 'station_count'),
            measurementCount: self::integer($document, 'measurement_count'),
            measurementCounts: self::measurementCounts($document),
            firstObservedAt: self::nullableString($document, 'first_observed_at'),
            lastObservedAt: self::nullableString($document, 'last_observed_at'),
            missingValueCount: self::integer($document, 'missing_value_count'),
            invalidOrSuspectCount: self::integer($document, 'invalid_or_suspect_count'),
            revisionCount: self::integer($document, 'revision_count'),
            activeAlertCount: self::integer($document, 'active_alert_count'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $document
     */
    private static function string(array $document, string $field): string
    {
        $value = $document[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidReconciliationFixture("Reconciliation field '{$field}' must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $document
     */
    private static function nullableString(array $document, string $field): ?string
    {
        $value = $document[$field] ?? null;

        if ($value !== null && (! is_string($value) || trim($value) === '')) {
            throw new InvalidReconciliationFixture("Reconciliation field '{$field}' must be a string or null.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $document
     */
    private static function integer(array $document, string $field): int
    {
        $value = $document[$field] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidReconciliationFixture("Reconciliation field '{$field}' must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @return list<array{station_external_id: string, parameter_code: string, count: int}>
     */
    private static function measurementCounts(array $document): array
    {
        $rows = $document['measurement_counts'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidReconciliationFixture("Reconciliation field 'measurement_counts' must be a list.");
        }

        $counts = [];

        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidReconciliationFixture('Every reconciliation measurement count must be an object.');
            }

            $counts[] = [
                'station_external_id' => self::string($row, 'station_external_id'),
                'parameter_code' => self::string($row, 'parameter_code'),
                'count' => self::integer($row, 'count'),
            ];
        }

        return $counts;
    }
}
