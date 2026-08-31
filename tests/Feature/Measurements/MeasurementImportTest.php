<?php

namespace Tests\Feature\Measurements;

use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureMeasurementScenario;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Measurements\Data\MeasurementImportResult;
use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Enums\RevisionOrigin;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use App\Domain\Measurements\Services\MeasurementImporter;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use App\Domain\Stations\Services\StationRegistryImporter;
use App\Support\Canonical\RejectionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CanonicalRows;
use Tests\TestCase;

class MeasurementImportTest extends TestCase
{
    use RefreshDatabase;

    private MeasurementImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = new MeasurementImporter;
    }

    /**
     * The station and parameter a canonical test row refers to.
     */
    private function seedRegistry(): void
    {
        Station::factory()->create([
            'source' => CanonicalRows::SOURCE,
            'external_id' => 'test-station-001',
            'code' => 'TEST-001',
        ]);

        Parameter::factory()->create(['code' => 'PM25', 'canonical_unit' => 'ug/m3']);
    }

    private function importFixtureRegistry(): void
    {
        (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);
    }

    private function importFixture(FixtureMeasurementScenario $scenario): MeasurementImportResult
    {
        return $this->importer->import(new FixtureMeasurementProvider($scenario));
    }

    #[Test]
    public function the_base_fixture_stores_every_valid_measurement(): void
    {
        $this->importFixtureRegistry();

        $result = $this->importFixture(FixtureMeasurementScenario::Base);

        $this->assertSame(8, $result->received);
        $this->assertSame(7, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->unchanged);
        $this->assertSame(1, $result->rejected());
        $this->assertSame(0, $result->revisionsCreated);

        $this->assertSame(7, Measurement::query()->count());
        $this->assertSame(0, MeasurementRevision::query()->count());
    }

    #[Test]
    public function repeating_the_base_fixture_import_adds_no_rows(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);

        $countAfterFirstRun = Measurement::query()->count();
        $second = $this->importFixture(FixtureMeasurementScenario::Base);

        $this->assertSame($countAfterFirstRun, Measurement::query()->count());
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(7, $second->unchanged);
    }

    #[Test]
    public function repeating_the_base_fixture_import_creates_no_revisions(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);
        $this->importFixture(FixtureMeasurementScenario::Base);

        $this->assertSame(0, MeasurementRevision::query()->count());
    }

    #[Test]
    public function a_missing_reading_is_stored_as_null_and_never_as_zero(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);

        $missing = Measurement::query()
            ->where('quality', MeasurementQuality::Missing)
            ->sole();

        $this->assertNull($missing->value);
        $this->assertNull($missing->original_value);
        $this->assertSame(MeasurementQuality::Missing, $missing->original_quality);

        // Read straight from the row, in case a cast were hiding a zero.
        $row = DB::table('measurements')->where('id', $missing->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->value);
        $this->assertNull($row->original_value);

        $this->assertSame(0, Measurement::query()->where('value', 0)->count());
    }

    #[Test]
    public function quality_flags_round_trip_as_a_list_of_strings(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);

        $flagged = Measurement::query()
            ->where('quality', MeasurementQuality::Suspect)
            ->sole();

        $this->assertSame(['fixture_synthetic', 'fixture_suspect_range'], $flagged->quality_flags);

        $empty = Measurement::query()
            ->where('unit', 'ug/m3')
            ->where('sensor_no', null)
            ->sole();

        $this->assertSame([], $empty->quality_flags);
    }

    #[Test]
    public function a_row_naming_an_unregistered_station_is_rejected_without_losing_the_others(): void
    {
        $this->importFixtureRegistry();

        $result = $this->importFixture(FixtureMeasurementScenario::Base);

        $this->assertTrue($result->isPartial());
        $this->assertCount(1, $result->rejections);
        $this->assertSame(RejectionReason::UnknownStation, $result->rejections[0]->reason);
        $this->assertStringContainsString('fixture-station-999', $result->rejections[0]->detail);
        $this->assertSame(7, Measurement::query()->count());
    }

    #[Test]
    public function a_row_naming_an_unknown_parameter_is_rejected(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['parameter_code' => 'NOT_IN_CATALOGUE']),
            ]),
        );

        $this->assertSame(0, Measurement::query()->count());
        $this->assertSame(RejectionReason::UnknownParameterCode, $result->rejections[0]->reason);
    }

    #[Test]
    public function a_unit_that_is_not_the_canonical_unit_is_rejected(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['unit' => 'mg/m3']),
            ]),
        );

        $this->assertSame(0, Measurement::query()->count());
        $this->assertSame(RejectionReason::UnitMismatch, $result->rejections[0]->reason);
        $this->assertStringContainsString('mg/m3', $result->rejections[0]->detail);
        $this->assertStringContainsString('ug/m3', $result->rejections[0]->detail);
    }

    #[Test]
    public function a_reading_declared_missing_may_not_carry_a_value(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['quality' => 'missing', 'value' => 12.0]),
            ]),
        );

        $this->assertSame(0, Measurement::query()->count());
        $this->assertSame(RejectionReason::MissingRequiresNullValue, $result->rejections[0]->reason);
    }

    #[Test]
    public function a_null_value_must_be_reported_as_a_missing_reading(): void
    {
        $this->seedRegistry();

        foreach (['valid', 'suspect', 'invalid', 'corrected'] as $quality) {
            $result = $this->importer->importBatch(
                CanonicalRows::measurementBatch([
                    CanonicalRows::measurement(['value' => null, 'quality' => $quality]),
                ]),
            );

            $this->assertSame(
                RejectionReason::NullValueRequiresMissingQuality,
                $result->rejections[0]->reason,
                "Expected a null value under quality '{$quality}' to be rejected.",
            );
            $this->assertStringContainsString($quality, $result->rejections[0]->detail);
        }

        $this->assertSame(0, Measurement::query()->count());
    }

    #[Test]
    public function a_null_value_reported_as_missing_is_accepted(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['value' => null, 'quality' => 'missing']),
            ]),
        );

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->rejected());

        $measurement = Measurement::query()->sole();
        $this->assertNull($measurement->value);
        $this->assertNull($measurement->original_value);
        $this->assertSame(MeasurementQuality::Missing, $measurement->quality);
        $this->assertSame(MeasurementQuality::Missing, $measurement->original_quality);
    }

    #[Test]
    public function the_two_way_rule_also_governs_the_original_pair_written_on_create(): void
    {
        $this->seedRegistry();

        // original_value / original_quality are copied from the first accepted
        // reading, so the rule that guards one guards the other.
        $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['value' => null, 'quality' => 'missing']),
            ]),
        );

        $rows = DB::table('measurements')
            ->whereNull('original_value')
            ->where('original_quality', '!=', 'missing')
            ->count();

        $this->assertSame(0, $rows);

        $inconsistent = DB::table('measurements')
            ->whereNotNull('original_value')
            ->where('original_quality', 'missing')
            ->count();

        $this->assertSame(0, $inconsistent);
    }

    #[Test]
    public function revision_history_never_records_a_value_that_contradicts_its_quality(): void
    {
        $this->seedRegistry();

        // missing -> a real reading, then a real reading -> missing, so both
        // sides of the history pair take a null and a number in turn.
        $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['value' => null, 'quality' => 'missing']),
            ]),
        );
        $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['value' => 17.25, 'quality' => 'corrected', 'revision' => 2]),
            ]),
        );
        $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['value' => null, 'quality' => 'missing', 'revision' => 3]),
            ]),
        );

        $this->assertSame(2, MeasurementRevision::query()->count());

        foreach (MeasurementRevision::query()->orderBy('revision')->get() as $revision) {
            $this->assertSame(
                $revision->previous_value === null,
                $revision->previous_quality === MeasurementQuality::Missing,
            );
            $this->assertSame(
                $revision->corrected_value === null,
                $revision->corrected_quality === MeasurementQuality::Missing,
            );
        }

        $first = MeasurementRevision::query()->where('revision', 2)->sole();
        $this->assertNull($first->previous_value);
        $this->assertSame(MeasurementQuality::Missing, $first->previous_quality);
        $this->assertSame('17.250000', $first->corrected_value);

        $second = MeasurementRevision::query()->where('revision', 3)->sole();
        $this->assertSame('17.250000', $second->previous_value);
        $this->assertNull($second->corrected_value);
        $this->assertSame(MeasurementQuality::Missing, $second->corrected_quality);
    }

    #[Test]
    public function a_source_batch_may_not_claim_a_manual_entry(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['is_manual' => true]),
            ]),
        );

        $this->assertSame(0, Measurement::query()->count());
        $this->assertSame(RejectionReason::ManualEntryNotSupported, $result->rejections[0]->reason);
    }

    #[Test]
    public function the_fixture_import_always_stores_is_manual_false(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);

        $this->assertSame(0, Measurement::query()->where('is_manual', true)->count());
        $this->assertSame(7, Measurement::query()->where('is_manual', false)->count());
    }

    #[Test]
    public function a_revision_below_one_is_rejected(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['revision' => 0]),
            ]),
        );

        $this->assertSame(0, Measurement::query()->count());
        $this->assertSame(RejectionReason::InvalidRevision, $result->rejections[0]->reason);
    }

    #[Test]
    public function one_rejected_row_does_not_roll_back_the_valid_rows_beside_it(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['observed_at' => '2026-08-31T04:00:00Z']),
                CanonicalRows::measurement([
                    'observed_at' => '2026-08-31T05:00:00Z',
                    'station_external_id' => 'test-station-does-not-exist',
                ]),
                CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00Z']),
            ]),
        );

        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->rejected());
        $this->assertSame(2, Measurement::query()->count());
    }

    #[Test]
    public function a_newer_source_revision_updates_the_effective_value(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);

        $result = $this->importFixture(FixtureMeasurementScenario::Correction);

        $this->assertSame(1, $result->received);
        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->revisionsCreated);
        $this->assertSame(0, $result->rejected());

        $corrected = $this->correctedMeasurement();
        $this->assertSame('25.900000', $corrected->value);
        $this->assertSame(MeasurementQuality::Corrected, $corrected->quality);
        $this->assertSame(2, $corrected->revision);
    }

    #[Test]
    public function a_correction_leaves_the_original_value_and_quality_untouched(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);

        $before = $this->correctedMeasurement();
        $this->assertSame('23.400000', $before->original_value);
        $this->assertSame(MeasurementQuality::Valid, $before->original_quality);

        $this->importFixture(FixtureMeasurementScenario::Correction);

        $after = $this->correctedMeasurement();
        $this->assertSame('23.400000', $after->original_value);
        $this->assertSame(MeasurementQuality::Valid, $after->original_quality);
        $this->assertTrue($after->isCorrected());

        // Straight from the row, so no accessor can be masking a rewrite.
        // Compared numerically: the drivers render a stored decimal
        // differently ("23.400000" on PostgreSQL, "23.4" on SQLite).
        $row = DB::table('measurements')->where('id', $after->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(23.4, (float) $row->original_value);
        $this->assertSame('valid', $row->original_quality);
    }

    #[Test]
    public function the_revision_history_records_the_value_before_and_after(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);
        $this->importFixture(FixtureMeasurementScenario::Correction);

        $revision = MeasurementRevision::query()->sole();

        $this->assertSame($this->correctedMeasurement()->id, $revision->measurement_id);
        $this->assertSame(2, $revision->revision);
        $this->assertSame('23.400000', $revision->previous_value);
        $this->assertSame(MeasurementQuality::Valid, $revision->previous_quality);
        $this->assertSame('25.900000', $revision->corrected_value);
        $this->assertSame(MeasurementQuality::Corrected, $revision->corrected_quality);
        $this->assertSame(MeasurementRevision::REASON_SOURCE_REVISION, $revision->reason_code);
        $this->assertSame(RevisionOrigin::Source, $revision->change_origin);
        $this->assertNull($revision->changed_by);
        $this->assertNull($revision->reason_text);
    }

    #[Test]
    public function repeating_the_correction_fixture_import_is_idempotent(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);
        $this->importFixture(FixtureMeasurementScenario::Correction);

        $measurements = Measurement::query()->count();

        $second = $this->importFixture(FixtureMeasurementScenario::Correction);

        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(1, $second->unchanged);
        $this->assertSame(0, $second->revisionsCreated);
        $this->assertSame($measurements, Measurement::query()->count());
        $this->assertSame(1, MeasurementRevision::query()->count());
    }

    #[Test]
    public function replaying_the_base_batch_after_a_correction_is_rejected_as_stale(): void
    {
        $this->importFixtureRegistry();
        $this->importFixture(FixtureMeasurementScenario::Base);
        $this->importFixture(FixtureMeasurementScenario::Correction);

        $result = $this->importFixture(FixtureMeasurementScenario::Base);

        // Six rows are unchanged; the corrected one arrives at revision 1.
        $this->assertSame(6, $result->unchanged);
        $this->assertSame(0, $result->updated);

        $stale = array_values(array_filter(
            $result->rejections,
            static fn ($rejection): bool => $rejection->reason === RejectionReason::StaleRevision,
        ));

        $this->assertCount(1, $stale);
        $this->assertStringContainsString('older than the stored revision', $stale[0]->detail);

        $corrected = $this->correctedMeasurement();
        $this->assertSame('25.900000', $corrected->value);
        $this->assertSame(2, $corrected->revision);
    }

    #[Test]
    public function the_stored_revision_restated_with_a_different_value_is_rejected_as_a_conflict(): void
    {
        $this->seedRegistry();

        $this->importer->importBatch(
            CanonicalRows::measurementBatch([CanonicalRows::measurement()]),
        );

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['value' => 99.9]),
            ]),
        );

        $this->assertSame(RejectionReason::RevisionConflict, $result->rejections[0]->reason);
        $this->assertSame('23.400000', Measurement::query()->sole()->value);
        $this->assertSame(0, MeasurementRevision::query()->count());
    }

    #[Test]
    public function the_stored_revision_restated_with_a_different_quality_is_rejected_as_a_conflict(): void
    {
        $this->seedRegistry();

        $this->importer->importBatch(
            CanonicalRows::measurementBatch([CanonicalRows::measurement()]),
        );

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['quality' => 'suspect']),
            ]),
        );

        $this->assertSame(RejectionReason::RevisionConflict, $result->rejections[0]->reason);
        $this->assertSame(MeasurementQuality::Valid, Measurement::query()->sole()->quality);
    }

    #[Test]
    public function a_newer_revision_that_changes_nothing_writes_no_history(): void
    {
        $this->seedRegistry();

        $this->importer->importBatch(
            CanonicalRows::measurementBatch([CanonicalRows::measurement()]),
        );

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement([
                    'revision' => 2,
                    'received_at' => '2026-09-01T06:00:00Z',
                ]),
            ]),
        );

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->revisionsCreated);
        $this->assertSame(2, Measurement::query()->sole()->revision);
        $this->assertSame(0, MeasurementRevision::query()->count());
    }

    #[Test]
    public function a_correction_to_a_missing_reading_records_a_null_previous_value(): void
    {
        $this->seedRegistry();

        $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['value' => null, 'quality' => 'missing']),
            ]),
        );

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['value' => 17.25, 'quality' => 'corrected', 'revision' => 2]),
            ]),
        );

        $this->assertSame(1, $result->revisionsCreated);

        $revision = MeasurementRevision::query()->sole();
        $this->assertNull($revision->previous_value);
        $this->assertSame(MeasurementQuality::Missing, $revision->previous_quality);
        $this->assertSame('17.250000', $revision->corrected_value);

        $measurement = Measurement::query()->sole();
        $this->assertNull($measurement->original_value);
        $this->assertSame(MeasurementQuality::Missing, $measurement->original_quality);
        $this->assertSame('17.250000', $measurement->value);
    }

    #[Test]
    public function two_rows_in_one_batch_describing_the_same_observation_are_not_both_stored(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(),
                CanonicalRows::measurement(['value' => 44.4]),
            ]),
        );

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->rejected());
        $this->assertSame(RejectionReason::DuplicateInBatch, $result->rejections[0]->reason);
        $this->assertSame(1, Measurement::query()->count());
    }

    #[Test]
    public function a_row_declaring_a_different_source_than_its_batch_is_rejected(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['source' => 'somewhere-else']),
            ]),
        );

        $this->assertSame(0, Measurement::query()->count());
        $this->assertSame(RejectionReason::MalformedRow, $result->rejections[0]->reason);
    }

    #[Test]
    public function fractional_seconds_survive_a_write_and_a_read(): void
    {
        $this->seedRegistry();

        $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement([
                    'observed_at' => '2026-08-31T06:00:00.123456Z',
                    'received_at' => '2026-08-31T06:02:00.654321Z',
                    'source_updated_at' => '2026-08-31T06:02:00.111222Z',
                ]),
            ]),
        );

        $measurement = Measurement::query()->sole();

        $this->assertSame('2026-08-31 06:00:00.123456', $measurement->observed_at->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-08-31 06:02:00.654321', $measurement->received_at?->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-08-31 06:02:00.111222', $measurement->source_updated_at?->utc()->format('Y-m-d H:i:s.u'));
    }

    #[Test]
    public function two_observations_inside_one_second_are_stored_separately(): void
    {
        $this->seedRegistry();

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.100000Z', 'value' => 10.0]),
                CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.200000Z', 'value' => 20.0]),
            ]),
        );

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->rejected());
        $this->assertSame(2, Measurement::query()->count());

        $observed = Measurement::query()
            ->orderBy('observed_at')
            ->get()
            ->map(fn (Measurement $measurement): string => $measurement->observed_at->utc()->format('H:i:s.u'))
            ->all();

        $this->assertSame(['06:00:00.100000', '06:00:00.200000'], $observed);
    }

    #[Test]
    public function re_importing_two_observations_inside_one_second_creates_no_duplicates(): void
    {
        $this->seedRegistry();

        $rows = [
            CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.100000Z', 'value' => 10.0]),
            CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.200000Z', 'value' => 20.0]),
        ];

        $this->importer->importBatch(CanonicalRows::measurementBatch($rows));
        $second = $this->importer->importBatch(CanonicalRows::measurementBatch($rows));

        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(2, $second->unchanged);
        $this->assertSame(2, Measurement::query()->count());
    }

    #[Test]
    public function a_correction_updates_only_the_observation_with_the_matching_microseconds(): void
    {
        $this->seedRegistry();

        $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.100000Z', 'value' => 10.0]),
                CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.200000Z', 'value' => 20.0]),
            ]),
        );

        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement([
                    'observed_at' => '2026-08-31T06:00:00.200000Z',
                    'value' => 22.5,
                    'quality' => 'corrected',
                    'revision' => 2,
                ]),
            ]),
        );

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->revisionsCreated);
        $this->assertSame(2, Measurement::query()->count());

        $untouched = $this->measurementObservedAt('2026-08-31 06:00:00.100000');
        $this->assertSame('10.000000', $untouched->value);
        $this->assertSame(MeasurementQuality::Valid, $untouched->quality);
        $this->assertSame(1, $untouched->revision);

        $corrected = $this->measurementObservedAt('2026-08-31 06:00:00.200000');
        $this->assertSame('22.500000', $corrected->value);
        $this->assertSame('20.000000', $corrected->original_value);
        $this->assertSame(MeasurementQuality::Corrected, $corrected->quality);
        $this->assertSame(2, $corrected->revision);

        $revision = MeasurementRevision::query()->sole();
        $this->assertSame($corrected->id, $revision->measurement_id);
        $this->assertSame('20.000000', $revision->previous_value);
    }

    #[Test]
    public function a_correction_naming_a_neighbouring_instant_creates_a_new_observation(): void
    {
        $this->seedRegistry();

        $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement(['observed_at' => '2026-08-31T06:00:00.100000Z', 'value' => 10.0]),
            ]),
        );

        // Whole-second matching would have found the stored row and applied
        // this as a revision to it.
        $result = $this->importer->importBatch(
            CanonicalRows::measurementBatch([
                CanonicalRows::measurement([
                    'observed_at' => '2026-08-31T06:00:00.900000Z',
                    'value' => 99.0,
                    'revision' => 2,
                ]),
            ]),
        );

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->revisionsCreated);
        $this->assertSame('10.000000', $this->measurementObservedAt('2026-08-31 06:00:00.100000')->value);
    }

    #[Test]
    public function a_rejection_carries_no_stack_trace_or_file_path(): void
    {
        $this->importFixtureRegistry();

        $result = $this->importFixture(FixtureMeasurementScenario::Base);

        foreach ($result->rejections as $rejection) {
            $this->assertStringNotContainsString('#0 ', $rejection->detail);
            $this->assertStringNotContainsString('.php', $rejection->detail);
            $this->assertStringNotContainsString(base_path(), $rejection->detail);
            $this->assertDoesNotMatchRegularExpression('/\R/', $rejection->detail);
        }
    }

    /**
     * Look a stored observation up by its exact instant, microseconds included.
     */
    private function measurementObservedAt(string $utc): Measurement
    {
        return Measurement::query()->where('observed_at', $utc)->sole();
    }

    private function correctedMeasurement(): Measurement
    {
        return Measurement::query()
            ->where('source_measurement_id', 'FIXTURE-001-PM25-20260831T060000Z')
            ->sole();
    }
}
