<?php

namespace App\Domain\Integrations\Fixtures;

use App\Domain\Integrations\Contracts\StationRegistryProvider;
use App\Domain\Stations\Data\ParameterCatalogueBatch;
use App\Domain\Stations\Data\ParameterRecord;
use App\Domain\Stations\Data\StationRecord;
use App\Domain\Stations\Data\StationRegistryBatch;
use App\Support\Canonical\InvalidCanonicalRow;
use App\Support\Canonical\RejectedRow;
use JsonException;
use RuntimeException;

/**
 * Development-only station registry provider backed by a checked-in fixture.
 *
 * It exists so the Stations capability can be built and tested before Hydromet
 * delivers real samples (CLAUDE.md, "External-data development"). The data it
 * returns is invented; see the `_notice` field in the fixture file.
 *
 * It is deliberately not bound to {@see StationRegistryProvider} in the service
 * container: a real adapter must be wired explicitly, so nothing can fall back
 * to fixture data by accident. Callers name this class directly.
 *
 * Mapping responsibility kept here:
 *   - read and validate the fixture envelope;
 *   - stamp the provider key, which a payload is never allowed to choose;
 *   - build one canonical DTO per row;
 *   - turn an unreadable row into a safe rejection instead of failing the read.
 */
final class FixtureStationRegistryProvider implements StationRegistryProvider
{
    /**
     * Provider key written to `stations.source`. Distinct from `hydromet` so
     * fixture rows can never be mistaken for, or collide with, real ones.
     */
    public const SOURCE_KEY = 'fixture';

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? __DIR__.'/data/station-registry.fixture.json';
    }

    public function sourceKey(): string
    {
        return self::SOURCE_KEY;
    }

    public function describeOrigin(): string
    {
        return 'built-in development fixture ('.basename($this->path).')';
    }

    public function fetchParameterCatalogue(): ParameterCatalogueBatch
    {
        $rows = $this->rows('parameter_catalogue');

        $records = [];
        $rejections = [];

        foreach ($rows as $index => $row) {
            try {
                $records[] = ParameterRecord::fromCanonical($row);
            } catch (InvalidCanonicalRow $exception) {
                $rejections[] = RejectedRow::make(
                    $this->reference('parameter', $index, $row, 'code'),
                    $exception->reason,
                    $exception->safeDetail(),
                );
            }
        }

        return new ParameterCatalogueBatch(self::SOURCE_KEY, $records, $rejections);
    }

    public function fetchStationRegistry(): StationRegistryBatch
    {
        $rows = $this->rows('station_registry');

        $records = [];
        $rejections = [];

        foreach ($rows as $index => $row) {
            // The provider owns its key; the payload never states one.
            $row['source'] = self::SOURCE_KEY;

            try {
                $records[] = StationRecord::fromCanonical($row);
            } catch (InvalidCanonicalRow $exception) {
                $rejections[] = RejectedRow::make(
                    $this->reference('station', $index, $row, 'external_id'),
                    $exception->reason,
                    $exception->safeDetail(),
                );
            }
        }

        return new StationRegistryBatch(self::SOURCE_KEY, $records, $rejections);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(string $key): array
    {
        $document = $this->document();
        $rows = $document[$key] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException("Fixture is missing a '{$key}' list.");
        }

        $lists = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Fixture '{$key}' contains a non-object row.");
            }

            $lists[] = $row;
        }

        return $lists;
    }

    /**
     * A read failure is a whole-source failure, not a row rejection, so it is
     * thrown rather than reported per row.
     *
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException('Station registry fixture is missing or unreadable.');
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException('Station registry fixture could not be read.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Station registry fixture is not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Station registry fixture must decode to an object.');
        }

        return $decoded;
    }

    /**
     * Safe row reference for rejection reporting: the row's own identifier when
     * it is a usable string, otherwise its position in the fixture.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function reference(string $kind, int $index, array $row, string $idKey): string
    {
        $id = $row[$idKey] ?? null;

        if (is_string($id) && trim($id) !== '') {
            return self::SOURCE_KEY.':'.$kind.':'.$id;
        }

        return self::SOURCE_KEY.':'.$kind.':#'.($index + 1);
    }
}
