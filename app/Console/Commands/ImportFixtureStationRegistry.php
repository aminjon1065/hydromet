<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Stations\Data\ImportResult;
use App\Domain\Stations\Data\StationRegistryImportReport;
use App\Domain\Stations\Services\StationRegistryImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads the built-in development fixture into the station registry.
 *
 * The name says "fixture" in the signature, the title and every line of output
 * so it cannot be mistaken for a Hydromet import. A real import command arrives
 * with a real adapter.
 *
 * Exit code 1 means the run stopped on an unexpected error. Because the import
 * writes one row at a time, that does not mean nothing was stored; re-running
 * after the cause is fixed completes the registry, since the import is
 * idempotent. A batch that merely contained rejected rows is a partial success
 * and exits 0, because the valid rows were stored (docs/02-architecture.md,
 * section 7).
 */
class ImportFixtureStationRegistry extends Command
{
    protected $signature = 'stations:import-fixture-registry';

    protected $description = 'Import the built-in MOCK station registry fixture (development and test data only, not Hydromet data)';

    public function handle(StationRegistryImporter $importer, FixtureStationRegistryProvider $provider): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->components->error('This command loads invented fixture data and is blocked in production.');

            return self::FAILURE;
        }

        $this->components->warn('Importing MOCK data. Source key: "'.$provider->sourceKey().'". Origin: '.$provider->describeOrigin().'.');

        try {
            $report = $importer->import($provider);
        } catch (Throwable $exception) {
            // An unexpected exception can carry driver output, file paths or
            // configuration details, so it goes to the log and never to the
            // console.
            report($exception);

            $this->components->error(
                'The fixture import stopped on an unexpected error. Rows are written one at a '
                .'time, so part of the registry may already be stored. The import is idempotent: '
                .'once the cause is fixed, run it again to finish. Details are in the application log.'
            );

            return self::FAILURE;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    private function renderReport(StationRegistryImportReport $report): void
    {
        $this->newLine();
        $this->table(
            ['Collection', 'Received', 'Accepted', 'Created', 'Updated', 'Unchanged', 'Rejected'],
            [
                $this->row('parameters', $report->parameters),
                $this->row('stations', $report->stations),
            ],
        );

        if (! $report->isPartial()) {
            $this->components->info('Fixture import complete for source "'.$report->source.'". No rows were rejected.');

            return;
        }

        $this->components->warn('Partial result: valid rows were stored, the rows below were rejected and skipped.');

        $this->table(
            ['Row', 'Reason', 'Detail'],
            array_map(
                static fn ($rejection): array => [$rejection->reference, $rejection->reason->value, $rejection->detail],
                $report->rejections(),
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    private function row(string $label, ImportResult $result): array
    {
        $counters = $result->counters();

        return [
            $label,
            (string) $counters['received'],
            (string) $counters['accepted'],
            (string) $counters['created'],
            (string) $counters['updated'],
            (string) $counters['unchanged'],
            (string) $counters['rejected'],
        ];
    }
}
