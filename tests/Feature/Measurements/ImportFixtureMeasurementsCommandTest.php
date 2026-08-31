<?php

namespace Tests\Feature\Measurements;

use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use App\Domain\Stations\Services\StationRegistryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ImportFixtureMeasurementsCommandTest extends TestCase
{
    use RefreshDatabase;

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
    public function an_unexpected_failure_is_logged_and_reported_without_the_exception_message(): void
    {
        Exceptions::fake();

        // A real failure mode rather than a stubbed one: the provider is
        // pointed at a fixture path that does not exist.
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
        Exceptions::assertReported(RuntimeException::class);

        // The exception itself reaches the log, never the console. Asserted on
        // the failure line alone: the banner above it deliberately names the
        // configured origin, which is operator-facing by design.
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
