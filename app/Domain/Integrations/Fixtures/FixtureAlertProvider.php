<?php

namespace App\Domain\Integrations\Fixtures;

use App\Domain\Alerts\Data\AlertBatch;
use App\Domain\Alerts\Data\AlertRecord;
use App\Domain\Integrations\Contracts\AlertProvider;
use App\Domain\Integrations\Data\SynchronizationWindow;
use App\Support\Canonical\InvalidCanonicalRow;
use App\Support\Canonical\RejectedRow;
use JsonException;
use RuntimeException;

/**
 * Development-only warning provider backed by checked-in fixtures.
 *
 * It exists so the Alerts capability can be built and tested before Hydromet
 * chooses a MeteoAlert source type and supplies real samples (CLAUDE.md,
 * "External-data development"; docs/08-hydromet-input-checklist.md, section 3).
 * The data it returns is invented; see the `_notice` field in each fixture.
 *
 * It is deliberately not bound to {@see AlertProvider} in the service
 * container: a real adapter must be wired explicitly, so nothing can fall back
 * to fixture data by accident. Callers name this class directly.
 *
 * The fixture is written in the canonical shape rather than in an invented CAP
 * or WFS document, because inventing a wire format would be inventing
 * Hydromet's source. What this adapter therefore exercises is the part a real
 * adapter also owns: envelope validation, provider-key stamping, per-row DTO
 * construction, and turning an unreadable row into a safe rejection instead of
 * failing the whole read.
 */
final class FixtureAlertProvider implements AlertProvider
{
    /**
     * Provider key written to `alert_messages.source`. It matches the other
     * fixture providers so every synthetic record shares one clearly-mock
     * source, and so no real Hydromet source could ever collide with it.
     */
    public const SOURCE_KEY = FixtureStationRegistryProvider::SOURCE_KEY;

    private readonly string $path;

    public function __construct(
        private readonly FixtureAlertScenario $scenario = FixtureAlertScenario::Baseline,
        ?string $path = null,
    ) {
        $this->path = $path ?? __DIR__.'/data/'.$scenario->fileName();
    }

    public function sourceKey(): string
    {
        return self::SOURCE_KEY;
    }

    public function scenario(): FixtureAlertScenario
    {
        return $this->scenario;
    }

    public function describeOrigin(): string
    {
        return 'built-in development fixture, '.$this->scenario->describe().' ('.basename($this->path).')';
    }

    public function fetchAlerts(?SynchronizationWindow $window = null): AlertBatch
    {
        $records = [];
        $rejections = [];

        foreach ($this->rows() as $index => $row) {
            // The provider owns its key; the payload never states one.
            $row['source'] = self::SOURCE_KEY;

            try {
                $record = AlertRecord::fromCanonical($row);
            } catch (InvalidCanonicalRow $exception) {
                $rejections[] = RejectedRow::make(
                    $this->reference($index, $row),
                    $exception->reason,
                    $exception->safeDetail(),
                );

                continue;
            }

            // A bounded window filters on send time, which is the only instant
            // every warning message carries. A real adapter applies this
            // upstream; the fixture applies it here so the contract behaves the
            // same either way.
            if ($window !== null && ! $this->withinWindow($record, $window)) {
                continue;
            }

            $records[] = $record;
        }

        return new AlertBatch(self::SOURCE_KEY, $records, $rejections);
    }

    private function withinWindow(AlertRecord $record, SynchronizationWindow $window): bool
    {
        // Half-open [from, to), matching the measurement window contract, so
        // consecutive windows tile without importing a message twice.
        return $record->sentAt->greaterThanOrEqualTo($window->from)
            && $record->sentAt->lessThan($window->to);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(): array
    {
        $document = $this->document();

        // A feed that says it is something else means the wrong file is on
        // disk; importing it anyway would misreport what was loaded.
        $scenario = $document['scenario'] ?? null;

        if ($scenario !== $this->scenario->value) {
            throw new RuntimeException('Alert fixture does not declare the requested scenario.');
        }

        $rows = $document['alerts'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException("Alert fixture is missing an 'alerts' list.");
        }

        $lists = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Alert fixture 'alerts' contains a non-object row.");
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
            throw new RuntimeException('Alert fixture is missing or unreadable.');
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException('Alert fixture could not be read.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Alert fixture is not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Alert fixture must decode to an object.');
        }

        return $decoded;
    }

    /**
     * Safe row reference for rejection reporting: the message's own identifier
     * when it is a usable string, otherwise its position in the feed.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function reference(int $index, array $row): string
    {
        $identifier = $row['identifier'] ?? null;

        if (is_string($identifier) && trim($identifier) !== '') {
            return self::SOURCE_KEY.':'.$identifier;
        }

        return self::SOURCE_KEY.':alert:#'.($index + 1);
    }
}
