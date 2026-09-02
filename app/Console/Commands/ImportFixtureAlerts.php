<?php

namespace App\Console\Commands;

use App\Domain\Alerts\Data\AlertImportResult;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Data\SynchronizationOutcome;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Fixtures\FixtureAlertProvider;
use App\Domain\Integrations\Fixtures\FixtureAlertScenario;
use App\Domain\Integrations\Fixtures\FixtureIntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Integrations\Services\SynchronizationRunner;
use Illuminate\Console\Command;

/**
 * Loads one built-in synthetic warning feed.
 *
 * The name says "fixture" in the signature, the title and every line of output
 * so it cannot be mistaken for a MeteoAlert import. A real import command
 * arrives with a real adapter, once Hydromet names the source type
 * (docs/08-hydromet-input-checklist.md, section 3).
 *
 * The command owns no import logic: it resolves the provider, hands the work to
 * {@see SynchronizationRunner} so the attempt is journalled, and renders the
 * result. Exit code 1 means the run stopped on an unexpected error; a feed that
 * merely contained rejected messages is a partial success and exits 0, because
 * the valid warnings were stored (docs/02-architecture.md, section 7).
 */
class ImportFixtureAlerts extends Command
{
    protected $signature = 'alerts:import-fixture-feed {--scenario= : Which MOCK feed to load: baseline or lifecycle}';

    protected $description = 'Import a built-in MOCK warning feed (development and test data only, not Hydromet data)';

    public function handle(SynchronizationRunner $runner, AlertImporter $importer): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->components->error('This command loads invented fixture data and is blocked in production.');

            return self::FAILURE;
        }

        $scenario = $this->resolveScenario();

        if ($scenario === null) {
            return self::FAILURE;
        }

        $provider = $this->getLaravel()->make(FixtureAlertProvider::class, ['scenario' => $scenario]);

        $this->components->warn(
            'Importing MOCK data. Source key: "'.$provider->sourceKey().'". Origin: '.$provider->describeOrigin().'.'
        );

        $result = null;

        $run = $runner->run(
            FixtureIntegrationSource::ensure(),
            SynchronizationKind::Alerts,
            function () use ($importer, $provider, &$result): SynchronizationOutcome {
                $result = $importer->import($provider);

                return SynchronizationOutcome::make(
                    $result->received,
                    $result->accepted(),
                    $result->updated,
                    $result->rejections,
                );
            },
        );

        // No result means the closure threw; the runner has already journalled
        // the failure and logged safe metadata.
        if ($result === null || $run->status === SynchronizationStatus::Failed) {
            $this->renderFailure($run);

            return self::FAILURE;
        }

        $this->renderResult($scenario, $result, $run);

        return self::SUCCESS;
    }

    /**
     * The scenario is required and closed: a typo names no feed rather than
     * quietly loading the wrong one.
     */
    private function resolveScenario(): ?FixtureAlertScenario
    {
        $supplied = $this->option('scenario');
        $allowed = implode(', ', FixtureAlertScenario::values());

        if (! is_string($supplied) || $supplied === '') {
            $this->components->error("Option --scenario is required. Choose one of: {$allowed}.");

            return null;
        }

        $scenario = FixtureAlertScenario::tryFrom($supplied);

        if ($scenario === null) {
            $this->components->error("Unknown --scenario. Choose one of: {$allowed}.");

            return null;
        }

        return $scenario;
    }

    private function renderFailure(SynchronizationRun $run): void
    {
        $this->components->error(
            'The fixture import stopped on an unexpected error. Messages are written one at a '
            .'time, so part of the feed may already be stored. The import is idempotent: '
            .'once the cause is fixed, run it again to finish.'
        );

        $this->components->info('Recorded as synchronization run #'.$run->id.' with status "'.$run->status->value.'".');
    }

    private function renderResult(
        FixtureAlertScenario $scenario,
        AlertImportResult $result,
        SynchronizationRun $run,
    ): void {
        $counters = $result->counters();

        $this->newLine();
        $this->table(
            ['Scenario', 'Received', 'Accepted', 'Created', 'Updated', 'Unchanged', 'Rejected', 'Superseded'],
            [[
                $scenario->value,
                (string) $counters['received'],
                (string) $counters['accepted'],
                (string) $counters['created'],
                (string) $counters['updated'],
                (string) $counters['unchanged'],
                (string) $counters['rejected'],
                (string) $counters['superseded'],
            ]],
        );

        $this->components->info(
            'Recorded as synchronization run #'.$run->id.' with status "'.$run->status->value.'".'
        );

        if (! $result->isPartial()) {
            $this->components->info(
                'Fixture warning import complete for the '.$scenario->describe().'. No messages were rejected.'
            );

            return;
        }

        $this->components->warn('Partial result: valid messages were stored, the rows below were rejected and skipped.');

        $this->table(
            ['Row', 'Reason', 'Detail'],
            array_map(
                static fn ($rejection): array => [$rejection->reference, $rejection->reason->value, $rejection->detail],
                $result->rejections,
            ),
        );
    }
}
