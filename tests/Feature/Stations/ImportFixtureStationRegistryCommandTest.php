<?php

namespace Tests\Feature\Stations;

use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ImportFixtureStationRegistryCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'stations:import-fixture-registry';

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
    public function the_first_run_imports_the_fixture_and_reports_a_partial_result(): void
    {
        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MOCK', $output);
        $this->assertStringContainsString('Partial result', $output);
        // The counters an operator reads off the report.
        $this->assertStringContainsString('Rejected', $output);
        $this->assertStringContainsString('latitude_out_of_range', $output);

        $this->assertSame(5, Parameter::query()->count());
        $this->assertSame(3, Station::query()->count());
        $this->assertSame(8, DB::table('station_parameter')->count());
    }

    #[Test]
    public function a_rejected_row_does_not_make_the_command_fail(): void
    {
        // The fixture always contains exactly one deliberately broken row, so a
        // non-zero exit here would mean a partial import is treated as failure.
        $this->assertSame(0, Artisan::call(self::COMMAND));
    }

    #[Test]
    public function running_the_command_twice_leaves_the_same_number_of_rows(): void
    {
        $this->assertSame(0, Artisan::call(self::COMMAND));

        $stations = Station::query()->count();
        $parameters = Parameter::query()->count();
        $links = DB::table('station_parameter')->count();

        $this->assertSame(0, Artisan::call(self::COMMAND));

        $this->assertSame($stations, Station::query()->count());
        $this->assertSame($parameters, Parameter::query()->count());
        $this->assertSame($links, DB::table('station_parameter')->count());
    }

    #[Test]
    public function the_second_run_reports_every_row_as_unchanged(): void
    {
        Artisan::call(self::COMMAND);
        Artisan::output();

        Artisan::call(self::COMMAND);
        $output = Artisan::output();

        // parameters: 5 received, 5 accepted, 0 created, 0 updated, 5 unchanged
        $this->assertMatchesRegularExpression('/parameters\s*\|\s*5\s*\|\s*5\s*\|\s*0\s*\|\s*0\s*\|\s*5\s*\|\s*0/', $output);
        // stations: 4 received, 3 accepted, 0 created, 0 updated, 3 unchanged, 1 rejected
        $this->assertMatchesRegularExpression('/stations\s*\|\s*4\s*\|\s*3\s*\|\s*0\s*\|\s*0\s*\|\s*3\s*\|\s*1/', $output);
    }

    #[Test]
    public function the_command_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertSame(1, Artisan::call(self::COMMAND));
        $this->assertSame(0, Station::query()->count());
    }

    #[Test]
    public function an_unexpected_failure_is_logged_and_reported_without_the_exception_message(): void
    {
        Exceptions::fake();

        // A real failure mode rather than a stubbed one: the provider is
        // pointed at a fixture path that does not exist.
        $this->app->bind(
            FixtureStationRegistryProvider::class,
            fn (): FixtureStationRegistryProvider => new FixtureStationRegistryProvider(
                base_path('storage/framework/testing/no-such-fixture.json'),
            ),
        );

        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);

        // The exception itself reaches the log, never the console. Asserted on
        // the failure line alone: the banner above it deliberately names the
        // configured origin, which is operator-facing by design.
        Exceptions::assertReported(RuntimeException::class);

        $errorLine = $this->errorLine($output);
        $this->assertStringNotContainsString('missing or unreadable', $errorLine);
        $this->assertStringNotContainsString('no-such-fixture', $errorLine);
        $this->assertStringNotContainsString('.json', $errorLine);
        $this->assertStringNotContainsString(base_path(), $errorLine);

        // The operator is told what actually happened and what to do next.
        $this->assertStringContainsString('stopped on an unexpected error', $errorLine);
        $this->assertStringContainsString('may already be stored', $errorLine);
        $this->assertStringContainsString('idempotent', $errorLine);
        $this->assertStringContainsString('run it again', $errorLine);
    }

    #[Test]
    public function the_failure_message_does_not_claim_that_nothing_was_stored(): void
    {
        Exceptions::fake();

        $this->app->bind(
            FixtureStationRegistryProvider::class,
            fn (): FixtureStationRegistryProvider => new FixtureStationRegistryProvider(
                base_path('storage/framework/testing/no-such-fixture.json'),
            ),
        );

        Artisan::call(self::COMMAND);
        $errorLine = $this->errorLine(Artisan::output());

        // The import writes one row at a time, so a claim that the registry is
        // untouched would mislead an operator into skipping a re-run.
        $this->assertStringNotContainsString('nothing was stored', $errorLine);
        $this->assertStringNotContainsString('nothing was saved', $errorLine);
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
