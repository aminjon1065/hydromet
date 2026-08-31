<?php

namespace Tests\Feature\Measurements;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Integrations\Models\SynchronizationRejectedRow;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use App\Domain\Stations\Services\StationRegistryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CapturesLogs;
use Tests\TestCase;

class ImportFixtureMeasurementsCommandTest extends TestCase
{
    use CapturesLogs, RefreshDatabase;

    private const COMMAND = 'measurements:import-fixture-batch';

    protected function setUp(): void
    {
        parent::setUp();

        (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);
    }

    #[Test]
    public function the_command_name_and_description_state_that_it_loads_mock_data(): void
    {
        $this->assertArrayHasKey(self::COMMAND, Artisan::all());
        $this->assertStringContainsString('fixture', self::COMMAND);

        $description = Artisan::all()[self::COMMAND]->getDescription();
        $this->assertStringContainsString('MOCK', $description);
        $this->assertStringContainsString('not Hydromet data', $description);
    }

    #[Test]
    public function the_base_scenario_imports_and_reports_a_partial_result(): void
    {
        $exitCode = Artisan::call(self::COMMAND, ['--scenario' => 'base']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MOCK', $output);
        $this->assertStringContainsString('Partial result', $output);
        $this->assertStringContainsString('Revisions created', $output);
        $this->assertStringContainsString('unknown_station', $output);

        $this->assertSame(7, Measurement::query()->count());
        $this->assertSame(0, MeasurementRevision::query()->count());
    }

    #[Test]
    public function a_rejected_row_does_not_make_the_command_fail(): void
    {
        // The base fixture always contains exactly one deliberately broken row,
        // so a non-zero exit here would mean a partial import is treated as a
        // failure.
        $this->assertSame(0, Artisan::call(self::COMMAND, ['--scenario' => 'base']));
    }

    #[Test]
    public function running_the_base_scenario_twice_leaves_the_same_number_of_rows(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'base']);
        Artisan::output();

        $measurements = Measurement::query()->count();

        $this->assertSame(0, Artisan::call(self::COMMAND, ['--scenario' => 'base']));
        $output = Artisan::output();

