<?php

namespace App\Domain\Integrations\Queries;

use App\Domain\Integrations\Enums\SourceHealth;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Enums\SystemStatus;
use App\Domain\Integrations\Models\IntegrationSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What `/api/v1/system/status` publishes about the portal's external sources.
 *
 * This answers one question for a visitor: can the data on the screen be
 * trusted to be current? It is not a health check of the application — `/up`
 * and `/health` answer that — and it is not a health check of Hydromet either.
 * The portal cannot see whether a provider is up; it can only see whether its
 * own last import of that provider succeeded, and how long ago.
 *
 * Everything published here is deliberately non-sensitive: a source code, a
 * state, a timestamp and the approved threshold. No base URL, producer,
 * authentication type, error text, hostname or counter reaches the response,
 * because this endpoint is public and those describe internal infrastructure
 * (docs/02-architecture.md, section 9).
 *
 * Only `enabled` sources are published. A source an operator has switched off
 * is not something a visitor is waiting on, and the fixture source is disabled
 * for exactly that reason.
 *
 * @phpstan-type PublicSourceStatus array{
 *     code: string,
 *     status: string,
 *     last_success_at: string|null,
 *     stale_after_seconds: int|null
 * }
 * @phpstan-type PublicSystemStatusReport array{
 *     status: string,
 *     generated_at: string,
 *     sources: list<PublicSourceStatus>
 * }
 */
final class PublicSystemStatus
{
    /**
     * Microsecond UTC, matching every other timestamp the API publishes.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * The whole report.
     *
     * `$moment` is taken once and used for every comparison in the response, so
     * two sources cannot be judged against clocks a few milliseconds apart and
     * report states that contradict each other.
     *
     * @return PublicSystemStatusReport
     */
    public function report(?Carbon $moment = null): array
    {
        $moment ??= Carbon::now('UTC');

        $sources = $this->sources($moment);

        return [
            'status' => $this->overall($sources)->value,
            'generated_at' => $moment->utc()->format(self::TIMESTAMP_FORMAT),
            'sources' => $sources,
        ];
    }

    /**
     * One entry per enabled source, ordered by code.
     *
     * Three queries, whatever the number of sources: the sources, the newest
     * finished run of each, and the newest successful finish of each. Asking
     * per source would make a public endpoint's cost grow with the size of the
     * configuration.
     *
     * @return list<PublicSourceStatus>
     */
    private function sources(Carbon $moment): array
    {
        $sources = IntegrationSource::query()
            ->where('enabled', true)
            ->orderBy('code')
            ->get(['id', 'code', 'stale_after_seconds']);

        if ($sources->isEmpty()) {
            return [];
        }

        $ids = $sources->pluck('id')->all();
        $lastFinished = $this->lastFinishedRunStatus($ids);
        $lastSuccess = $this->lastSuccessfulFinish($ids);

        return array_values(array_map(
            function (IntegrationSource $source) use ($moment, $lastFinished, $lastSuccess): array {
                $successAt = $lastSuccess[$source->id] ?? null;

                return [
                    'code' => $source->code,
                    'status' => $this->health(
                        $source->stale_after_seconds,
                        $successAt,
                        $lastFinished[$source->id] ?? null,
                        $moment,
                    )->value,
                    // Returned even when the state is `unknown`: the timestamp
                    // is a fact, and withholding it would hide that an import
                    // did work simply because nobody has approved a threshold.
                    'last_success_at' => $successAt?->utc()->format(self::TIMESTAMP_FORMAT),
                    'stale_after_seconds' => $source->stale_after_seconds,
                ];
            },
            $sources->all(),
        ));
    }

