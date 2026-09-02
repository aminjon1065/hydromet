<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Fixtures\FixtureAlertProvider;
use App\Domain\Integrations\Models\SynchronizationRejectedRow;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Support\Canonical\RejectionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CapturesLogs;
use Tests\TestCase;

class ImportFixtureAlertsCommandTest extends TestCase
{
    use CapturesLogs, RefreshDatabase;

    private const COMMAND = 'alerts:import-fixture-feed';

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
    public function the_scenario_option_is_required(): void
    {
        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--scenario is required', $output);
        $this->assertStringContainsString('baseline, lifecycle', $output);
        $this->assertSame(0, AlertMessage::query()->count());
    }

    #[Test]
    public function an_unknown_scenario_loads_nothing(): void
    {
        $exitCode = Artisan::call(self::COMMAND, ['--scenario' => 'everything']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown --scenario', $output);
        $this->assertSame(0, AlertMessage::query()->count());
        // A rejected option is not an import attempt, so nothing is journalled.
        $this->assertSame(0, SynchronizationRun::query()->count());
    }

    #[Test]
    public function the_baseline_scenario_imports_and_reports_a_partial_result(): void
    {
        $exitCode = Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        $output = Artisan::output();

        // The baseline feed always carries one undrawable area, so a non-zero
        // exit here would mean a partial import is treated as a failure and the
        // four storable warnings would look lost.
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MOCK', $output);
        $this->assertStringContainsString('Partial result', $output);
        $this->assertStringContainsString('Superseded', $output);
        $this->assertStringContainsString(RejectionReason::UnsupportedGeometry->value, $output);
        $this->assertStringContainsString('fixture-alert-0005', $output);

        // received 5, accepted 4, created 4, updated 0, unchanged 0, rejected 1, superseded 0
        $this->assertMatchesRegularExpression(
            '/baseline\s*\|\s*5\s*\|\s*4\s*\|\s*4\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*0/',
            $output,
        );

        $this->assertSame(4, AlertMessage::query()->count());
        // The rejected message took its area with it; the four stored ones keep
        // theirs, including the multi-area warning.
        $this->assertSame(5, AlertArea::query()->count());
        $this->assertNull(AlertMessage::query()->where('identifier', 'fixture-alert-0005')->first());
    }

    #[Test]
    public function running_the_baseline_scenario_twice_stores_nothing_further(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        Artisan::output();

        $messages = AlertMessage::query()->count();
        $areas = AlertArea::query()->count();

        $this->assertSame(0, Artisan::call(self::COMMAND, ['--scenario' => 'baseline']));
        $output = Artisan::output();

        $this->assertSame($messages, AlertMessage::query()->count());
        $this->assertSame($areas, AlertArea::query()->count());

        // received 5, accepted 4, created 0, updated 0, unchanged 4, rejected 1, superseded 0
        $this->assertMatchesRegularExpression(
            '/baseline\s*\|\s*5\s*\|\s*4\s*\|\s*0\s*\|\s*0\s*\|\s*4\s*\|\s*1\s*\|\s*0/',
            $output,
        );
    }

    #[Test]
    public function the_lifecycle_scenario_supersedes_its_references_exactly_once(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        Artisan::output();

        $this->assertSame(0, Artisan::call(self::COMMAND, ['--scenario' => 'lifecycle']));
        $first = Artisan::output();

        // received 2, accepted 2, created 2, updated 0, unchanged 0, rejected 0, superseded 2
        $this->assertMatchesRegularExpression(
            '/lifecycle\s*\|\s*2\s*\|\s*2\s*\|\s*2\s*\|\s*0\s*\|\s*0\s*\|\s*0\s*\|\s*2/',
            $first,
        );

        $this->assertSame(0, Artisan::call(self::COMMAND, ['--scenario' => 'lifecycle']));
        $second = Artisan::output();

        // The second pass finds both predecessors already withdrawn. A non-zero
        // superseded count here would mean a re-read of an unchanged feed
        // re-attributes a withdrawal, rewriting published history.
        $this->assertMatchesRegularExpression(
            '/lifecycle\s*\|\s*2\s*\|\s*2\s*\|\s*0\s*\|\s*0\s*\|\s*2\s*\|\s*0\s*\|\s*0/',
            $second,
        );

        $this->assertSame(6, AlertMessage::query()->count());

        $updated = AlertMessage::query()->where('identifier', 'fixture-alert-0001')->sole();
        $cancelled = AlertMessage::query()->where('identifier', 'fixture-alert-0002')->sole();

        $this->assertTrue($updated->isSuperseded());
        $this->assertTrue($cancelled->isSuperseded());
    }

    #[Test]
    public function each_invocation_is_journalled_as_its_own_synchronization_run(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        Artisan::output();
        Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        Artisan::output();
        Artisan::call(self::COMMAND, ['--scenario' => 'lifecycle']);
        Artisan::output();

        $runs = SynchronizationRun::query()->orderBy('id')->get();

        $this->assertCount(3, $runs);

        foreach ($runs as $run) {
            $this->assertSame(SynchronizationKind::Alerts, $run->kind);
            $this->assertSame('fixture', $run->source->code);
            $this->assertNotNull($run->finished_at);
        }

        [$firstBaseline, $repeatedBaseline, $lifecycle] = $runs->all();

        // Both baseline runs quarantined the same undrawable message; the
        // lifecycle feed has none.
        $this->assertSame(SynchronizationStatus::Partial, $firstBaseline->status);
        $this->assertSame(SynchronizationStatus::Partial, $repeatedBaseline->status);
        $this->assertSame(SynchronizationStatus::Succeeded, $lifecycle->status);

        $this->assertSame(5, $firstBaseline->received_count);
        $this->assertSame(4, $firstBaseline->accepted_count);
        $this->assertSame(1, $firstBaseline->rejected_count);

        // A repeat reports the same received and accepted totals: the journal
        // must show that the feed was re-read in full, not that it shrank.
        $this->assertSame(5, $repeatedBaseline->received_count);
        $this->assertSame(4, $repeatedBaseline->accepted_count);
        $this->assertSame(1, $repeatedBaseline->rejected_count);

        $this->assertSame(2, $lifecycle->received_count);
        $this->assertSame(2, $lifecycle->accepted_count);
        $this->assertSame(0, $lifecycle->rejected_count);

        $this->assertSame(2, SynchronizationRejectedRow::query()->count());
    }

    #[Test]
    public function the_console_names_the_run_it_recorded(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        $output = Artisan::output();

        $run = SynchronizationRun::query()->sole();

        $this->assertStringContainsString('synchronization run #'.$run->id, $output);
        $this->assertStringContainsString('status "partial"', $output);
    }

    #[Test]
    public function the_rejected_message_is_quarantined_with_a_safe_detail(): void
    {
        Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        Artisan::output();

        $rejection = SynchronizationRejectedRow::query()->sole();

        $this->assertSame(RejectionReason::UnsupportedGeometry, $rejection->reason_code);
        $this->assertStringContainsString('fixture-alert-0005', $rejection->reference);

        // The stored detail is operator-facing text, not a trace: it must name
        // no source file, no deployment path and no line break that could
        // forge a second entry in a log or console table.
        $this->assertStringNotContainsString('.php', $rejection->safe_detail);
        $this->assertStringNotContainsString(base_path(), $rejection->safe_detail);
        $this->assertStringNotContainsString("\n", $rejection->safe_detail);
        $this->assertStringNotContainsString("\r", $rejection->safe_detail);
        $this->assertStringNotContainsString("\n", $rejection->reference);
    }

    #[Test]
    public function the_command_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $exitCode = Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('blocked in production', $output);
        $this->assertSame(0, AlertMessage::query()->count());
        $this->assertSame(0, SynchronizationRun::query()->count());
    }

