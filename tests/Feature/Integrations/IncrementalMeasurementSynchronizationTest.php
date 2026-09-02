<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Data\SynchronizationOutcome;
use App\Domain\Integrations\Data\SynchronizationWindow;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Exceptions\SynchronizationWindowUnavailable;
use App\Domain\Integrations\Fixtures\FixtureIntegrationSource;
use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureMeasurementScenario;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Integrations\Services\MeasurementSynchronizer;
use App\Domain\Integrations\Services\SynchronizationRunner;
use App\Domain\Integrations\Services\SynchronizationWindowPlanner;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use App\Domain\Measurements\Services\MeasurementImporter;
use App\Domain\Stations\Services\StationRegistryImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IncrementalMeasurementSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_window_is_inclusive_normalized_to_utc_and_never_runs_backwards(): void
    {
        $window = new SynchronizationWindow(
            CarbonImmutable::parse('2026-08-31T09:00:00+05:00'),
            CarbonImmutable::parse('2026-08-31T11:00:00+05:00'),
        );

        $this->assertSame('2026-08-31T04:00:00+00:00', $window->from->toIso8601String());
        $this->assertSame('2026-08-31T06:00:00+00:00', $window->to->toIso8601String());
        $this->assertTrue($window->contains(CarbonImmutable::parse('2026-08-31T04:00:00Z')));
        $this->assertTrue($window->contains(CarbonImmutable::parse('2026-08-31T06:00:00Z')));
        $this->assertFalse($window->contains(CarbonImmutable::parse('2026-08-31T06:00:00.000001Z')));

        $this->expectException(InvalidArgumentException::class);

        new SynchronizationWindow(
            CarbonImmutable::parse('2026-08-31T06:00:01Z'),
            CarbonImmutable::parse('2026-08-31T06:00:00Z'),
        );
    }

    #[Test]
    public function the_first_window_requires_an_explicit_bootstrap_start(): void
    {
        $source = $this->incrementalSource();
        $planner = new SynchronizationWindowPlanner;

        $this->expectException(SynchronizationWindowUnavailable::class);
        $this->expectExceptionMessage('explicit bootstrap start is required');

        $planner->next(
            $source,
            SynchronizationKind::Measurements,
            CarbonImmutable::parse('2026-08-31T06:00:00Z'),
        );
    }

    #[Test]
    public function a_source_without_a_cursor_strategy_cannot_be_planned(): void
    {
        $source = IntegrationSource::factory()->create([
            'cursor_strategy' => 'none',
            'overlap_seconds' => 0,
        ]);

        $this->expectException(SynchronizationWindowUnavailable::class);
        $this->expectExceptionMessage('has no incremental cursor strategy');

        (new SynchronizationWindowPlanner)->next(
            $source,
            SynchronizationKind::Measurements,
            CarbonImmutable::parse('2026-08-31T06:00:00Z'),
            CarbonImmutable::parse('2026-08-31T04:00:00Z'),
        );
    }

    #[Test]
    public function the_next_window_overlaps_the_latest_completed_cursor(): void
    {
        $source = $this->incrementalSource(overlapSeconds: 3600);

        SynchronizationRun::factory()->partial()->measurements()->create([
            'source_id' => $source->id,
            'cursor_from' => '2026-08-31T04:00:00Z',
            'cursor_to' => '2026-08-31T06:00:00Z',
        ]);

        // A later failed attempt must not advance the successful cursor.
        SynchronizationRun::factory()->failed()->measurements()->create([
            'source_id' => $source->id,
            'cursor_from' => '2026-08-31T06:00:00Z',
            'cursor_to' => '2026-08-31T08:00:00Z',
        ]);

        $window = (new SynchronizationWindowPlanner)->next(
            $source,
            SynchronizationKind::Measurements,
            CarbonImmutable::parse('2026-08-31T07:00:00Z'),
            CarbonImmutable::parse('2026-08-31T04:30:00Z'),
        );

        // 06:00 minus one-hour overlap, clamped no earlier than bootstrap.
        $this->assertSame('2026-08-31T05:00:00+00:00', $window->from->toIso8601String());
        $this->assertSame('2026-08-31T07:00:00+00:00', $window->to->toIso8601String());
    }

    #[Test]
    public function a_bounded_fixture_read_returns_only_rows_inside_the_window(): void
    {
        $window = new SynchronizationWindow(
            CarbonImmutable::parse('2026-08-31T05:00:00Z'),
            CarbonImmutable::parse('2026-08-31T05:00:00Z'),
        );

        $batch = (new FixtureMeasurementProvider)->fetchMeasurements($window);

        $this->assertSame(2, $batch->received());
        $this->assertCount(2, $batch->records);
        $this->assertSame([], $batch->rejections);

        foreach ($batch->records as $record) {
            $this->assertSame('2026-08-31T05:00:00+00:00', $record->observedAt->toIso8601String());
        }
    }

    #[Test]
    public function overlap_captures_a_late_source_correction_without_duplicates(): void
    {
        (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);

        $source = $this->incrementalSource(overlapSeconds: 3600);
        $planner = new SynchronizationWindowPlanner;
        $synchronizer = new MeasurementSynchronizer(
            new SynchronizationRunner,
            new MeasurementImporter,
        );

        $historicalWindow = $planner->next(
            $source,
            SynchronizationKind::Measurements,
            CarbonImmutable::parse('2026-08-31T06:00:00Z'),
            CarbonImmutable::parse('2026-08-31T04:00:00Z'),
        );

        $historicalRun = $synchronizer->synchronize(
            $source,
            new FixtureMeasurementProvider(FixtureMeasurementScenario::Base),
            $historicalWindow,
        );

        $this->assertSame(SynchronizationStatus::Partial, $historicalRun->status);
        $this->assertSame(7, Measurement::query()->count());
        $this->assertSame(0, MeasurementRevision::query()->count());

        $incrementalWindow = $planner->next(
            $source,
            SynchronizationKind::Measurements,
            CarbonImmutable::parse('2026-08-31T07:00:00Z'),
            CarbonImmutable::parse('2026-08-31T04:00:00Z'),
        );

        $this->assertSame('2026-08-31T05:00:00+00:00', $incrementalWindow->from->toIso8601String());

        $correctionRun = $synchronizer->synchronize(
            $source,
            new FixtureMeasurementProvider(FixtureMeasurementScenario::Correction),
            $incrementalWindow,
        );

        $this->assertSame(SynchronizationStatus::Succeeded, $correctionRun->status);
        $this->assertSame('2026-08-31T05:00:00+00:00', $correctionRun->cursor_from?->toIso8601String());
        $this->assertSame('2026-08-31T07:00:00+00:00', $correctionRun->cursor_to?->toIso8601String());
        $this->assertSame(7, Measurement::query()->count());
        $this->assertSame(1, MeasurementRevision::query()->count());

        $corrected = Measurement::query()
            ->where('source_measurement_id', 'FIXTURE-001-PM25-20260831T060000Z')
            ->sole();

        $this->assertSame('25.900000', $corrected->value);
        $this->assertSame(2, $corrected->revision);
    }

    #[Test]
    public function a_failed_bounded_attempt_keeps_its_exact_window_without_advancing_the_planner(): void
    {
        $source = $this->incrementalSource(overlapSeconds: 300);
        $window = new SynchronizationWindow(
            CarbonImmutable::parse('2026-08-31T05:55:00Z'),
            CarbonImmutable::parse('2026-08-31T07:00:00Z'),
        );

        $run = (new SynchronizationRunner)->run(
            $source,
            SynchronizationKind::Measurements,
            function (): SynchronizationOutcome {
                throw new \RuntimeException('Synthetic provider failure.');
            },
            $window,
        );

        $this->assertSame(SynchronizationStatus::Failed, $run->status);
        $this->assertSame('2026-08-31T05:55:00+00:00', $run->cursor_from?->toIso8601String());
        $this->assertSame('2026-08-31T07:00:00+00:00', $run->cursor_to?->toIso8601String());

        $this->expectException(SynchronizationWindowUnavailable::class);

        (new SynchronizationWindowPlanner)->next(
            $source,
            SynchronizationKind::Measurements,
            CarbonImmutable::parse('2026-08-31T08:00:00Z'),
        );
    }

    #[Test]
    public function a_provider_cannot_write_under_another_source_journal(): void
    {
        $source = IntegrationSource::factory()->create([
            'code' => 'not-fixture',
            'cursor_strategy' => 'observed_at',
        ]);
        $window = new SynchronizationWindow(
            CarbonImmutable::parse('2026-08-31T04:00:00Z'),
            CarbonImmutable::parse('2026-08-31T06:00:00Z'),
        );

        $run = (new MeasurementSynchronizer(new SynchronizationRunner, new MeasurementImporter))
            ->synchronize($source, new FixtureMeasurementProvider, $window);

        $this->assertSame(SynchronizationStatus::Failed, $run->status);
        $this->assertSame(SynchronizationRun::ERROR_UNEXPECTED, $run->error_code);
        $this->assertSame(0, Measurement::query()->count());
    }

    private function incrementalSource(int $overlapSeconds = 300): IntegrationSource
    {
        $source = FixtureIntegrationSource::ensure();
        $source->update([
            'cursor_strategy' => 'observed_at',
            'overlap_seconds' => $overlapSeconds,
        ]);

        return $source->refresh();
    }
}
