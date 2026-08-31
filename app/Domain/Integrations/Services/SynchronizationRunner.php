<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Data\SynchronizationOutcome;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Support\Canonical\RejectedRow;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Journals one import attempt, docs/03-data-contracts.md section 8.2.
 *
 * The only writer of `synchronization_runs` and `synchronization_rejected_rows`.
 * It knows nothing about stations or measurements: callers hand it a closure
 * that performs the import and returns the counters
 * ({@see SynchronizationOutcome}), so a new import kind needs no change here.
 *
 * The run row is opened as `running` *before* the closure is invoked, and
 * committed immediately, so a process that dies while reading a provider still
 * leaves a trace of having started.
 *
 * What it deliberately does not do:
 *   - it does not wrap the import in a transaction. Stations and measurements
 *     are the system of record and each row is written on its own, so one bad
 *     row must not roll back the rows around it
 *     (docs/02-architecture.md, section 7);
 *   - it does not put an exception message anywhere. Neither the journal nor
 *     the log receives the exception, its message or its trace; only a stable
 *     code, a safe sentence and the exception's class name are recorded.
 */
final class SynchronizationRunner
{
    /**
     * @param  Closure(SynchronizationRun): SynchronizationOutcome  $work
     */
    public function run(
        IntegrationSource $source,
        SynchronizationKind $kind,
        Closure $work,
    ): SynchronizationRun {
        $run = $this->open($source, $kind);

        try {
            $outcome = $work($run);
        } catch (Throwable $exception) {
            $this->logFailure($run, $source, $kind, $exception);

            $this->close($run, SynchronizationStatus::Failed, SynchronizationOutcome::make(0, 0, 0, []), [
                'error_code' => SynchronizationRun::ERROR_UNEXPECTED,
                'sanitized_error' => 'The synchronization stopped on an unexpected error before it could finish. '
                    .'Rows accepted before that point are stored. Re-run the import once the cause is fixed.',
            ]);

            return $run;
        }

        $status = $outcome->isPartial()
            ? SynchronizationStatus::Partial
            : SynchronizationStatus::Succeeded;

        $this->close($run, $status, $outcome);

        return $run;
    }

    /**
     * Record that a run failed, without letting the exception itself reach the
     * log.
     *
     * The exception is deliberately not passed to `report()` or to the logger.
     * A provider failure message routinely carries the thing that must never be
     * written down: a database DSN with its password, an `Authorization`
     * header, a chunk of the raw payload, or an absolute path that describes
     * the deployment. The class name is kept because it names the kind of
     * failure without quoting anything the failure was carrying.
     *
     * The trade-off is deliberate and visible: the log says which run failed
     * and how it failed in type terms, not why. Reproducing the cause means
     * re-running the import with the provider's own diagnostics, which is why
     * neither the console nor `sanitized_error` promises details here.
     */
    private function logFailure(
        SynchronizationRun $run,
        IntegrationSource $source,
        SynchronizationKind $kind,
        Throwable $exception,
    ): void {
        Log::error('Synchronization run failed.', [
            'run_id' => $run->id,
            'source' => $source->code,
            'kind' => $kind->value,
            'exception_class' => $exception::class,
        ]);
    }

    /**
     * Open the run in its own transaction so the `running` row is durable
     * before any provider is touched.
     */
    private function open(IntegrationSource $source, SynchronizationKind $kind): SynchronizationRun
    {
        return DB::transaction(fn (): SynchronizationRun => SynchronizationRun::query()->create([
            'source_id' => $source->id,
            'kind' => $kind,
            'started_at' => Carbon::now('UTC'),
            'status' => SynchronizationStatus::Running,
        ]));
    }

    /**
     * @param  array<string, string|null>  $errorAttributes
     */
    private function close(
        SynchronizationRun $run,
        SynchronizationStatus $status,
        SynchronizationOutcome $outcome,
        array $errorAttributes = [],
    ): void {
        DB::transaction(function () use ($run, $status, $outcome, $errorAttributes): void {
            $this->storeRejections($run, $outcome->rejections);

            $run->fill([
                'status' => $status,
                'finished_at' => Carbon::now('UTC'),
                'received_count' => $outcome->received,
                'accepted_count' => $outcome->accepted,
                'updated_count' => $outcome->updated,
                'rejected_count' => $outcome->rejected(),
                'response_checksum' => $outcome->responseChecksum,
                ...$errorAttributes,
            ]);

            $run->save();
        });
    }

    /**
     * @param  list<RejectedRow>  $rejections
     */
    private function storeRejections(SynchronizationRun $run, array $rejections): void
    {
        if ($rejections === []) {
            return;
        }

        $now = Carbon::now('UTC');

        $run->rejectedRows()->insert(array_map(
            static fn (RejectedRow $rejection): array => [
                'synchronization_run_id' => $run->id,
                // Already sanitized by RejectedRow: one printable line, capped
                // to the column widths.
                'reference' => $rejection->reference,
                'reason_code' => $rejection->reason->value,
                'safe_detail' => $rejection->detail,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rejections,
        ));
    }
}
