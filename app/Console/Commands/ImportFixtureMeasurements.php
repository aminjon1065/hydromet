<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Data\SynchronizationOutcome;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Fixtures\FixtureIntegrationSource;
use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureMeasurementScenario;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Integrations\Services\SynchronizationRunner;
use App\Domain\Measurements\Data\MeasurementImportResult;
use App\Domain\Measurements\Services\MeasurementImporter;
use Illuminate\Console\Command;

/**
 * Loads one built-in synthetic measurement batch.
 *
 * The name says "fixture" in the signature, the title and every line of output
 * so it cannot be mistaken for a Hydromet import. A real import command arrives
 * with a real adapter.
 *
 * Exit code 1 means the run stopped on an unexpected error. Because the import
 * writes one row at a time, that does not mean nothing was stored; re-running
 * after the cause is fixed completes the batch, since the import is idempotent.
 * A batch that merely contained rejected rows is a partial success and exits 0,
 * because the valid rows were stored (docs/02-architecture.md, section 7).
 */
class ImportFixtureMeasurements extends Command
{
    protected $signature = 'measurements:import-fixture-batch {--scenario= : Which MOCK batch to load: base or correction}';

    protected $description = 'Import a built-in MOCK measurement batch (development and test data only, not Hydromet data)';

    public function handle(SynchronizationRunner $runner, MeasurementImporter $importer): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->components->error('This command loads invented fixture data and is blocked in production.');

            return self::FAILURE;
        }

        $scenario = $this->resolveScenario();

        if ($scenario === null) {
            return self::FAILURE;
        }

        // Resolved through the container, with the scenario as an explicit
        // parameter. The concrete fixture class is still named here, so nothing
        // can substitute a real adapter, but the read itself stays replaceable.
        $provider = $this->getLaravel()->make(FixtureMeasurementProvider::class, ['scenario' => $scenario]);

        $this->components->warn(
            'Importing MOCK data. Source key: "'.$provider->sourceKey().'". Origin: '.$provider->describeOrigin().'.'
        );

        $result = null;

        $run = $runner->run(
            FixtureIntegrationSource::ensure(),
            SynchronizationKind::Measurements,
            function () use ($importer, $provider, &$result): SynchronizationOutcome {
                $result = $importer->import($provider);

                return SynchronizationOutcome::fromMeasurements($result);
            },
        );

        // No result means the closure threw; the runner has already journalled
        // the failure and sent the exception to the log.
        if ($result === null || $run->status === SynchronizationStatus::Failed) {
            $this->renderFailure($run);

            return self::FAILURE;
        }

        $this->renderResult($scenario, $result, $run);

        return self::SUCCESS;
    }

    private function renderFailure(SynchronizationRun $run): void
    {
        $this->components->error(
            'The fixture import stopped on an unexpected error. Rows are written one at a '
            .'time, so part of the batch may already be stored. The import is idempotent: '
            .'once the cause is fixed, run it again to finish. Details are in the application log.'
        );

        $this->components->info('Recorded as synchronization run #'.$run->id.' with status "'.$run->status->value.'".');
    }

    /**
     * The scenario is required and closed: a typo names no batch rather than
     * quietly loading the wrong one.
     */
    private function resolveScenario(): ?FixtureMeasurementScenario
    {
        $supplied = $this->option('scenario');
        $allowed = implode(', ', FixtureMeasurementScenario::values());

        if (! is_string($supplied) || $supplied === '') {
            $this->components->error("Option --scenario is required. Choose one of: {$allowed}.");

            return null;
        }

        $scenario = FixtureMeasurementScenario::tryFrom($supplied);

        if ($scenario === null) {
            $this->components->error("Unknown --scenario. Choose one of: {$allowed}.");

            return null;
        }

        return $scenario;
    }

    private function renderResult(
        FixtureMeasurementScenario $scenario,
        MeasurementImportResult $result,
        SynchronizationRun $run,
    ): void {
        $counters = $result->counters();

        $this->newLine();
        $this->table(
            ['Scenario', 'Received', 'Accepted', 'Created', 'Updated', 'Unchanged', 'Rejected', 'Revisions created'],
            [[
                $scenario->value,
                (string) $counters['received'],
                (string) $counters['accepted'],
                (string) $counters['created'],
                (string) $counters['updated'],
                (string) $counters['unchanged'],
                (string) $counters['rejected'],
                (string) $counters['revisions_created'],
            ]],
        );

        $this->components->info(
            'Recorded as synchronization run #'.$run->id.' with status "'.$run->status->value.'".'
        );

        if (! $result->isPartial()) {
            $this->components->info(
                'Fixture measurement import complete for the '.$scenario->describe().'. No rows were rejected.'
            );

            return;
        }

        $this->components->warn('Partial result: valid rows were stored, the rows below were rejected and skipped.');

        $this->table(
            ['Row', 'Reason', 'Detail'],
            array_map(
                static fn ($rejection): array => [$rejection->reference, $rejection->reason->value, $rejection->detail],
                $result->rejections,
            ),
        );
    }
}