    #[Test]
    public function an_unexpected_failure_reaches_neither_the_console_nor_the_log(): void
    {
        $this->captureLogs();
        Exceptions::fake();

        $this->bindMissingFixture();

        $exitCode = Artisan::call(self::COMMAND, ['--scenario' => 'baseline']);
        $errorLine = $this->errorLine(Artisan::output());

        $this->assertSame(1, $exitCode);

        // The exception is neither handed to the framework reporter nor
        // written to the log; only safe structured fields are.
        Exceptions::assertNothingReported();

        $logged = $this->loggedText();
        $this->assertStringContainsString('Synchronization run failed.', $logged);
        $this->assertStringContainsString('alerts', $logged);
        $this->assertStringNotContainsString('missing or unreadable', $logged);
        $this->assertStringNotContainsString('no-such-alert-fixture', $logged);
        $this->assertStringNotContainsString(base_path(), $logged);

        // Asserted on the failure line alone: the banner above it deliberately
        // names the configured origin, which is operator-facing by design.
        $this->assertStringNotContainsString('missing or unreadable', $errorLine);
        $this->assertStringNotContainsString('no-such-alert-fixture', $errorLine);
        $this->assertStringNotContainsString('.json', $errorLine);
        $this->assertStringNotContainsString('.php', $errorLine);
        $this->assertStringNotContainsString(base_path(), $errorLine);

        // The operator is told what happened and what to do next, without a
        // claim that the feed was left untouched.
        $this->assertStringContainsString('stopped on an unexpected error', $errorLine);
        $this->assertStringContainsString('may already be stored', $errorLine);
        $this->assertStringContainsString('idempotent', $errorLine);
        $this->assertStringNotContainsString('nothing was stored', $errorLine);
    }

    #[Test]
    public function a_failed_run_is_journalled_with_a_safe_error(): void
    {
        $this->bindMissingFixture();

        $this->assertSame(1, Artisan::call(self::COMMAND, ['--scenario' => 'baseline']));
        Artisan::output();

        $run = SynchronizationRun::query()->sole();

        $this->assertSame(SynchronizationKind::Alerts, $run->kind);
        $this->assertSame(SynchronizationStatus::Failed, $run->status);
        $this->assertSame(SynchronizationRun::ERROR_UNEXPECTED, $run->error_code);
        // Even a failed attempt is closed, so an operator never sees a run that
        // looks like it is still going.
        $this->assertNotNull($run->finished_at);
        $this->assertStringNotContainsString('.json', (string) $run->sanitized_error);
        $this->assertStringNotContainsString('unreadable', (string) $run->sanitized_error);
        $this->assertSame(0, AlertMessage::query()->count());
    }

    /**
     * A real failure mode rather than a stubbed one: the provider is pointed at
     * a fixture path that does not exist, so the exception message carries that
     * path.
     */
    private function bindMissingFixture(): void
    {
        $this->app->bind(
            FixtureAlertProvider::class,
            fn ($app, array $parameters): FixtureAlertProvider => new FixtureAlertProvider(
                $parameters['scenario'],
                base_path('storage/framework/testing/no-such-alert-fixture.json'),
            ),
        );
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