    /**
     * The state of one source.
     *
     * The order of the branches is the rule: an unapproved threshold outranks
     * everything, because without it the portal has no definition of "late";
     * and a stale copy outranks a later failure, because being out of date is
     * what a visitor needs to know first.
     */
    private function health(
        ?int $staleAfterSeconds,
        ?Carbon $lastSuccessAt,
        ?SynchronizationStatus $lastFinishedStatus,
        Carbon $moment,
    ): SourceHealth {
        if ($staleAfterSeconds === null) {
            return SourceHealth::Unknown;
        }

        if ($lastSuccessAt === null) {
            return SourceHealth::Unavailable;
        }

        // The boundary belongs to the healthy side: a source is stale only once
        // the threshold has been exceeded, not the instant it is reached.
        if ($moment->greaterThan($lastSuccessAt->copy()->addSeconds($staleAfterSeconds))) {
            return SourceHealth::Stale;
        }

        // The newest finished run being a failure is the same statement as
        // "something failed after the last success", and needs no second query.
        if ($lastFinishedStatus !== null && ! $lastFinishedStatus->isClean()) {
            return SourceHealth::Degraded;
        }

        return SourceHealth::Healthy;
    }

    /**
     * The closing status of each source's newest finished run.
     *
     * A run still in progress is skipped rather than treated as an outcome: it
     * has not said anything yet, and letting it hide the last finished result
     * would make the endpoint flicker while an import is running.
     *
     * Ranked by `finished_at` then `id`, so two runs closed in the same
     * microsecond still resolve to one answer on every request and on both
     * database engines.
     *
     * @param  array<int, int>  $sourceIds
     * @return array<int, SynchronizationStatus>
     */
    private function lastFinishedRunStatus(array $sourceIds): array
    {
        $ranked = DB::table('synchronization_runs')
            ->select(['source_id', 'status'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY source_id ORDER BY finished_at DESC, id DESC) AS run_rank')
            ->whereIn('source_id', $sourceIds)
            ->whereNotNull('finished_at')
            ->whereIn('status', [
                SynchronizationStatus::Succeeded->value,
                SynchronizationStatus::Partial->value,
                SynchronizationStatus::Failed->value,
            ]);

        $rows = DB::query()
            ->fromSub($ranked, 'ranked_runs')
            ->where('run_rank', 1)
            ->get();

        $statuses = [];

        foreach ($rows as $row) {
            $status = SynchronizationStatus::tryFrom((string) data_get($row, 'status'));

            if ($status !== null) {
                $statuses[(int) data_get($row, 'source_id')] = $status;
            }
        }

        return $statuses;
    }

    /**
     * When each source last finished an import successfully.
     *
     * `finished_at`, never `started_at`: a run that began an hour ago and
     * succeeded a minute ago is a minute old, not an hour. A run with no
     * `finished_at` has not succeeded at all.
     *
     * @param  array<int, int>  $sourceIds
     * @return array<int, Carbon>
     */
    private function lastSuccessfulFinish(array $sourceIds): array
    {
        $rows = DB::table('synchronization_runs')
            ->select('source_id')
            ->selectRaw('MAX(finished_at) AS last_success_at')
            ->whereIn('source_id', $sourceIds)
            ->where('status', SynchronizationStatus::Succeeded->value)
            ->whereNotNull('finished_at')
            ->groupBy('source_id')
            ->get();

        $timestamps = [];

        foreach ($rows as $row) {
            $value = data_get($row, 'last_success_at');

            if (is_string($value) && $value !== '') {
                $timestamps[(int) data_get($row, 'source_id')] = Carbon::parse($value)->utc();
            }
        }

        return $timestamps;
    }

    /**
     * The one word at the top of the report.
     *
     * A problem anywhere makes the whole report `degraded`, because a visitor
     * reading it is asking whether anything on the portal may be out of date.
     * `unknown` is reserved for having nothing to say at all — no enabled
     * source, or no source with an approved threshold — and never stands in for
     * "everything is fine".
     *
     * @param  list<PublicSourceStatus>  $sources
     */
    private function overall(array $sources): SystemStatus
    {
        if ($sources === []) {
            return SystemStatus::Unknown;
        }

        $states = array_map(
            static fn (array $source): ?SourceHealth => SourceHealth::tryFrom($source['status']),
            $sources,
        );

        foreach ($states as $state) {
            if ($state?->needsAttention() === true) {
                return SystemStatus::Degraded;
            }
        }

        // A mix of healthy and unknown is `ok`: something is being tracked and
        // it is current. Only having nothing tracked at all is `unknown`.
        foreach ($states as $state) {
            if ($state === SourceHealth::Healthy) {
                return SystemStatus::Ok;
            }
        }

        return SystemStatus::Unknown;
    }
}
