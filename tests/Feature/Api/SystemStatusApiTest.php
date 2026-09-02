<?php

namespace Tests\Feature\Api;

use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/v1/system/status`.
 *
 * Whether the portal's copy of each external source is current — not whether
 * the application is up, which `/up` and `/health` answer, and not whether
 * Hydromet is up, which the portal cannot see.
 *
 * The clock is frozen in every test. "Stale" is a statement about an interval,
 * so a test that let the wall clock supply one end of it would change meaning
 * as the suite ages, and the boundary cases would stop testing the boundary.
 *
 * These run on both drivers. The endpoint has to answer identically on SQLite
 * and PostgreSQL or the fast suite proves nothing about production.
 */
class SystemStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-02T12:00:00.000000Z';

    private const THRESHOLD = 7200;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    // --- Shape and headers -----------------------------------------------

    #[Test]
    public function the_endpoint_answers_with_the_documented_envelope(): void
    {
        $this->source('hydromet_observations', self::THRESHOLD);
        $this->recordRun('hydromet_observations', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');

        $response = $this->getJson('/api/v1/system/status')->assertOk();

        // No `data` wrapper: the contract puts the three keys at the top level.
        $response->assertExactJsonStructure([
            'status',
            'generated_at',
            'sources' => [['code', 'status', 'last_success_at', 'stale_after_seconds']],
        ]);

        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('generated_at', '2026-09-02T12:00:00.000000Z');
        $response->assertJsonPath('sources.0.code', 'hydromet_observations');
        $response->assertJsonPath('sources.0.status', 'healthy');
        $response->assertJsonPath('sources.0.last_success_at', '2026-09-02T11:00:00.000000Z');
        $response->assertJsonPath('sources.0.stale_after_seconds', self::THRESHOLD);
    }

    /**
     * A cached status is worse than none: it would tell a visitor a stale
     * source is current.
     */
    #[Test]
    public function the_response_is_never_cached_and_is_correlatable(): void
    {
        $response = $this->getJson('/api/v1/system/status')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('X-Request-Id'));
        // The shared hardening applies here like everywhere else.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function the_timestamps_are_utc_with_microseconds(): void
    {
        $this->source('a', self::THRESHOLD);
        $this->recordRun('a', SynchronizationStatus::Succeeded, '2026-09-02T11:30:45.123456Z');

        $body = $this->getJson('/api/v1/system/status')->assertOk();

        foreach ([$body->json('generated_at'), $body->json('sources.0.last_success_at')] as $timestamp) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
                (string) $timestamp,
            );
        }

        $body->assertJsonPath('sources.0.last_success_at', '2026-09-02T11:30:45.123456Z');
    }

    /**
     * The endpoint is public, so it must publish a source code, a state, a
     * timestamp and the threshold — and nothing that describes infrastructure.
     */
    #[Test]
    public function the_response_reveals_nothing_about_the_internal_configuration(): void
    {
        $source = IntegrationSource::factory()->http()->staleAfter(self::THRESHOLD)->create([
            'code' => 'hydromet_observations',
            'enabled' => true,
        ]);

        SynchronizationRun::factory()->failed()->create([
            'source_id' => $source->id,
            'started_at' => Carbon::parse('2026-09-02T11:00:00Z'),
            'finished_at' => Carbon::parse('2026-09-02T11:00:05Z'),
        ]);

        $body = (string) $this->getJson('/api/v1/system/status')->assertOk()->getContent();

        foreach ([
            'example.test',
            'https://',
            'test-producer',
            'api_key',
            'sanitized_error',
            'unexpected_error',
            'base_url',
            'producer',
            'authentication_type',
            'received_count',
            'timeout_seconds',
            'polling_interval_seconds',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "The response leaked [{$forbidden}].");
        }

        // Exactly the four documented keys per source, nothing more.
        $this->assertSame(
            ['code', 'last_success_at', 'stale_after_seconds', 'status'],
            $this->sortedKeys($this->getJson('/api/v1/system/status')->json('sources.0')),
        );
    }

    // --- Which sources are published --------------------------------------

    #[Test]
    public function a_portal_with_no_sources_reports_unknown_and_an_empty_list(): void
    {
        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('status', 'unknown')
            ->assertJsonPath('sources', []);
    }

    #[Test]
    public function a_disabled_source_is_not_published(): void
    {
        $this->source('disabled_feed', self::THRESHOLD, enabled: false);
        $this->recordRun('disabled_feed', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources', [])
            ->assertJsonPath('status', 'unknown');
    }

    #[Test]
    public function sources_are_ordered_by_code(): void
    {
        foreach (['zulu_feed', 'alpha_feed', 'mike_feed'] as $code) {
            $this->source($code, self::THRESHOLD);
            $this->recordRun($code, SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
        }

        $this->assertSame(
            ['alpha_feed', 'mike_feed', 'zulu_feed'],
            array_column($this->getJson('/api/v1/system/status')->json('sources'), 'code'),
        );
    }

    // --- Per-source states -------------------------------------------------

    #[Test]
    public function a_source_without_an_approved_threshold_is_unknown(): void
    {
        $this->source('no_rule_yet', null);
        $this->recordRun('no_rule_yet', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', 'unknown')
            ->assertJsonPath('sources.0.stale_after_seconds', null)
            // The timestamp is a fact and is still published: an unanswered
            // question about staleness does not erase a successful import.
            ->assertJsonPath('sources.0.last_success_at', '2026-09-02T11:00:00.000000Z');
    }

    #[Test]
    public function a_source_with_a_threshold_but_no_successful_run_is_unavailable(): void
    {
        $this->source('never_worked', self::THRESHOLD);
        $this->recordRun('never_worked', SynchronizationStatus::Failed, '2026-09-02T11:00:00Z');

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', 'unavailable')
            ->assertJsonPath('sources.0.last_success_at', null);
    }

    /**
     * The boundary belongs to the healthy side.
     *
     * @return array<string, array{string, string}>
     */
    public static function stalenessBoundary(): array
    {
        return [
            'well inside the window' => ['2026-09-02T11:00:00Z', 'healthy'],
            'exactly one second inside' => ['2026-09-02T10:00:01Z', 'healthy'],
            'exactly at the threshold' => ['2026-09-02T10:00:00Z', 'healthy'],
            'one microsecond past it' => ['2026-09-02T09:59:59.999999Z', 'stale'],
            'one second past it' => ['2026-09-02T09:59:59Z', 'stale'],
            'long past it' => ['2026-09-01T00:00:00Z', 'stale'],
        ];
    }

    #[Test]
    #[DataProvider('stalenessBoundary')]
    public function the_threshold_boundary_is_inclusive(string $succeededAt, string $expected): void
    {
        // Threshold 7200s and now 12:00:00 put the boundary exactly at 10:00:00.
        $this->source('bounded', self::THRESHOLD);
        $this->recordRun('bounded', SynchronizationStatus::Succeeded, $succeededAt);

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', $expected);
    }

    /**
     * @return array<string, array{SynchronizationStatus}>
     */
    public static function failingClosingStatuses(): array
    {
        return [
            'failed' => [SynchronizationStatus::Failed],
            'partial' => [SynchronizationStatus::Partial],
        ];
    }

    #[Test]
    #[DataProvider('failingClosingStatuses')]
    public function a_failure_after_a_fresh_success_is_degraded(SynchronizationStatus $status): void
    {
        $this->source('recently_broken', self::THRESHOLD);
        $this->recordRun('recently_broken', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
        $this->recordRun('recently_broken', $status, '2026-09-02T11:30:00Z');

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', 'degraded')
            // The last success is still reported: it says how old the data is.
            ->assertJsonPath('sources.0.last_success_at', '2026-09-02T11:00:00.000000Z')
            ->assertJsonPath('status', 'degraded');
    }

    #[Test]
    public function an_older_failure_before_a_newer_success_does_not_degrade_the_source(): void
    {
        $this->source('recovered', self::THRESHOLD);
        $this->recordRun('recovered', SynchronizationStatus::Failed, '2026-09-02T10:30:00Z');
        $this->recordRun('recovered', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', 'healthy')
            ->assertJsonPath('status', 'ok');
    }

    /**
     * An import in progress has not said anything yet, so it must not hide the
     * result of the last one that did.
     */
    #[Test]
    public function a_running_import_does_not_erase_the_last_finished_result(): void
    {
        $this->source('busy', self::THRESHOLD);
        $this->recordRun('busy', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');

        $this->recordRunningImport('busy', '2026-09-02T11:59:00Z');

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', 'healthy')
            ->assertJsonPath('sources.0.last_success_at', '2026-09-02T11:00:00.000000Z');
    }

    #[Test]
    public function a_running_import_does_not_hide_an_earlier_failure_either(): void
    {
        $this->source('busy_after_failing', self::THRESHOLD);
        $this->recordRun('busy_after_failing', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
        $this->recordRun('busy_after_failing', SynchronizationStatus::Failed, '2026-09-02T11:10:00Z');

        $this->recordRunningImport('busy_after_failing', '2026-09-02T11:59:00Z');

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', 'degraded');
    }

    /**
     * Two runs closed in the same microsecond still have to resolve to one
     * answer, on every request and on both engines. The newer row wins.
     */
    #[Test]
    public function runs_finishing_at_the_same_instant_resolve_by_id(): void
    {
        $this->source('tied', self::THRESHOLD);
        $this->recordRun('tied', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
        $this->recordRun('tied', SynchronizationStatus::Failed, '2026-09-02T11:00:00Z');

        $first = $this->getJson('/api/v1/system/status')->assertOk()->json('sources.0.status');
        $second = $this->getJson('/api/v1/system/status')->assertOk()->json('sources.0.status');

        $this->assertSame('degraded', $first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function the_same_instant_resolves_the_other_way_when_the_success_is_newer(): void
    {
        $this->source('tied_other_way', self::THRESHOLD);
        $this->recordRun('tied_other_way', SynchronizationStatus::Failed, '2026-09-02T11:00:00Z');
        $this->recordRun('tied_other_way', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', 'healthy');
    }

    /**
     * `started_at` is not the answer: a run that began an hour ago and
     * succeeded a minute ago is a minute old.
     */
    #[Test]
    public function staleness_is_measured_from_the_finish_not_the_start(): void
    {
        $this->source('long_run', self::THRESHOLD);

        SynchronizationRun::factory()->measurements()->create([
            'source_id' => $this->sourceId('long_run'),
            // Started well outside the window, finished well inside it.
            'started_at' => Carbon::parse('2026-09-01T00:00:00Z'),
            'finished_at' => Carbon::parse('2026-09-02T11:00:00Z'),
        ]);

        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('sources.0.status', 'healthy')
            ->assertJsonPath('sources.0.last_success_at', '2026-09-02T11:00:00.000000Z');
    }

    // --- Overall status ----------------------------------------------------

    #[Test]
    public function every_tracked_source_healthy_reports_ok(): void
    {
        foreach (['a', 'b'] as $code) {
            $this->source($code, self::THRESHOLD);
            $this->recordRun($code, SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
        }

        $this->getJson('/api/v1/system/status')->assertOk()->assertJsonPath('status', 'ok');
    }

    #[Test]
    public function every_source_unknown_reports_unknown(): void
    {
        foreach (['a', 'b'] as $code) {
            $this->source($code, null);
            $this->recordRun($code, SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
        }

        $this->getJson('/api/v1/system/status')->assertOk()->assertJsonPath('status', 'unknown');
    }

    /**
     * A source nobody has ruled on must not drag the report down, and must not
     * be counted as fine either — the healthy one is what makes it `ok`.
     */
    #[Test]
    public function a_mix_of_healthy_and_unknown_reports_ok(): void
    {
        $this->source('tracked', self::THRESHOLD);
        $this->recordRun('tracked', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
        $this->source('untracked', null);

        $body = $this->getJson('/api/v1/system/status')->assertOk();

        $body->assertJsonPath('status', 'ok');
        $this->assertSame(
            ['healthy', 'unknown'],
            array_column($body->json('sources'), 'status'),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function problemStates(): array
    {
        return [
            'stale' => ['stale'],
            'unavailable' => ['unavailable'],
            'degraded' => ['degraded'],
        ];
    }

    #[Test]
    #[DataProvider('problemStates')]
    public function one_source_needing_attention_degrades_the_whole_report(string $state): void
    {
        $this->source('healthy_one', self::THRESHOLD);
        $this->recordRun('healthy_one', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');

        $this->source('problem_one', self::THRESHOLD);

        match ($state) {
            'stale' => $this->recordRun('problem_one', SynchronizationStatus::Succeeded, '2026-09-01T00:00:00Z'),
            'degraded' => (function (): void {
                $this->recordRun('problem_one', SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
                $this->recordRun('problem_one', SynchronizationStatus::Failed, '2026-09-02T11:30:00Z');
            })(),
            // `unavailable` needs no run at all.
            default => null,
        };

        $body = $this->getJson('/api/v1/system/status')->assertOk();

        $body->assertJsonPath('status', 'degraded');
        $this->assertContains($state, array_column($body->json('sources'), 'status'));
    }

    // --- Cost --------------------------------------------------------------

    /**
     * A public endpoint must not get more expensive as the configuration grows.
     */
    #[Test]
    public function the_query_count_does_not_grow_with_the_number_of_sources(): void
    {
        $addSources = function (int $from, int $to): void {
            for ($index = $from; $index <= $to; $index++) {
                $code = sprintf('feed_%02d', $index);
                $this->source($code, self::THRESHOLD);
                $this->recordRun($code, SynchronizationStatus::Succeeded, '2026-09-02T11:00:00Z');
            }
        };

        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/system/status')->assertOk();
            $queries = count(DB::getRawQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // Nothing is deleted between the two measurements: sources are only
        // added, so the second reading is the same endpoint doing strictly more
        // work if it were doing per-source queries.
        $addSources(1, 1);
        $withOne = $measure();

        $addSources(2, 12);
        $withTwelve = $measure();

        // The second reading really did cover twelve sources, so an equal query
        // count is evidence rather than a coincidence.
        $this->assertCount(12, $this->getJson('/api/v1/system/status')->json('sources'));
        $this->assertSame($withOne, $withTwelve);
    }

    // --- Helpers -----------------------------------------------------------

    private function source(string $code, ?int $staleAfterSeconds, bool $enabled = true): IntegrationSource
    {
        return IntegrationSource::factory()->create([
            'code' => $code,
            'enabled' => $enabled,
            'stale_after_seconds' => $staleAfterSeconds,
        ]);
    }

    private function sourceId(string $code): int
    {
        return IntegrationSource::query()->where('code', $code)->sole()->id;
    }

    /**
     * A finished run, built through the factory state that matches its closing
     * status: a failed run needs an error code and a partial one needs a
     * rejection, and PostgreSQL enforces both.
     */
    private function recordRun(string $code, SynchronizationStatus $status, string $finishedAt): SynchronizationRun
    {
        $finished = Carbon::parse($finishedAt);

        $factory = match ($status) {
            SynchronizationStatus::Failed => SynchronizationRun::factory()->failed(),
            SynchronizationStatus::Partial => SynchronizationRun::factory()->partial(),
            SynchronizationStatus::Running => SynchronizationRun::factory()->running(),
            SynchronizationStatus::Succeeded => SynchronizationRun::factory(),
        };

        return $factory->measurements()->create([
            'source_id' => $this->sourceId($code),
            'started_at' => $finished->copy()->subSeconds(5),
            'finished_at' => $finished,
        ]);
    }

    /**
     * A run still in progress: no finish, and no counters yet.
     */
    private function recordRunningImport(string $code, string $startedAt): SynchronizationRun
    {
        return SynchronizationRun::factory()->running()->measurements()->create([
            'source_id' => $this->sourceId($code),
            'started_at' => Carbon::parse($startedAt),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }
}
