<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Data\SynchronizationOutcome;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Exceptions\InvalidSynchronizationOutcome;
use App\Domain\Integrations\Fixtures\FixtureIntegrationSource;
use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureMeasurementScenario;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRejectedRow;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Integrations\Services\SynchronizationRunner;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use App\Domain\Measurements\Services\MeasurementImporter;
use App\Domain\Stations\Models\Station;
use App\Domain\Stations\Services\StationRegistryImporter;
use App\Support\Canonical\RejectedRow;
use App\Support\Canonical\RejectionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CapturesLogs;
use Tests\TestCase;

class SynchronizationRunnerTest extends TestCase
{
    use CapturesLogs, RefreshDatabase;

    private SynchronizationRunner $runner;

    private IntegrationSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new SynchronizationRunner;
        $this->source = FixtureIntegrationSource::ensure();
    }

    #[Test]
    public function a_clean_import_is_journalled_as_succeeded(): void
    {
        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => SynchronizationOutcome::make(5, 5, 2, []),
        );

        $run->refresh();

        $this->assertSame(SynchronizationStatus::Succeeded, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertTrue($run->finished_at->greaterThanOrEqualTo($run->started_at));
        $this->assertSame(5, $run->received_count);
        $this->assertSame(5, $run->accepted_count);
        $this->assertSame(2, $run->updated_count);
        $this->assertSame(0, $run->rejected_count);
        $this->assertNull($run->error_code);
        $this->assertNull($run->sanitized_error);
        $this->assertSame(0, SynchronizationRejectedRow::query()->count());
    }

    #[Test]
    public function an_import_with_quarantined_rows_is_journalled_as_partial(): void
    {
        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => SynchronizationOutcome::make(4, 3, 0, [
                RejectedRow::make('fixture:row-1', RejectionReason::UnknownStation, 'No station is registered.'),
            ]),
        );

        $run->refresh();

        $this->assertSame(SynchronizationStatus::Partial, $run->status);
        $this->assertSame(4, $run->received_count);
        $this->assertSame(3, $run->accepted_count);
        $this->assertSame(1, $run->rejected_count);
        $this->assertNull($run->error_code);

        $rejected = $run->rejectedRows()->sole();
        $this->assertSame('fixture:row-1', $rejected->reference);
        $this->assertSame(RejectionReason::UnknownStation, $rejected->reason_code);
        $this->assertSame('No station is registered.', $rejected->safe_detail);
    }

    /**
     * An exception carrying every kind of thing that must never be written
     * down: a password, a DSN, an Authorization header, a chunk of raw payload,
     * a hostname and a stack frame.
     */
    private function leakyException(): RuntimeException
    {
        return new RuntimeException(
            'SQLSTATE[08006] could not connect to pgsql:host=hydromet-db-01.internal;dbname=hydromet '
            .'user=svc_reader password=s3cr3t-P4ssw0rd Authorization: Bearer tok_live_9f3a2b '
            ."payload={\"station_external_id\":\"fixture-station-001\",\"value\":23.4}\n"
            ."#0 /var/www/html/app/Domain/Integrations/Http/Client.php(42): connect()\n"
            .'#1 /var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php(70)'
        );
    }

    /**
     * @return list<string>
     */
    private function forbiddenFragments(): array
    {
        return [
            's3cr3t-P4ssw0rd',
            'svc_reader',
            'hydromet-db-01.internal',
            'SQLSTATE',
            'pgsql:host=',
            'Bearer tok_live_9f3a2b',
            'Authorization',
            'fixture-station-001',
            '23.4',
            '#0 ',
            '#1 ',
            '/var/www/html',
            'Connector.php',
        ];
    }

    #[Test]
    public function an_unexpected_exception_is_journalled_as_failed_with_a_safe_error_only(): void
    {
        $this->captureLogs();

        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => throw $this->leakyException(),
        );

        $run->refresh();

        $this->assertSame(SynchronizationStatus::Failed, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(SynchronizationRun::ERROR_UNEXPECTED, $run->error_code);
        $this->assertNotNull($run->sanitized_error);

        $journal = $run->error_code.' '.$run->sanitized_error;

        foreach ($this->forbiddenFragments() as $secret) {
            $this->assertStringNotContainsString($secret, $journal);
        }
    }

    #[Test]
    public function the_exception_itself_never_reaches_the_log(): void
    {
        $this->captureLogs();

        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => throw $this->leakyException(),
        );

        $logged = $this->loggedText();

        // The failure is recorded, so the silence below is not simply "nothing
        // was logged at all".
        $this->assertStringContainsString('Synchronization run failed.', $logged);

        foreach ($this->forbiddenFragments() as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }

        // Only the four safe fields, and the class name that identifies the
        // kind of failure without quoting what it carried.
        $messages = $this->loggedMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('error', $messages[0]->level);
        $this->assertSame(
            ['run_id', 'source', 'kind', 'exception_class'],
            array_keys($messages[0]->context),
        );
        $this->assertSame($run->id, $messages[0]->context['run_id']);
        $this->assertSame('fixture', $messages[0]->context['source']);
        $this->assertSame('measurements', $messages[0]->context['kind']);
        $this->assertSame(RuntimeException::class, $messages[0]->context['exception_class']);
    }

    #[Test]
    public function the_exception_is_not_handed_to_the_framework_reporter(): void
    {
        // report() would send the message and trace to the configured channels
        // and to any error tracker wired into the handler.
        Exceptions::fake();

        $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => throw $this->leakyException(),
        );

        Exceptions::assertNothingReported();
    }

    #[Test]
    public function the_safe_error_does_not_promise_details_the_log_does_not_hold(): void
    {
        $this->captureLogs();

        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => throw $this->leakyException(),
        );

        // The log keeps no cause, so the operator-facing text must not send
        // anyone looking for one.
        $this->assertStringNotContainsString('Details are in the application log', (string) $run->sanitized_error);
        $this->assertStringContainsString('Re-run the import', (string) $run->sanitized_error);
    }

    #[Test]
    public function the_run_is_open_and_visible_before_the_provider_is_read(): void
    {
        $statusDuringWork = null;
        $idDuringWork = null;

        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::StationRegistry,
            function (SynchronizationRun $open) use (&$statusDuringWork, &$idDuringWork): SynchronizationOutcome {
                // Read back from the database, not from the passed instance, so
                // this proves the row was committed before the work began.
                $stored = SynchronizationRun::query()->findOrFail($open->id);
                $statusDuringWork = $stored->status;
                $idDuringWork = $stored->id;

                return SynchronizationOutcome::make(1, 1, 0, []);
            },
        );

        $this->assertSame(SynchronizationStatus::Running, $statusDuringWork);
        $this->assertSame($run->id, $idDuringWork);
        $this->assertSame(SynchronizationStatus::Succeeded, $run->refresh()->status);
    }

    #[Test]
    public function a_failed_run_keeps_the_rows_that_were_imported_before_it_stopped(): void
    {
        $this->captureLogs();

        // A real import first, so there is committed data to lose.
        $this->runner->run(
            $this->source,
            SynchronizationKind::StationRegistry,
            fn (): SynchronizationOutcome => SynchronizationOutcome::fromStationRegistry(
                (new StationRegistryImporter)->import(new FixtureStationRegistryProvider),
            ),
        );

        $stationsBefore = Station::query()->count();
        $this->assertSame(3, $stationsBefore);

        $this->runner->run(
            $this->source,
            SynchronizationKind::StationRegistry,
            function (): SynchronizationOutcome {
                // Import something, then fail: stations are the system of
                // record and must not be rolled back by the journal.
                (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);

                throw new RuntimeException('Provider vanished mid-read.');
            },
        );

        $this->assertSame($stationsBefore, Station::query()->count());
        $this->assertSame(
            SynchronizationStatus::Failed,
            SynchronizationRun::query()->orderByDesc('id')->firstOrFail()->status,
        );
    }

    #[Test]
    public function the_station_registry_outcome_sums_both_collections(): void
    {
        $report = (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);
        $outcome = SynchronizationOutcome::fromStationRegistry($report);

        // 5 parameters + 4 station rows, of which one station is rejected.
        $this->assertSame(9, $outcome->received);
        $this->assertSame(8, $outcome->accepted);
        $this->assertSame(1, $outcome->rejected());
        $this->assertTrue($outcome->isPartial());
    }

    #[Test]
    public function the_measurement_outcome_carries_the_import_counters(): void
    {
        (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);

        $result = (new MeasurementImporter)->import(
            new FixtureMeasurementProvider(FixtureMeasurementScenario::Base),
        );
        $outcome = SynchronizationOutcome::fromMeasurements($result);

        $this->assertSame(8, $outcome->received);
        $this->assertSame(7, $outcome->accepted);
        $this->assertSame(1, $outcome->rejected());
    }

    #[Test]
    public function each_attempt_is_a_separate_run_and_no_data_is_duplicated(): void
    {
        $import = fn (): SynchronizationOutcome => SynchronizationOutcome::fromStationRegistry(
            (new StationRegistryImporter)->import(new FixtureStationRegistryProvider),
        );

        $first = $this->runner->run($this->source, SynchronizationKind::StationRegistry, $import);
        $second = $this->runner->run($this->source, SynchronizationKind::StationRegistry, $import);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, SynchronizationRun::query()->count());
        $this->assertSame(3, Station::query()->count());

        // The second attempt found everything already stored.
        $this->assertSame(0, $second->refresh()->updated_count);
        $this->assertSame(8, $second->accepted_count);
        $this->assertSame(1, $second->rejected_count);

        // Each partial run keeps its own copy of what it quarantined.
        $this->assertSame(2, SynchronizationRejectedRow::query()->count());
        $this->assertSame(1, $first->rejectedRows()->count());
        $this->assertSame(1, $second->rejectedRows()->count());
    }

    #[Test]
    public function a_repeated_measurement_run_records_no_new_revisions(): void
    {
        (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);

        $importBase = fn (): SynchronizationOutcome => SynchronizationOutcome::fromMeasurements(
            (new MeasurementImporter)->import(new FixtureMeasurementProvider(FixtureMeasurementScenario::Base)),
        );
        $importCorrection = fn (): SynchronizationOutcome => SynchronizationOutcome::fromMeasurements(
            (new MeasurementImporter)->import(new FixtureMeasurementProvider(FixtureMeasurementScenario::Correction)),
        );

        $this->runner->run($this->source, SynchronizationKind::Measurements, $importBase);
        $this->runner->run($this->source, SynchronizationKind::Measurements, $importBase);
        $this->runner->run($this->source, SynchronizationKind::Measurements, $importCorrection);
        $lastCorrection = $this->runner->run($this->source, SynchronizationKind::Measurements, $importCorrection);

        $this->assertSame(4, SynchronizationRun::query()->count());
        $this->assertSame(7, Measurement::query()->count());
        $this->assertSame(1, MeasurementRevision::query()->count());

        // Nothing left to apply, so the last run updated nothing.
        $this->assertSame(0, $lastCorrection->refresh()->updated_count);
        $this->assertSame(SynchronizationStatus::Succeeded, $lastCorrection->status);
    }

    #[Test]
    public function the_journal_records_the_source_and_can_be_read_back_through_relations(): void
    {
        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => SynchronizationOutcome::make(2, 1, 0, [
                RejectedRow::make('fixture:row-9', RejectionReason::UnitMismatch, 'Unit is not the canonical unit.'),
            ]),
        );

        $this->assertSame($this->source->id, $run->source->id);
        $this->assertSame('fixture', $run->source->code);
        $this->assertTrue($this->source->synchronizationRuns()->whereKey($run->id)->exists());

        $rejected = SynchronizationRejectedRow::query()->sole();
        $this->assertSame($run->id, $rejected->run->id);
    }

    #[Test]
    public function quarantined_rows_stay_sanitized_when_they_reach_the_journal(): void
    {
        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => SynchronizationOutcome::make(1, 0, 0, [
                RejectedRow::make(
                    "row\n1\treference",
                    RejectionReason::MalformedRow,
                    "line one\r\nline two\ttabbed",
                ),
            ]),
        );

        $rejected = $run->rejectedRows()->sole();

        $this->assertSame('row 1 reference', $rejected->reference);
        $this->assertSame('line one line two tabbed', $rejected->safe_detail);
        $this->assertDoesNotMatchRegularExpression('/\R/', $rejected->reference);
        $this->assertDoesNotMatchRegularExpression('/\R/', $rejected->safe_detail);
    }

    /**
     * @return array<string, array{int, int, int, int, string}>
     */
    public static function incoherentCounters(): array
    {
        // received, accepted, updated, rejection count, expected message part
        return [
            'negative received' => [-1, 0, 0, 0, 'cannot be negative'],
            'negative accepted' => [0, -1, 0, 0, 'cannot be negative'],
            'negative updated' => [0, 0, -1, 0, 'cannot be negative'],
            'received exceeds the parts' => [5, 3, 0, 1, 'do not add up'],
            'rejections not counted in received' => [3, 3, 0, 1, 'do not add up'],
            'rejections with nothing received' => [0, 0, 0, 1, 'do not add up'],
            'accepted without anything received' => [0, 2, 0, 0, 'do not add up'],
            'more updated than accepted' => [3, 3, 4, 0, 'More rows were updated than accepted'],
        ];
    }

    #[Test]
    #[DataProvider('incoherentCounters')]
    public function an_incoherent_outcome_is_refused_before_a_run_is_written(
        int $received,
        int $accepted,
        int $updated,
        int $rejectionCount,
        string $expectedMessage,
    ): void {
        $rejections = [];

        for ($index = 0; $index < $rejectionCount; $index++) {
            $rejections[] = RejectedRow::make(
                'fixture:row-'.$index,
                RejectionReason::MalformedRow,
                'Row could not be read.',
            );
        }

        try {
            SynchronizationOutcome::make($received, $accepted, $updated, $rejections);
            $this->fail('Expected the outcome to be refused.');
        } catch (InvalidSynchronizationOutcome $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }

        // Nothing was written, on any driver.
        $this->assertSame(0, SynchronizationRun::query()->count());
    }

    #[Test]
    public function a_run_whose_import_reports_incoherent_counters_is_journalled_as_failed(): void
    {
        $this->captureLogs();

        $run = $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => SynchronizationOutcome::make(5, 3, 0, []),
        );

        $run->refresh();

        // The run row already existed, so the defect is recorded rather than
        // silently discarded — but no incoherent counters were stored.
        $this->assertSame(SynchronizationStatus::Failed, $run->status);
        $this->assertSame(0, $run->received_count);
        $this->assertSame(0, $run->accepted_count);
        $this->assertSame(0, $run->rejected_count);
        $this->assertSame(
            InvalidSynchronizationOutcome::class,
            $this->loggedMessages()[0]->context['exception_class'],
        );
    }

    #[Test]
    public function both_import_factories_run_through_the_same_validation(): void
    {
        // The real reports are coherent, so the guarantee is that they are
        // checked at all rather than trusted.
        $registry = SynchronizationOutcome::fromStationRegistry(
            (new StationRegistryImporter)->import(new FixtureStationRegistryProvider),
        );

        $this->assertSame($registry->received, $registry->accepted + $registry->rejected());
        $this->assertLessThanOrEqual($registry->accepted, $registry->updated);

        $measurements = SynchronizationOutcome::fromMeasurements(
            (new MeasurementImporter)->import(new FixtureMeasurementProvider(FixtureMeasurementScenario::Base)),
        );

        $this->assertSame($measurements->received, $measurements->accepted + $measurements->rejected());
        $this->assertLessThanOrEqual($measurements->accepted, $measurements->updated);
    }

    #[Test]
    public function the_fixture_source_is_not_enabled_for_a_future_scheduler(): void
    {
        $source = FixtureIntegrationSource::ensure();

        $this->assertFalse($source->enabled);
        $this->assertSame('fixture', $source->type);
        $this->assertNull($source->base_url);
        $this->assertSame('none', $source->authentication_type);
        $this->assertNull($source->polling_interval_seconds);
        $this->assertSame('none', $source->cursor_strategy);
    }

    #[Test]
    public function a_disabled_source_still_runs_when_an_operator_asks_for_it(): void
    {
        $disabled = IntegrationSource::factory()->disabled()->create();

        $run = $this->runner->run(
            $disabled,
            SynchronizationKind::StationRegistry,
            fn (): SynchronizationOutcome => SynchronizationOutcome::fromStationRegistry(
                (new StationRegistryImporter)->import(new FixtureStationRegistryProvider),
            ),
        );

        // `enabled` gates automatic polling, not a manual run.
        $this->assertFalse($disabled->refresh()->enabled);
        $this->assertSame(SynchronizationStatus::Partial, $run->refresh()->status);
        $this->assertSame(3, Station::query()->count());
    }

    #[Test]
    public function the_whole_journal_is_free_of_payloads_secrets_and_traces(): void
    {
        (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);

        $this->runner->run(
            $this->source,
            SynchronizationKind::Measurements,
            fn (): SynchronizationOutcome => SynchronizationOutcome::fromMeasurements(
                (new MeasurementImporter)->import(new FixtureMeasurementProvider(FixtureMeasurementScenario::Base)),
            ),
        );

        $stored = [];

        foreach (SynchronizationRun::query()->get() as $run) {
            $stored[] = implode(' ', [$run->error_code ?? '', $run->sanitized_error ?? '', $run->response_checksum ?? '']);
        }

        foreach (SynchronizationRejectedRow::query()->get() as $row) {
            $stored[] = $row->reference.' '.$row->reason_code->value.' '.$row->safe_detail;
        }

        foreach (IntegrationSource::query()->get() as $source) {
            $stored[] = (string) $source->base_url;
        }

        $journal = implode("\n", $stored);

        // A stack frame, a SQL statement, a URL query string and a credential
        // are each a way the journal could leak; none may appear.
        foreach (['#0 ', 'Stack trace', 'select * from', 'SQLSTATE', '?', 'password', 'api_key=', 'Bearer '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $journal);
        }

        $this->assertStringNotContainsString('.php', $journal);
        $this->assertStringNotContainsString(base_path(), $journal);
    }
}
