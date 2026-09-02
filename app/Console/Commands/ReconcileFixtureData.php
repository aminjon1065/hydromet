<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Data\ReconciliationReport;
use App\Domain\Integrations\Fixtures\FixtureReconciliationExpectation;
use App\Domain\Integrations\Services\DataReconciler;
use Illuminate\Console\Command;

/**
 * Proves the complete checked-in mock dataset matches its expected aggregate
 * totals. Production reconciliation gets a separately supplied and approved
 * expectation once Hydromet delivers a reference period.
 *
 * "Complete" includes the warning fixtures: `active_alert_count` is part of the
 * acceptance totals in docs/06-testing-and-acceptance.md, so running this
 * before `alerts:import-fixture-feed` reports a difference rather than passing
 * on a partial dataset. The failure output names every batch that has to be
 * imported first.
 */
class ReconcileFixtureData extends Command
{
    protected $signature = 'data:reconcile-fixture';

    protected $description = 'Compare imported MOCK data with its checked-in expected totals (not Hydromet acceptance)';

    public function handle(DataReconciler $reconciler): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->components->error('This command validates invented fixture data and is blocked in production.');

            return self::FAILURE;
        }

        $this->components->warn('Reconciling MOCK fixture totals. This is not Hydromet acceptance evidence.');

        $report = $reconciler->reconcile(FixtureReconciliationExpectation::load());

        if ($report->matches()) {
            $this->renderSuccess($report);

            return self::SUCCESS;
        }

        $this->renderFailure($report);

        return self::FAILURE;
    }

    private function renderSuccess(ReconciliationReport $report): void
    {
        $actual = $report->actual;

        $this->table(
            ['Source', 'Stations', 'Measurements', 'Missing', 'Invalid/suspect', 'Revisions', 'Warnings in force', 'First observation', 'Last observation'],
            [[
                $actual->source,
                (string) $actual->stationCount,
                (string) $actual->measurementCount,
                (string) $actual->missingValueCount,
                (string) $actual->invalidOrSuspectCount,
                (string) $actual->revisionCount,
                (string) $actual->activeAlertCount,
                $actual->firstObservedAt ?? '-',
                $actual->lastObservedAt ?? '-',
            ]],
        );

        $this->components->info('MOCK fixture reconciliation passed: every expected aggregate matches.');
    }

    private function renderFailure(ReconciliationReport $report): void
    {
        $this->components->error('MOCK fixture reconciliation failed. Investigate every difference below.');
        $this->components->warn(
            'The expected totals assume every fixture batch has been imported: '
            .'stations:import-fixture-registry, measurements:import-fixture-batch '
            .'(--scenario=base and --scenario=correction) and alerts:import-fixture-feed '
            .'(--scenario=baseline and --scenario=lifecycle).',
        );

        $this->table(
            ['Field', 'Expected', 'Actual'],
            array_map(
                fn (array $difference): array => [
                    $difference['field'],
                    $this->printable($difference['expected']),
                    $this->printable($difference['actual']),
                ],
                $report->differences(),
            ),
        );
    }

    private function printable(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