        $this->assertSame($measurements, Measurement::query()->count());
        // received 8, accepted 7, created 0, updated 0, unchanged 7, rejected 1, revisions 0
        $this->assertMatchesRegularExpression(
            '/base\s*\|\s*8\s*\|\s*7\s*\|\s*0\s*\|\s*0\s*\|\s*7\s*\|\s*1\s*\|\s*0/',
            $output,
        );
    }

    #[Test]
    public function running_the_correction_scenario_twice_creates_exactly_one_revision(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'base']);
        Artisan::output();

        $this->assertSame(0, Artisan::call(self::COMMAND, ['--scenario' => 'correction']));
        $first = Artisan::output();

        // received 1, accepted 1, created 0, updated 1, unchanged 0, rejected 0, revisions 1
        $this->assertMatchesRegularExpression(
            '/correction\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1/',
            $first,
        );

        $this->assertSame(0, Artisan::call(self::COMMAND, ['--scenario' => 'correction']));
        $second = Artisan::output();

        // The second run changes nothing and records no further history.
        $this->assertMatchesRegularExpression(
            '/correction\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0/',
            $second,
        );

        $this->assertSame(1, MeasurementRevision::query()->count());
    }

    #[Test]
    public function the_scenario_option_is_required(): void
    {
        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--scenario is required', $output);
        $this->assertStringContainsString('base, correction', $output);
        $this->assertSame(0, Measurement::query()->count());
    }

    #[Test]
    public function an_unknown_scenario_loads_nothing(): void
    {
        $exitCode = Artisan::call(self::COMMAND, ['--scenario' => 'everything']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown --scenario', $output);
        $this->assertSame(0, Measurement::query()->count());
    }

    #[Test]
    public function the_command_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $exitCode = Artisan::call(self::COMMAND, ['--scenario' => 'base']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('blocked in production', $output);
        $this->assertSame(0, Measurement::query()->count());
    }

    #[Test]
    public function an_unexpected_failure_reaches_neither_the_console_nor_the_log(): void
    {
        $this->captureLogs();
        Exceptions::fake();

        // A real failure mode rather than a stubbed one: the provider is
        // pointed at a fixture path that does not exist, so the exception
        // message carries that path.
        $this->app->bind(
            FixtureMeasurementProvider::class,
            fn ($app, array $parameters): FixtureMeasurementProvider => new FixtureMeasurementProvider(
                $parameters['scenario'],
                base_path('storage/framework/testing/no-such-measurement-fixture.json'),
            ),
        );

        $exitCode = Artisan::call(self::COMMAND, ['--scenario' => 'base']);
        $errorLine = $this->errorLine(Artisan::output());

        $this->assertSame(1, $exitCode);

        // The exception is neither handed to the framework reporter nor
        // written to the log; only safe structured fields are.
        Exceptions::assertNothingReported();

        $logged = $this->loggedText();
        $this->assertStringContainsString('Synchronization run failed.', $logged);
        $this->assertStringNotContainsString('missing or unreadable', $logged);
        $this->assertStringNotContainsString('no-such-measurement-fixture', $logged);
        $this->assertStringNotContainsString(base_path(), $logged);

        // Asserted on the failure line alone: the banner above it deliberately
        // names the configured origin, which is operator-facing by design.
        $this->assertStringNotContainsString('missing or unreadable', $errorLine);
        $this->assertStringNotContainsString('no-such-measurement-fixture', $errorLine);
        $this->assertStringNotContainsString('.json', $errorLine);
        $this->assertStringNotContainsString('.php', $errorLine);
        $this->assertStringNotContainsString(base_path(), $errorLine);

        // The operator is told what happened and what to do next, without a
        // claim that the batch was left untouched.
        $this->assertStringContainsString('stopped on an unexpected error', $errorLine);
        $this->assertStringContainsString('may already be stored', $errorLine);
        $this->assertStringContainsString('idempotent', $errorLine);
        $this->assertStringNotContainsString('nothing was stored', $errorLine);
    }

    #[Test]
    public function each_invocation_is_journalled_as_its_own_synchronization_run(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'base']);
        Artisan::output();
        Artisan::call(self::COMMAND, ['--scenario' => 'base']);
        Artisan::output();
        Artisan::call(self::COMMAND, ['--scenario' => 'correction']);
        Artisan::output();

        $runs = SynchronizationRun::query()->orderBy('id')->get();

        $this->assertCount(3, $runs);

        foreach ($runs as $run) {
            $this->assertSame(SynchronizationKind::Measurements, $run->kind);
            $this->assertSame('fixture', $run->source->code);
            $this->assertNotNull($run->finished_at);
        }

        [$firstBase, $repeatedBase, $correction] = $runs->all();

        // Both base runs quarantined the same broken row; the correction batch
        // has none.
        $this->assertSame(SynchronizationStatus::Partial, $firstBase->status);
        $this->assertSame(SynchronizationStatus::Partial, $repeatedBase->status);
        $this->assertSame(SynchronizationStatus::Succeeded, $correction->status);

        $this->assertSame(8, $firstBase->received_count);
        $this->assertSame(7, $firstBase->accepted_count);
        $this->assertSame(1, $firstBase->rejected_count);

        // The repeat found everything already stored.
        $this->assertSame(0, $repeatedBase->updated_count);
        // The correction applied exactly one change.
        $this->assertSame(1, $correction->updated_count);

        $this->assertSame(2, SynchronizationRejectedRow::query()->count());
        $this->assertSame(7, Measurement::query()->count());
        $this->assertSame(1, MeasurementRevision::query()->count());
    }

    #[Test]
    public function the_console_names_the_run_it_recorded(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'base']);
        $output = Artisan::output();

        $run = SynchronizationRun::query()->sole();

        $this->assertStringContainsString('synchronization run #'.$run->id, $output);
        $this->assertStringContainsString('status "partial"', $output);
    }

    #[Test]
    public function a_failed_run_is_journalled_with_a_safe_error(): void
    {
        $this->app->bind(
            FixtureMeasurementProvider::class,
            fn ($app, array $parameters): FixtureMeasurementProvider => new FixtureMeasurementProvider(
                $parameters['scenario'],
                base_path('storage/framework/testing/no-such-measurement-fixture.json'),
            ),
        );

        $this->assertSame(1, Artisan::call(self::COMMAND, ['--scenario' => 'base']));
        Artisan::output();

        $run = SynchronizationRun::query()->sole();

        $this->assertSame(SynchronizationStatus::Failed, $run->status);
        $this->assertSame(SynchronizationRun::ERROR_UNEXPECTED, $run->error_code);
        $this->assertNotNull($run->finished_at);
        $this->assertStringNotContainsString('.json', (string) $run->sanitized_error);
        $this->assertStringNotContainsString('unreadable', (string) $run->sanitized_error);
    }

    /**
     * The console output from the ERROR marker onwards.
     */
    private function errorLine(string $output): string
    {
        $position = strpos($output, 'ERROR');

        $this->assertNotFalse($position, 'Expected the command to print an error.');

        return substr($output, $position);
    }
}
