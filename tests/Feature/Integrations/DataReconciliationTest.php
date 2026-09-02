<?php

namespace Tests\Feature\Integrations;

use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Fixtures\FixtureAlertProvider;
use App\Domain\Integrations\Fixtures\FixtureAlertScenario;
use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureMeasurementScenario;
use App\Domain\Integrations\Fixtures\FixtureReconciliationExpectation;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Integrations\Services\DataReconciler;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Services\MeasurementImporter;
use App\Domain\Stations\Models\Station;
use App\Domain\Stations\Services\StationRegistryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'data:reconcile-fixture';

    /**
     * Every total but one is time-independent. `active_alert_count` is not, so
     * the clock is frozen inside the fixture warning window rather than left to
     * whatever the suite happens to run at.
     */
    private const RECONCILED_AT = '2026-06-01T00:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::RECONCILED_AT));
    }

    #[Test]
    public function the_complete_fixture_dataset_matches_every_expected_total(): void
    {
        $this->importCompleteFixtureDataset();

        $report = (new DataReconciler)->reconcile(FixtureReconciliationExpectation::load());

        $this->assertTrue($report->matches());
        $this->assertSame([], $report->differences());
        $this->assertSame([
            [
                'station_external_id' => 'fixture-station-001',
                'parameter_code' => 'PM10',
                'count' => 1,
            ],
            [
                'station_external_id' => 'fixture-station-001',
                'parameter_code' => 'PM25',
                'count' => 3,
            ],
            [
                'station_external_id' => 'fixture-station-002',
                'parameter_code' => 'TA',
                'count' => 2,
            ],
            [
                'station_external_id' => 'fixture-station-003',
                'parameter_code' => 'RH',
                'count' => 1,
            ],
        ], $report->actual->measurementCounts);
        $this->assertSame(1, $report->actual->activeAlertCount);
    }

    /**
     * The reconciled warning count is the publication rule, not a row count:
     * the fixtures store six messages and only one of them is in force.
     */
    #[Test]
    public function the_warning_total_counts_only_the_warnings_in_force(): void
    {
        $this->importCompleteFixtureDataset();

        $snapshot = (new DataReconciler)->snapshot('fixture', Carbon::parse(self::RECONCILED_AT));

        $this->assertGreaterThan(1, AlertMessage::query()->where('source', 'fixture')->count());
        $this->assertSame(1, $snapshot->activeAlertCount);
    }

    #[Test]
    public function a_wrong_warning_total_is_reported_and_fails_the_command(): void
    {
        $this->importCompleteFixtureDataset();

        // Withdraw the one warning in force, exactly as a Cancel would, so the
        // dataset stops matching its expectation in that one field.
        $inForce = AlertMessage::query()->activeAt(Carbon::parse(self::RECONCILED_AT))->sole();
        $replacement = AlertMessage::query()
            ->where('source', 'fixture')
            ->whereKeyNot($inForce->id)
            ->firstOrFail();

        $inForce->update([
            'superseded_by_id' => $replacement->id,
            'superseded_at' => Carbon::parse(self::RECONCILED_AT),
        ]);

        $report = (new DataReconciler)->reconcile(
            FixtureReconciliationExpectation::load(),
            Carbon::parse(self::RECONCILED_AT),
        );

        $this->assertFalse($report->matches());
        $this->assertContains('active_alert_count', array_column($report->differences(), 'field'));

        $exitCode = Artisan::call(self::COMMAND);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('active_alert_count', Artisan::output());
    }

    #[Test]
    public function unrelated_source_rows_do_not_change_the_fixture_report(): void
    {
        $this->importCompleteFixtureDataset();
        Station::factory()->create(['source' => 'another-source']);
        Measurement::factory()->create(['source' => 'another-source']);

        $report = (new DataReconciler)->reconcile(FixtureReconciliationExpectation::load());

        $this->assertTrue($report->matches());
    }

    #[Test]
    public function every_difference_is_reported_instead_of_being_hidden(): void
    {
        $this->importCompleteFixtureDataset();
        Measurement::query()
            ->where('source', 'fixture')
            ->whereNull('value')
            ->sole()
            ->delete();

        $report = (new DataReconciler)->reconcile(FixtureReconciliationExpectation::load());
        $fields = array_column($report->differences(), 'field');

        $this->assertFalse($report->matches());
        $this->assertContains('measurement_count', $fields);
        $this->assertContains('measurement_counts', $fields);
        $this->assertContains('missing_value_count', $fields);
    }

    #[Test]
    public function the_fixture_command_passes_after_all_fixture_batches_are_imported(): void
    {
        $this->importCompleteFixtureDataset();

        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MOCK', $output);
        $this->assertStringContainsString('reconciliation passed', $output);
        $this->assertStringContainsString('7', $output);
    }

    #[Test]
    public function the_fixture_command_fails_with_an_actionable_diff_when_data_is_missing(): void
    {
        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('reconciliation failed', $output);
        $this->assertStringContainsString('station_count', $output);
        $this->assertStringContainsString('measurement_count', $output);
        // The operator has to be told which batches are missing, including
        // the warning scenarios that feed active_alert_count.
        $this->assertStringContainsString('active_alert_count', $output);
        $this->assertStringContainsString('stations:import-fixture-registry', $output);
        $this->assertStringContainsString('alerts:import-fixture-feed', $output);
    }

    #[Test]
    public function the_fixture_command_is_blocked_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $exitCode = Artisan::call(self::COMMAND);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('blocked in production', Artisan::output());
    }

    /**
     * Every checked-in batch, warnings included. The expected totals describe
     * the whole fixture dataset, so importing part of it is a mismatch rather
     * than a smaller pass.
     */
    private function importCompleteFixtureDataset(): void
    {
        (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);

        $measurements = new MeasurementImporter;
        $measurements->import(new FixtureMeasurementProvider(FixtureMeasurementScenario::Base));
        $measurements->import(new FixtureMeasurementProvider(FixtureMeasurementScenario::Correction));

        $alerts = new AlertImporter;
        $alerts->import(new FixtureAlertProvider(FixtureAlertScenario::Baseline));
        $alerts->import(new FixtureAlertProvider(FixtureAlertScenario::Lifecycle));
    }
}
