<?php

namespace Tests\Feature\Stations;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRejectedRow;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use App\Support\Canonical\RejectionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CapturesLogs;
use Tests\TestCase;

class ImportFixtureStationRegistryCommandTest extends TestCase
{
    use CapturesLogs, RefreshDatabase;

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
    public function an_unexpected_failure_reaches_neither_the_console_nor_the_log(): void
    {
        $this->captureLogs();
        Exceptions::fake();

        // A real failure mode rather than a stubbed one: the provider is
        // pointed at a fixture path that does not exist, so the exception
        // message carries that path.
        $this->app->bind(
            FixtureStationRegistryProvider::class,
            fn (): FixtureStationRegistryProvider => new FixtureStationRegistryProvider(
                base_path('storage/framework/testing/no-such-fixture.json'),
            ),
        );

        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);

        // The exception is neither handed to the framework reporter nor
        // written to the log; only safe structured fields are.
        Exceptions::assertNothingReported();

        $logged = $this->loggedText();
        $this->assertStringContainsString('Synchronization run failed.', $logged);
        $this->assertStringNotContainsString('missing or unreadable', $logged);
        $this->assertStringNotContainsString('no-such-fixture', $logged);
        $this->assertStringNotContainsString(base_path(), $logged);

        // Asserted on the failure line alone: the banner above it deliberately
        // names the configured origin, which is operator-facing by design.
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

    #[Test]
    public function each_invocation_is_journalled_as_its_own_synchronization_run(): void
    {
        Artisan::call(self::COMMAND);
        Artisan::output();
        Artisan::call(self::COMMAND);
        Artisan::output();

        $runs = SynchronizationRun::query()->orderBy('id')->get();

        $this->assertCount(2, $runs);

        foreach ($runs as $run) {
            $this->assertSame(SynchronizationKind::StationRegistry, $run->kind);
            $this->assertSame('fixture', $run->source->code);
            $this->assertSame(SynchronizationStatus::Partial, $run->status);
            $this->assertNotNull($run->finished_at);
            // 5 catalogue rows plus 4 station rows, one of which is rejected.
            $this->assertSame(9, $run->received_count);
            $this->assertSame(8, $run->accepted_count);
            $this->assertSame(1, $run->rejected_count);
        }

        $this->assertSame(2, SynchronizationRejectedRow::query()->count());
        $this->assertSame(3, Station::query()->count());
        $this->assertSame(5, Parameter::query()->count());

        // Only one source row, however many times the command runs.
        $this->assertSame(1, IntegrationSource::query()->count());
    }

    #[Test]
    public function the_journal_keeps_the_safe_rejection_detail(): void
    {
        Artisan::call(self::COMMAND);
        Artisan::output();

        $rejected = SynchronizationRejectedRow::query()->sole();

        $this->assertSame(RejectionReason::LatitudeOutOfRange, $rejected->reason_code);
        $this->assertSame('fixture:fixture-station-004', $rejected->reference);
        $this->assertStringContainsString('outside -90..90', $rejected->safe_detail);
        $this->assertStringNotContainsString('.php', $rejected->safe_detail);
        $this->assertDoesNotMatchRegularExpression('/\R/', $rejected->safe_detail);
    }

    #[Test]
    public function the_console_names_the_run_it_recorded(): void
    {
        Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $run = SynchronizationRun::query()->sole();

        $this->assertStringContainsString('synchronization run #'.$run->id, $output);
        $this->assertStringContainsString('status "partial"', $output);
    }

    #[Test]
    public function a_failed_run_is_journalled_with_a_safe_error(): void
    {
        $this->app->bind(
            FixtureStationRegistryProvider::class,
            fn (): FixtureStationRegistryProvider => new FixtureStationRegistryProvider(
                base_path('storage/framework/testing/no-such-fixture.json'),
            ),
        );

        $this->assertSame(1, Artisan::call(self::COMMAND));
        Artisan::output();

        $run = SynchronizationRun::query()->sole();

        $this->assertSame(SynchronizationStatus::Failed, $run->status);
        $this->assertSame(SynchronizationRun::ERROR_UNEXPECTED, $run->error_code);
        $this->assertNotNull($run->finished_at);
        $this->assertStringNotContainsString('.json', (string) $run->sanitized_error);
        $this->assertStringNotContainsString('unreadable', (string) $run->sanitized_error);
        $this->assertSame(0, SynchronizationRejectedRow::query()->count());
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
