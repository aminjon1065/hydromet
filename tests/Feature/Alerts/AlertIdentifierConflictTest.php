<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Data\AlertAreaRecord;
use App\Domain\Alerts\Data\AlertBatch;
use App\Domain\Alerts\Data\AlertRecord;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Data\SynchronizationOutcome;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Fixtures\FixtureAlertProvider;
use App\Domain\Integrations\Fixtures\FixtureAlertScenario;
use App\Domain\Integrations\Fixtures\FixtureIntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Integrations\Services\SynchronizationRunner;
use App\Support\Canonical\RejectionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens when a stored identifier arrives again.
 *
 * `source` + `identifier` is the published identity of a warning, so the answer
 * has to satisfy two things that pull in opposite directions: re-reading a feed
 * that still lists yesterday's warnings must change nothing, and the same
 * identifier carrying different content must never overwrite what was published
 * under it.
 *
 * The dangerous case is the second one. CAP corrects a warning by sending a new
 * message with a new identifier, so a changed body under an existing identifier
 * is a provider or feed fault. Reading its `message_type` and `references` —
 * which the importer used to do — is how a stored `Alert` resent as a `Cancel`
 * would withdraw warnings it never referenced.
 */
class AlertIdentifierConflictTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'test';

    private AlertImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = new AlertImporter;
    }

    // --- Idempotency -----------------------------------------------------

    #[Test]
    public function an_identical_repeat_is_unchanged_and_writes_nothing(): void
    {
        $this->importer->importBatch($this->batch([$this->alertRow()]));
        $before = $this->fingerprint();

        $result = $this->importer->importBatch($this->batch([$this->alertRow()]));

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->unchanged);
        $this->assertSame([], $result->rejections);
        $this->assertSame($before, $this->fingerprint());
    }

    /**
     * A serializer is free to reorder the keys of a JSON object, and CAP
     * defines `categories` and `references` as sets rather than sequences.
     * Neither is a content change, and treating one as a conflict would
     * quarantine a feed that had not changed at all.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function equivalentSpellings(): array
    {
        return [
            'reordered geocode keys' => [[
                'areas' => [[
                    'description' => [
                        'tj' => 'Минтақаи озмоишӣ',
                        'ru' => 'Тестовый регион',
                        'en' => 'Test region',
                    ],
                    'geocodes' => [['value' => 'TEST-REGION-A', 'name' => 'TEST_REGION']],
                    'geometry' => [
                        'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8], [68.4, 38.3]]],
                        'type' => 'Polygon',
                    ],
                    'altitude_m' => null,
                    'ceiling_m' => null,
                ]],
            ]],
            'reordered categories' => [['category' => ['Safety', 'Met']]],
            'whole degrees written as integers' => [[
                'areas' => [[
                    'description' => [
                        'tj' => 'Минтақаи озмоишӣ',
                        'ru' => 'Тестовый регион',
                        'en' => 'Test region',
                    ],
                    'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[68.4, 38.3], [69, 38.3], [69, 38.8], [68.4, 38.8], [68.4, 38.3]]],
                    ],
                    'altitude_m' => null,
                    'ceiling_m' => null,
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('equivalentSpellings')]
    public function a_repeat_spelled_differently_but_meaning_the_same_is_still_unchanged(array $overrides): void
    {
        $first = $this->alertRow(['category' => ['Met', 'Safety']]);
        $this->importer->importBatch($this->batch([$first]));

        $result = $this->importer->importBatch($this->batch([$this->alertRow([
            'category' => ['Met', 'Safety'],
            ...$overrides,
        ])]));

        $this->assertSame([], $result->rejections);
        $this->assertSame(1, $result->unchanged);
    }

    // --- Area and geocode order ------------------------------------------

    /*
     * A warning covers a *set* of areas, and each area carries a *set* of
     * geocodes. Which one a feed writes first is not information, and the
     * stored order is insertion order while the incoming order is the
     * provider's — so comparing them positionally reported a re-read of an
     * unchanged feed as `identifier_conflict`. That was reproduced on
     * `fixture-alert-0002`, whose two areas came back swapped.
     */

    #[Test]
    public function reversing_the_order_of_the_areas_is_not_a_change(): void
    {
        $this->importer->importBatch($this->batch([$this->twoAreaRow()]));
        $before = $this->fingerprint();

        $result = $this->importer->importBatch($this->batch([
            $this->twoAreaRow(['areas' => array_reverse($this->twoAreas())]),
        ]));

        $this->assertSame([], $result->rejections);
        $this->assertSame(1, $result->unchanged);
        $this->assertSame($before, $this->fingerprint());
    }

    #[Test]
    public function reversing_the_geocodes_inside_an_area_is_not_a_change(): void
    {
        $this->importer->importBatch($this->batch([$this->twoAreaRow()]));

        $areas = $this->twoAreas();
        $areas[0]['geocodes'] = array_reverse($areas[0]['geocodes']);
        $areas[1]['geocodes'] = array_reverse($areas[1]['geocodes']);

        $result = $this->importer->importBatch($this->batch([$this->twoAreaRow(['areas' => $areas])]));

        $this->assertSame([], $result->rejections);
        $this->assertSame(1, $result->unchanged);
    }

    #[Test]
    public function reordering_areas_geocodes_and_json_keys_at_once_is_not_a_change(): void
    {
        $this->importer->importBatch($this->batch([$this->twoAreaRow()]));
        $before = $this->fingerprint();

        $areas = array_reverse($this->twoAreas());

        foreach ($areas as $index => $area) {
            $areas[$index]['geocodes'] = array_map(
                // Same geocode, keys written the other way round.
                static fn (array $geocode): array => ['value' => $geocode['value'], 'name' => $geocode['name']],
                array_reverse($area['geocodes']),
            );
            $areas[$index]['geometry'] = [
                'coordinates' => $area['geometry']['coordinates'],
                'type' => $area['geometry']['type'],
            ];
        }

        $result = $this->importer->importBatch($this->batch([$this->twoAreaRow(['areas' => $areas])]));

        $this->assertSame([], $result->rejections);
        $this->assertSame(1, $result->unchanged);
        $this->assertSame($before, $this->fingerprint());
    }

    /**
     * Normalising order must not collapse duplicates: a feed that drops one of
     * two identical areas has changed what it published.
     */
    #[Test]
    public function a_repeat_that_drops_one_of_two_identical_areas_is_a_conflict(): void
    {
        $twice = [$this->twoAreas()[0], $this->twoAreas()[0]];

        $this->importer->importBatch($this->batch([$this->twoAreaRow(['areas' => $twice])]));
        $before = $this->fingerprint();

        $result = $this->importer->importBatch($this->batch([
            $this->twoAreaRow(['areas' => [$this->twoAreas()[0]]]),
        ]));

        $this->assertCount(1, $result->rejections);
        $this->assertSame(RejectionReason::IdentifierConflict, $result->rejections[0]->reason);
        $this->assertSame($before, $this->fingerprint());
    }

    #[Test]
    public function a_repeat_that_duplicates_an_area_is_a_conflict(): void
    {
        $this->importer->importBatch($this->batch([$this->twoAreaRow(['areas' => [$this->twoAreas()[0]]])]));

        $result = $this->importer->importBatch($this->batch([
            $this->twoAreaRow(['areas' => [$this->twoAreas()[0], $this->twoAreas()[0]]]),
        ]));

        $this->assertCount(1, $result->rejections);
        $this->assertSame(RejectionReason::IdentifierConflict, $result->rejections[0]->reason);
    }

    /**
     * @return array<string, array{callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>}>
     */
    public static function areaContentChanges(): array
    {
        return [
            'an area is removed' => [static fn (array $areas): array => [$areas[0]]],
            'an added geocode' => [static function (array $areas): array {
                $areas[0]['geocodes'][] = ['name' => 'TEST_REGION', 'value' => 'TEST-REGION-Z'];

                return $areas;
            }],
            'a removed geocode' => [static function (array $areas): array {
                $areas[0]['geocodes'] = [$areas[0]['geocodes'][0]];

                return $areas;
            }],
            'a changed geocode value' => [static function (array $areas): array {
                $areas[1]['geocodes'][0]['value'] = 'TEST-REGION-CHANGED';

                return $areas;
            }],
            'a changed description' => [static function (array $areas): array {
                $areas[1]['description']['ru'] = 'Совсем другой регион';

                return $areas;
            }],
            'a moved vertex' => [static function (array $areas): array {
                $areas[0]['geometry']['coordinates'][0][1][0] = 70.0;

                return $areas;
            }],
            'a reversed polygon ring' => [static function (array $areas): array {
                $areas[0]['geometry']['coordinates'][0] = array_reverse($areas[0]['geometry']['coordinates'][0]);

                return $areas;
            }],
            'a changed altitude' => [static function (array $areas): array {
                $areas[1]['altitude_m'] = 1200.0;

                return $areas;
            }],
            'a changed ceiling' => [static function (array $areas): array {
                $areas[1]['ceiling_m'] = 5000.0;

                return $areas;
            }],
        ];
    }

    /**
     * @param  callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>  $change
     */
    #[Test]
    #[DataProvider('areaContentChanges')]
    public function a_change_to_what_an_area_says_is_still_a_conflict(callable $change): void
    {
        $this->importer->importBatch($this->batch([$this->twoAreaRow()]));
        $before = $this->fingerprint();

        $result = $this->importer->importBatch($this->batch([
            $this->twoAreaRow(['areas' => array_values($change($this->twoAreas()))]),
        ]));

        $this->assertCount(1, $result->rejections);
        $this->assertSame(RejectionReason::IdentifierConflict, $result->rejections[0]->reason);
        $this->assertSame($before, $this->fingerprint());
    }

    /**
     * The defect as it was actually met: the checked-in multi-area fixture,
     * re-imported with its two areas the other way round.
     */
    #[Test]
    public function the_multi_area_fixture_survives_a_reordered_re_read(): void
    {
        $importer = new AlertImporter;
        $importer->import(new FixtureAlertProvider(FixtureAlertScenario::Baseline));

        $stored = AlertMessage::query()
            ->where('identifier', 'fixture-alert-0002')
            ->firstOrFail();

        $this->assertGreaterThan(1, $stored->areas()->count());

        $before = $this->fingerprint();
        $batch = (new FixtureAlertProvider(FixtureAlertScenario::Baseline))->fetchAlerts();
        $reordered = [];

        foreach ($batch->records as $record) {
            $reordered[] = $record->identifier === 'fixture-alert-0002'
                ? $this->withAreas($record, array_reverse($record->areas))
                : $record;
        }

        $result = $importer->importBatch(new AlertBatch($batch->source, $reordered));

        $this->assertSame(
            [],
            array_values(array_filter(
                $result->rejections,
                static fn ($rejection): bool => $rejection->reason === RejectionReason::IdentifierConflict,
            )),
        );
        $this->assertSame($before, $this->fingerprint());
    }

    // --- Conflict --------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function contradictoryRepeats(): array
    {
        return [
            'headline' => [['headline' => [
                'tj' => 'Огоҳии дигар',
                'ru' => 'Другое предупреждение',
                'en' => 'A different warning',
            ]]],
            'description' => [['description' => [
                'tj' => 'Тавсифи дигар.',
                'ru' => 'Другое описание.',
                'en' => 'A different description.',
            ]]],
            'event code' => [['event_code' => 'A_DIFFERENT_EVENT']],
            'severity' => [['severity' => 'Extreme']],
            'urgency' => [['urgency' => 'Immediate']],
            'sender' => [['sender' => 'someone-else']],
            'expiry' => [['expires_at' => '2031-01-01T00:00:00Z']],
            'effective time' => [['effective_at' => '2026-01-16T05:00:00Z']],
            'scope' => [['scope' => 'Restricted']],
            'status' => [['status' => 'Test']],
            'a moved area vertex' => [['areas' => [[
                'description' => [
                    'tj' => 'Минтақаи озмоишӣ',
                    'ru' => 'Тестовый регион',
                    'en' => 'Test region',
                ],
                'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[68.4, 38.3], [70.0, 38.3], [70.0, 38.8], [68.4, 38.8], [68.4, 38.3]]],
                ],
                'altitude_m' => null,
                'ceiling_m' => null,
            ]]]],
            'an extra area' => [['areas' => [
                [
                    'description' => [
                        'tj' => 'Минтақаи озмоишӣ',
                        'ru' => 'Тестовый регион',
                        'en' => 'Test region',
                    ],
                    'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8], [68.4, 38.3]]],
                    ],
                    'altitude_m' => null,
                    'ceiling_m' => null,
                ],
                [
                    'description' => [
                        'tj' => 'Минтақаи дуюм',
                        'ru' => 'Второй регион',
                        'en' => 'Second region',
                    ],
                    'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-B']],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[70.4, 38.3], [71.0, 38.3], [71.0, 38.8], [70.4, 38.8], [70.4, 38.3]]],
                    ],
                    'altitude_m' => null,
                    'ceiling_m' => null,
                ],
            ]]],
            'a removed area description translation' => [['areas' => [[
                'description' => [
                    'tj' => 'Минтақаи озмоишӣ',
                    'ru' => 'Совсем другой регион',
                    'en' => 'Test region',
                ],
                'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8], [68.4, 38.3]]],
                ],
                'altitude_m' => null,
                'ceiling_m' => null,
            ]]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('contradictoryRepeats')]
    public function a_repeat_with_different_content_is_rejected_and_changes_nothing(array $overrides): void
    {
        $this->importer->importBatch($this->batch([$this->alertRow()]));
        $before = $this->fingerprint();

        $result = $this->importer->importBatch($this->batch([$this->alertRow($overrides)]));

        $this->assertCount(1, $result->rejections);
        $this->assertSame(RejectionReason::IdentifierConflict, $result->rejections[0]->reason);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->unchanged);
        $this->assertSame(0, $result->updated);

        // Not a second row, and not a rewritten first one.
        $this->assertSame(1, AlertMessage::query()->count());
        $this->assertSame($before, $this->fingerprint());
    }

    /**
     * The reason this is a rejection rather than an overwrite: the stored
     * message decides what it supersedes, so an identifier resent as a `Cancel`
     * cannot withdraw a warning the stored message never referenced.
     */
    #[Test]
    public function a_stored_alert_resent_as_a_cancellation_withdraws_nothing(): void
    {
        $this->importer->importBatch($this->batch([
            $this->alertRow(['identifier' => 'test-alert-0001']),
            $this->alertRow(['identifier' => 'test-alert-0002']),
        ]));

        $result = $this->importer->importBatch($this->batch([
            $this->alertRow([
                'identifier' => 'test-alert-0001',
                'message_type' => 'Cancel',
                'references' => ['test-alert-0002'],
                'sent_at' => '2026-01-16T05:00:00Z',
                'effective_at' => '2026-01-16T05:00:00Z',
            ]),
        ]));

        $this->assertCount(1, $result->rejections);
        $this->assertSame(RejectionReason::IdentifierConflict, $result->rejections[0]->reason);
        $this->assertSame(0, $result->superseded);

        // The bystander is untouched, and so is the message that was resent.
        $this->assertNull($this->message('test-alert-0002')->superseded_at);
        $this->assertNull($this->message('test-alert-0002')->superseded_by_id);
        $this->assertSame('Alert', $this->message('test-alert-0001')->message_type->value);
        $this->assertSame([], $this->message('test-alert-0001')->references);
    }

    #[Test]
    public function a_stored_alert_resent_as_an_update_supersedes_nothing(): void
    {
        $this->importer->importBatch($this->batch([
            $this->alertRow(['identifier' => 'test-alert-0001']),
            $this->alertRow(['identifier' => 'test-alert-0002']),
        ]));

        $result = $this->importer->importBatch($this->batch([
            $this->alertRow([
                'identifier' => 'test-alert-0001',
                'message_type' => 'Update',
                'references' => ['test-alert-0002'],
                'sent_at' => '2026-01-16T05:00:00Z',
                'effective_at' => '2026-01-16T05:00:00Z',
            ]),
        ]));

        $this->assertSame(0, $result->superseded);
        $this->assertSame(
            0,
            AlertMessage::query()->whereNotNull('superseded_at')->count(),
        );
    }

    #[Test]
    public function the_stored_areas_survive_a_conflict_untouched(): void
    {
        $this->importer->importBatch($this->batch([$this->alertRow()]));
        $areas = AlertArea::query()->orderBy('id')->get()->toArray();

        $this->importer->importBatch($this->batch([$this->alertRow([
            'areas' => [[
                'description' => [
                    'tj' => 'Минтақаи дигар',
                    'ru' => 'Другой регион',
                    'en' => 'Another region',
                ],
                'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-Z']],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[60.0, 30.0], [61.0, 30.0], [61.0, 31.0], [60.0, 31.0], [60.0, 30.0]]],
                ],
                'altitude_m' => null,
                'ceiling_m' => null,
            ]],
        ])]));

        $this->assertSame(1, AlertArea::query()->count());
        $this->assertEquals($areas, AlertArea::query()->orderBy('id')->get()->toArray());
    }

    /**
     * A conflict has to be visible to an operator, with a stable code and no
     * provider payload attached to it.
     */
    #[Test]
    public function the_conflict_is_quarantined_in_the_synchronization_run(): void
    {
        $this->importer->importBatch($this->batch([$this->alertRow()]));

        app(SynchronizationRunner::class)->run(
            FixtureIntegrationSource::ensure(),
            SynchronizationKind::Alerts,
            function (): SynchronizationOutcome {
                $result = $this->importer->importBatch(
                    $this->batch([$this->alertRow(['severity' => 'Extreme'])]),
                );

                return SynchronizationOutcome::make(
                    $result->received,
                    $result->accepted(),
                    $result->updated,
                    $result->rejections,
                );
            },
        );

        $run = SynchronizationRun::query()->latest('id')->firstOrFail();

        $this->assertSame(SynchronizationStatus::Partial, $run->status);
        $this->assertSame(1, $run->rejected_count);

        $rejected = $run->rejectedRows()->sole();

        $this->assertSame(RejectionReason::IdentifierConflict, $rejected->reason_code);
        $this->assertStringContainsString('test-alert-0001', $rejected->reference);
        // The quarantined row carries a stable code and a safe sentence, never
        // the provider payload that caused it.
        $this->assertStringNotContainsString('Extreme', $rejected->safe_detail);
    }

    /**
     * A conflict on one message must not discard the warnings beside it.
     */
    #[Test]
    public function the_messages_around_a_conflict_are_still_imported(): void
    {
        $this->importer->importBatch($this->batch([$this->alertRow(['identifier' => 'test-alert-0001'])]));

        $result = $this->importer->importBatch($this->batch([
            $this->alertRow(['identifier' => 'test-alert-0001', 'severity' => 'Extreme']),
            $this->alertRow(['identifier' => 'test-alert-0009']),
        ]));

        $this->assertCount(1, $result->rejections);
        $this->assertSame(1, $result->created);
        $this->assertSame('Severe', $this->message('test-alert-0001')->severity->value);
        $this->assertSame('test-alert-0009', $this->message('test-alert-0009')->identifier);
    }

    // --- Helpers ---------------------------------------------------------

    private function message(string $identifier): AlertMessage
    {
        return AlertMessage::query()
            ->where('source', self::SOURCE)
            ->where('identifier', $identifier)
            ->sole();
    }

    /**
     * Every stored byte of both tables, so "changed nothing" is asserted rather
     * than sampled.
     *
     * @return array<string, mixed>
     */
    private function fingerprint(): array
    {
        $rows = static fn (string $table): array => array_map(
            // Compared as data, not as objects: two reads of an unchanged table
            // return equal rows in different instances.
            static fn (object $row): array => (array) $row,
            DB::table($table)->orderBy('id')->get()->all(),
        );

        return [
            'messages' => $rows('alert_messages'),
            'areas' => $rows('alert_areas'),
        ];
    }

    /**
     * Two distinct areas, each carrying two geocodes, so reordering has
     * something to reorder.
     *
     * @return array<int, array<string, mixed>>
     */
    private function twoAreas(): array
    {
        return [
            [
                'description' => [
                    'tj' => 'Минтақаи озмоишӣ A',
                    'ru' => 'Тестовый регион A',
                    'en' => 'Test region A',
                ],
                'geocodes' => [
                    ['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A'],
                    ['name' => 'TEST_DISTRICT', 'value' => 'TEST-DISTRICT-A1'],
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8], [68.4, 38.3]]],
                ],
                'altitude_m' => null,
                'ceiling_m' => null,
            ],
            [
                'description' => [
                    'tj' => 'Минтақаи озмоишӣ B',
                    'ru' => 'Тестовый регион B',
                    'en' => 'Test region B',
                ],
                'geocodes' => [
                    ['name' => 'TEST_REGION', 'value' => 'TEST-REGION-B'],
                    ['name' => 'TEST_DISTRICT', 'value' => 'TEST-DISTRICT-B1'],
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[70.4, 38.3], [71.0, 38.3], [71.0, 38.8], [70.4, 38.8], [70.4, 38.3]]],
                ],
                'altitude_m' => 1500.0,
                'ceiling_m' => 4000.0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function twoAreaRow(array $overrides = []): array
    {
        return $this->alertRow(['areas' => $this->twoAreas(), ...$overrides]);
    }

    /**
     * The same canonical record with a different area order.
     *
     * @param  array<int, AlertAreaRecord>  $areas
     */
    private function withAreas(AlertRecord $record, array $areas): AlertRecord
    {
        return new AlertRecord(
            $record->source,
            $record->identifier,
            $record->sender,
            $record->status,
            $record->messageType,
            $record->scope,
            $record->eventCode,
            $record->severity,
            $record->urgency,
            $record->certainty,
            $record->categories,
            $record->references,
            $record->parameters,
            $record->sentAt,
            $record->effectiveAt,
            $record->onsetAt,
            $record->expiresAt,
            $record->headlineTj,
            $record->headlineRu,
            $record->headlineEn,
            $record->descriptionTj,
            $record->descriptionRu,
            $record->descriptionEn,
            $record->instructionTj,
            $record->instructionRu,
            $record->instructionEn,
            array_values($areas),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function batch(array $rows): AlertBatch
    {
        return new AlertBatch(
            self::SOURCE,
            array_map(static fn (array $row): AlertRecord => AlertRecord::fromCanonical($row), $rows),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function alertRow(array $overrides = []): array
    {
        return [
            'source' => self::SOURCE,
            'identifier' => 'test-alert-0001',
            'sender' => 'test-warning-desk',
            'status' => 'Actual',
            'message_type' => 'Alert',
            'scope' => 'Public',
            'event_code' => 'TEST_EVENT',
            'severity' => 'Severe',
            'urgency' => 'Expected',
            'certainty' => 'Likely',
            'category' => ['Met'],
            'references' => [],
            'parameters' => [],
            'sent_at' => '2026-01-15T05:00:00Z',
            'effective_at' => '2026-01-15T05:00:00Z',
            'onset_at' => null,
            'expires_at' => '2030-01-01T00:00:00Z',
            'headline' => [
                'tj' => 'Огоҳии озмоишӣ',
                'ru' => 'Тестовое предупреждение',
                'en' => 'Test warning',
            ],
            'description' => [
                'tj' => 'Тавсифи озмоишӣ.',
                'ru' => 'Тестовое описание.',
                'en' => 'Test description.',
            ],
            'instruction' => null,
            'areas' => [[
                'description' => [
                    'tj' => 'Минтақаи озмоишӣ',
                    'ru' => 'Тестовый регион',
                    'en' => 'Test region',
                ],
                'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8], [68.4, 38.3]]],
                ],
                'altitude_m' => null,
                'ceiling_m' => null,
            ]],
            ...$overrides,
        ];
    }
}
