<?php

namespace App\Domain\Integrations\Fixtures;

use App\Domain\Integrations\Contracts\MeasurementProvider;
use App\Domain\Measurements\Data\MeasurementBatch;
use App\Domain\Measurements\Data\MeasurementRecord;
use App\Support\Canonical\InvalidCanonicalRow;
use App\Support\Canonical\RejectedRow;
use JsonException;
use RuntimeException;

/**
 * Development-only measurement provider backed by checked-in fixtures.
 *
 * It exists so the Measurements capability can be built and tested before
 * Hydromet delivers real samples (CLAUDE.md, "External-data development"). The
 * data it returns is invented; see the `_notice` field in each fixture file.
 *
 * It is deliberately not bound to {@see MeasurementProvider} in the service
 * container: a real adapter must be wired explicitly, so nothing can fall back
 * to fixture data by accident. Callers name this class directly.
 *
 * Mapping responsibility kept here:
 *   - read and validate the fixture envelope;
 *   - confirm the file really is the scenario that was asked for;
 *   - stamp the provider key, which a payload is never allowed to choose;
 *   - build one canonical DTO per row;
 *   - turn an unreadable row into a safe rejection instead of failing the read.
 */
final class FixtureMeasurementProvider implements MeasurementProvider
{
    /**
     * Provider key written to `measurements.source`. It matches the station
     * registry fixture's source key, because a measurement is tied to a station
     * through `source` + `station_external_id`.
     */
    public const SOURCE_KEY = FixtureStationRegistryProvider::SOURCE_KEY;

    private readonly string $path;

    public function __construct(
        private readonly FixtureMeasurementScenario $scenario = FixtureMeasurementScenario::Base,
        ?string $path = null,
    ) {
        $this->path = $path ?? __DIR__.'/data/'.$scenario->fileName();
    }

    public function sourceKey(): string
    {
        return self::SOURCE_KEY;
    }

    public function scenario(): FixtureMeasurementScenario
    {
        return $this->scenario;
    }

    public function describeOrigin(): string
    {
        return 'built-in development fixture, '.$this->scenario->describe().' ('.basename($this->path).')';
    }

    public function fetchMeasurements(): MeasurementBatch
    {
        $records = [];
        $rejections = [];

        foreach ($this->rows() as $index => $row) {
            // The provider owns its key; the payload never states one.
            $row['source'] = self::SOURCE_KEY;

            try {
                $records[] = MeasurementRecord::fromCanonical($row);
            } catch (InvalidCanonicalRow $exception) {
                $rejections[] = RejectedRow::make(
                    $this->reference($index, $row),
                    $exception->reason,
                    $exception->safeDetail(),
                );
            }
        }

        return new MeasurementBatch(self::SOURCE_KEY, $records, $rejections);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(): array
    {
        $document = $this->document();

        // A batch that says it is something else means the wrong file is on
        // disk; importing it anyway would misreport what was loaded.
        $scenario = $document['scenario'] ?? null;

        if ($scenario !== $this->scenario->value) {
            throw new RuntimeException('Measurement fixture does not declare the requested scenario.');
        }

        $rows = $document['measurements'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException("Measurement fixture is missing a 'measurements' list.");
        }

        $lists = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Measurement fixture 'measurements' contains a non-object row.");
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
            throw new RuntimeException('Measurement fixture is missing or unreadable.');
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException('Measurement fixture could not be read.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Measurement fixture is not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Measurement fixture must decode to an object.');
        }

        return $decoded;
    }

    /**
     * Safe row reference for rejection reporting: the row's own natural-key
     * fields when they are usable strings, otherwise its position.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function reference(int $index, array $row): string
    {
        $station = $row['station_external_id'] ?? null;
        $parameter = $row['parameter_code'] ?? null;
        $observedAt = $row['observed_at'] ?? null;

        if (is_string($station) && is_string($parameter) && is_string($observedAt)) {
            return self::SOURCE_KEY.':'.$station.':'.$parameter.':'.$observedAt;
        }

        return self::SOURCE_KEY.':measurement:#'.($index + 1);
    }
}
