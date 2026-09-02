<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Data\AlertBatch;
use App\Domain\Alerts\Data\AlertImportResult;
use App\Domain\Alerts\Data\AlertRecord;
use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Queries\PublicAlertOverview;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Fixtures\FixtureAlertProvider;
use App\Domain\Integrations\Fixtures\FixtureAlertScenario;
use App\Support\Canonical\RejectionReason;
use App\Support\Locale\SupportedLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Warning import and the public read that depends on it.
 *
 * The CAP lifecycle rules of docs/06-testing-and-acceptance.md (ALERT-01..04)
 * are exercised end to end: what the importer stores, what it refuses, and what
 * a public client may therefore see. Both halves belong in one class because
 * "superseded" and "expired" are only observable through the read side — the
 * importer writes no `is_active` column for a test to inspect.
 *
 * Every publication assertion states its own moment. The suite must not decide
 * whether a warning is in force by consulting the clock it happens to run on.
 */
class AlertImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Source key of the hand-built batches. It matches the alert factory, so a
     * factory row and a canonical row describe the same feed.
     */
    private const SOURCE = 'test';

    /**
     * A moment inside the validity window of every default fixture and factory
     * warning, and after every fixture send time.
     */
    private const IN_FORCE = '2026-02-01T00:00:00Z';

    private AlertImporter $importer;

    private PublicAlertOverview $overview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = new AlertImporter;
        $this->overview = new PublicAlertOverview;
    }

    #[Test]
    public function the_baseline_fixture_stores_every_readable_warning(): void
    {
        $result = $this->importBaseline();

        $this->assertSame(5, $result->received);
        $this->assertSame(4, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->unchanged);
        $this->assertSame(0, $result->superseded);
        $this->assertSame(1, $result->rejected());
        $this->assertTrue($result->isPartial());

        $this->assertSame(4, AlertMessage::query()->count());
        $this->assertSame(5, AlertArea::query()->count());
    }

    #[Test]
    public function the_undrawable_warning_area_is_the_only_rejected_row(): void
    {
        $result = $this->importBaseline();

        $this->assertCount(1, $result->rejections);
        $this->assertSame(RejectionReason::UnsupportedGeometry, $result->rejections[0]->reason);
        $this->assertSame('fixture:fixture-alert-0005', $result->rejections[0]->reference);
        $this->assertSame(0, AlertMessage::query()->where('identifier', 'fixture-alert-0005')->count());
    }

    #[Test]
    public function a_warning_keeps_every_area_it_declared(): void
    {
        $this->importBaseline();

        $single = $this->message('fixture-alert-0001');
        $this->assertCount(1, $single->areas);
        $area = $single->areas->first();
        $this->assertNotNull($area);
        $this->assertTrue($area->isDrawable());

        // Decimal columns, so the derived extent survives a re-import
        // byte-for-byte instead of drifting through a float.
        $this->assertSame('68.400000', $area->bbox_west);
        $this->assertSame('38.300000', $area->bbox_south);
        $this->assertSame('69.000000', $area->bbox_east);
        $this->assertSame('38.800000', $area->bbox_north);

        $multi = $this->message('fixture-alert-0002');
        $this->assertCount(2, $multi->areas);

        $elevated = $multi->areas->last();
        $this->assertNotNull($elevated);
        $this->assertSame('MultiPolygon', $elevated->geometry['type'] ?? null);
        $this->assertSame('1500.00', $elevated->altitude_m);
        $this->assertSame('4000.00', $elevated->ceiling_m);
    }

    #[Test]
    public function repeating_the_baseline_import_stores_nothing_new(): void
    {
        $this->importBaseline();

        $areaIds = AlertArea::query()->orderBy('id')->pluck('id')->all();

        $second = $this->importBaseline();

        $this->assertSame(5, $second->received);
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(4, $second->unchanged);
        $this->assertSame(0, $second->superseded);

        $this->assertSame(4, AlertMessage::query()->count());
        // Areas are written only with their message, so a duplicated area would
        // be the visible symptom of a message stored twice.
        $this->assertSame($areaIds, AlertArea::query()->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function only_the_warnings_in_force_reach_a_public_client(): void
    {
        $this->importBaseline();

        $active = $this->overview->active($this->moment(), SupportedLocale::English);

        // The expired one, the Test-status one and the rejected one are all
        // absent for different reasons; the assertion names the whole set so a
        // newly leaking category cannot hide behind a count.
        $this->assertSame(
            ['fixture-alert-0001', 'fixture-alert-0002'],
            $this->identifiers($active),
        );

        $this->assertSame('Fixture warning: heavy rain (fixture)', $active[0]['headline']);
        $this->assertSame(
            'This is demonstration text, not an official recommendation.',
            $active[0]['instruction'],
        );
        $this->assertTrue($active[0]['isMock']);
        $this->assertCount(2, $active[1]['areas']);
        $this->assertNull($active[1]['instruction']);
    }

    #[Test]
    public function an_update_supersedes_its_predecessor_without_deleting_it(): void
    {
        $this->importBaseline();

        $result = $this->importLifecycle();

        $this->assertSame(2, $result->received);
        $this->assertSame(2, $result->created);
        $this->assertSame(2, $result->superseded);
        $this->assertSame(0, $result->rejected());

        $update = $this->message('fixture-alert-0001-update-1');
        $replaced = $this->message('fixture-alert-0001');

        $this->assertSame(AlertMessageType::Update, $update->message_type);
        $this->assertTrue($replaced->isSuperseded());
        $this->assertSame($update->id, $replaced->superseded_by_id);
        $this->assertSame('2026-01-15 06:30:00.000000', $this->stamp($replaced->superseded_at));

        // ALERT-02: the predecessor stays queryable, otherwise the portal could
        // no longer answer what it published before the update arrived.
        $this->assertSame(6, AlertMessage::query()->count());
        $this->assertSame(1, $update->supersedes()->count());

        $active = $this->overview->active($this->moment(), SupportedLocale::English);
        $this->assertContains('fixture-alert-0001-update-1', $this->identifiers($active));
        $this->assertNotContains('fixture-alert-0001', $this->identifiers($active));
    }

    #[Test]
    public function a_cancellation_withdraws_its_reference_and_is_never_shown_itself(): void
    {
        $this->importBaseline();
        $this->importLifecycle();

        $cancel = $this->message('fixture-alert-0002-cancel-1');
        $cancelled = $this->message('fixture-alert-0002');

        $this->assertSame(AlertMessageType::Cancel, $cancel->message_type);
        $this->assertSame($cancel->id, $cancelled->superseded_by_id);

        $identifiers = $this->identifiers($this->overview->active($this->moment(), SupportedLocale::English));

        $this->assertNotContains('fixture-alert-0002', $identifiers);
        // ALERT-03: a Cancel is not a warning. Showing it would put a
        // "this is over" card on the map exactly where the warning stood.
        $this->assertNotContains('fixture-alert-0002-cancel-1', $identifiers);
        $this->assertSame(['fixture-alert-0001-update-1'], $identifiers);

        $this->assertSame(2, AlertMessage::query()
            ->whereIn('identifier', ['fixture-alert-0002', 'fixture-alert-0002-cancel-1'])
            ->count());
    }

    #[Test]
    public function an_expired_warning_is_stored_but_leaves_the_public_view_at_its_expiry(): void
    {
        $this->importBaseline();

        $expired = $this->message('fixture-alert-0003');
        $this->assertSame('2026-01-02 00:00:00.000000', $this->stamp($expired->expires_at));

        // ALERT-04 needs no write: expiry is a comparison, so the same stored
        // row flips purely on the moment the question is asked.
        $before = $this->moment('2026-01-01T12:00:00Z');
        $this->assertSame(['fixture-alert-0003'], $this->identifiers(
            $this->overview->active($before, SupportedLocale::English),
        ));
        $this->assertTrue($expired->isActiveAt($before));

        // The window is half-open: at expires_at the warning is already over.
        $atExpiry = $this->moment('2026-01-02T00:00:00Z');
        $this->assertSame([], $this->identifiers(
            $this->overview->active($atExpiry, SupportedLocale::English),
        ));
        $this->assertFalse($expired->isActiveAt($atExpiry));

        $after = $this->moment('2026-01-03T00:00:00Z');
        $this->assertSame([], $this->identifiers(
            $this->overview->active($after, SupportedLocale::English),
        ));
        $this->assertFalse($expired->isActiveAt($after));
    }

    #[Test]
    public function repeating_the_lifecycle_import_re_supersedes_nothing(): void
    {
        $this->importBaseline();
        $this->importLifecycle();

        $stampedAt = $this->stamp($this->message('fixture-alert-0001')->superseded_at);
        $supersededBy = $this->message('fixture-alert-0001')->superseded_by_id;

        $second = $this->importLifecycle();

        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(2, $second->unchanged);
        $this->assertSame(0, $second->superseded);
        $this->assertSame(6, AlertMessage::query()->count());

        // A re-stamped withdrawal would silently rewrite when the warning
        // stopped being in force, which is the one fact the history must keep.
        $replaced = $this->message('fixture-alert-0001');
        $this->assertSame($stampedAt, $this->stamp($replaced->superseded_at));
        $this->assertSame($supersededBy, $replaced->superseded_by_id);
    }

    #[Test]
    public function supersession_records_when_the_replacement_was_sent_not_when_it_was_imported(): void
    {
        $this->importBaseline();

        // Frozen well away from every fixture send time, so "the moment the
        // importer ran" and "the moment the replacement was sent" cannot be
        // confused for one another.
        $this->travelTo(Carbon::parse('2026-03-01T00:00:00Z'), function (): void {
            $this->importLifecycle();

            $update = $this->message('fixture-alert-0001-update-1');
            $replaced = $this->message('fixture-alert-0001');

            $this->assertSame('2026-03-01 00:00:00.000000', $this->stamp($update->imported_at));
            $this->assertSame($this->stamp($update->sent_at), $this->stamp($replaced->superseded_at));
            $this->assertSame('2026-01-15 06:30:00.000000', $this->stamp($replaced->superseded_at));
        });
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supersedingMessageTypes(): array
    {
        return [
            'an Update' => ['Update'],
            'a Cancel' => ['Cancel'],
        ];
    }

    #[Test]
    #[DataProvider('supersedingMessageTypes')]
    public function a_superseding_message_that_references_nothing_is_rejected(string $messageType): void
    {
        $result = $this->importer->importBatch($this->batch([
            $this->alertRow(['message_type' => $messageType, 'references' => []]),
        ]));

        // Storing it would leave the withdrawn warning on the map with nothing
        // recording that it had been replaced.
        $this->assertSame(0, AlertMessage::query()->count());
        $this->assertSame(RejectionReason::MissingReference, $result->rejections[0]->reason);
        $this->assertStringContainsString($messageType, $result->rejections[0]->detail);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function impossibleValidityWindows(): array
    {
        return [
            'expiry equal to the send time' => [['expires_at' => '2026-01-15T05:00:00Z']],
            'expiry before the send time' => [['expires_at' => '2026-01-15T04:00:00Z']],
            'effective before the send time' => [['effective_at' => '2026-01-15T04:00:00Z']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('impossibleValidityWindows')]
    public function a_warning_that_was_never_in_force_is_rejected(array $overrides): void
    {
        $result = $this->importer->importBatch($this->batch([$this->alertRow($overrides)]));

        $this->assertSame(0, AlertMessage::query()->count());
        $this->assertSame(RejectionReason::InvalidValidityWindow, $result->rejections[0]->reason);
    }

    #[Test]
    public function two_messages_in_one_batch_sharing_an_identifier_are_not_both_stored(): void
    {
        $result = $this->importer->importBatch($this->batch([
            $this->alertRow([
                'identifier' => 'test-alert-duplicate',
                'sent_at' => '2026-01-15T05:00:00Z',
                'event_code' => 'TEST_FIRST',
            ]),
            $this->alertRow([
                'identifier' => 'test-alert-duplicate',
                'sent_at' => '2026-01-15T05:10:00Z',
                'effective_at' => '2026-01-15T05:10:00Z',
                'event_code' => 'TEST_SECOND',
            ]),
        ]));

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->rejected());
        $this->assertSame(RejectionReason::DuplicateInBatch, $result->rejections[0]->reason);
        $this->assertSame(1, AlertMessage::query()->count());
        // The earlier message keeps the identifier; the later one is refused
        // rather than quietly overwriting it.
        $this->assertSame('TEST_FIRST', $this->message('test-alert-duplicate')->event_code);
    }

    #[Test]
    public function a_message_declaring_a_different_source_than_its_batch_is_rejected(): void
    {
        $result = $this->importer->importBatch($this->batch([
            $this->alertRow(['source' => 'somewhere-else']),
        ]));

        $this->assertSame(0, AlertMessage::query()->count());
        $this->assertSame(RejectionReason::MalformedRow, $result->rejections[0]->reason);
    }

    #[Test]
    public function an_update_arriving_before_its_alert_in_the_batch_still_resolves(): void
    {
        // A feed is a snapshot, not a queue: array order carries no meaning.
        // Applied as listed, the Update would find nothing to supersede and the
        // withdrawn warning would stay on the map.
        $result = $this->importer->importBatch($this->batch([
            $this->alertRow([
                'identifier' => 'test-alert-0001-update-1',
                'message_type' => 'Update',
                'references' => ['test-alert-0001'],
                'sent_at' => '2026-01-15T06:30:00Z',
                'effective_at' => '2026-01-15T06:30:00Z',
            ]),
            $this->alertRow([
                'identifier' => 'test-alert-0001',
                'sent_at' => '2026-01-15T05:00:00Z',
            ]),
        ]));

        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->superseded);
        $this->assertSame(0, $result->rejected());

        $update = $this->message('test-alert-0001-update-1');
        $replaced = $this->message('test-alert-0001');

        $this->assertSame($update->id, $replaced->superseded_by_id);
        $this->assertSame('2026-01-15 06:30:00.000000', $this->stamp($replaced->superseded_at));

        $this->assertSame(['test-alert-0001-update-1'], $this->identifiers(
            $this->overview->active($this->moment(), SupportedLocale::English),
        ));
    }

    #[Test]
    public function one_rejected_message_does_not_discard_the_valid_messages_beside_it(): void
    {
        $result = $this->importer->importBatch($this->batch([
            $this->alertRow(['identifier' => 'test-alert-before']),
            $this->alertRow([
                'identifier' => 'test-alert-broken',
                'sent_at' => '2026-01-15T05:10:00Z',
                'effective_at' => '2026-01-15T05:10:00Z',
                'expires_at' => '2026-01-15T05:00:00Z',
            ]),
            $this->alertRow([
                'identifier' => 'test-alert-after',
                'sent_at' => '2026-01-15T05:20:00Z',
                'effective_at' => '2026-01-15T05:20:00Z',
            ]),
        ]));

        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->rejected());
        $this->assertSame(2, AlertMessage::query()->count());
        $this->assertSame(2, AlertArea::query()->count());
        $this->assertSame(
            ['test-alert-after', 'test-alert-before'],
            AlertMessage::query()->orderBy('identifier')->pluck('identifier')->all(),
        );
    }

    #[Test]
    public function a_rejection_carries_no_stack_trace_or_file_path(): void
    {
        $rejections = $this->importBaseline()->rejections;

        // Every rejection the importer itself raises, alongside the adapter's,
        // because both end up in the same operator-facing output.
        $handBuilt = $this->importer->importBatch($this->batch([
            $this->alertRow(['identifier' => 'test-alert-no-reference', 'message_type' => 'Cancel']),
            $this->alertRow(['identifier' => 'test-alert-bad-window', 'expires_at' => '2026-01-15T04:00:00Z']),
            $this->alertRow(['identifier' => 'test-alert-wrong-source', 'source' => 'somewhere-else']),
        ]));

        $rejections = [...$rejections, ...$handBuilt->rejections];
        $this->assertCount(4, $rejections);

        foreach ($rejections as $rejection) {
            $this->assertStringNotContainsString('#0 ', $rejection->detail);
            $this->assertStringNotContainsString('.php', $rejection->detail);
            $this->assertStringNotContainsString(base_path(), $rejection->detail);
            $this->assertDoesNotMatchRegularExpression('/\R/', $rejection->detail);
            $this->assertDoesNotMatchRegularExpression('/\R/', $rejection->reference);
        }
    }

    #[Test]
    public function a_test_or_restricted_message_is_stored_but_never_published(): void
    {
        AlertMessage::factory()->testStatus()->create(['identifier' => 'test-alert-exercise']);
        AlertMessage::factory()->restricted()->create(['identifier' => 'test-alert-restricted']);
        AlertMessage::factory()->create(['identifier' => 'test-alert-public']);

        // Stored so an operator can see they arrived: dropping them at the
        // adapter would leave nobody able to audit the decision.
        $this->assertSame(3, AlertMessage::query()->count());

        $this->assertSame(['test-alert-public'], $this->identifiers(
            $this->overview->active($this->moment(), SupportedLocale::English),
        ));
    }

    #[Test]
    public function the_extent_filter_returns_only_warnings_that_overlap_it(): void
    {
        $inside = AlertMessage::factory()->create(['identifier' => 'test-alert-inside']);
        AlertArea::factory()
            ->at(['west' => 68.0, 'south' => 38.0, 'east' => 69.0, 'north' => 39.0])
            ->create(['alert_message_id' => $inside->id]);

        $outside = AlertMessage::factory()->create(['identifier' => 'test-alert-outside']);
        AlertArea::factory()
            ->at(['west' => 74.0, 'south' => 40.0, 'east' => 75.0, 'north' => 41.0])
            ->create(['alert_message_id' => $outside->id]);

        $geocodeOnly = AlertMessage::factory()->create(['identifier' => 'test-alert-geocode']);
        AlertArea::factory()->withoutGeometry()->create(['alert_message_id' => $geocodeOnly->id]);

        // All three are in force, so anything missing below was removed by the
        // extent filter and not by a publication rule.
        $this->assertSame(
            ['test-alert-geocode', 'test-alert-inside', 'test-alert-outside'],
            $this->identifiers($this->overview->active($this->moment(), SupportedLocale::English)),
        );

        $found = $this->overview->active(
            $this->moment(),
            SupportedLocale::English,
            ['west' => 68.5, 'south' => 38.5, 'east' => 69.5, 'north' => 39.5],
        );

        // A geocode-only area has no shape, so no extent can contain it. Such a
        // warning stays invisible on the map until Hydromet supplies the
        // administrative boundary dataset.
        $this->assertSame(['test-alert-inside'], $this->identifiers($found));
    }

    #[Test]
    public function a_non_public_message_has_no_addressable_detail(): void
    {
        AlertMessage::factory()->restricted()->create(['identifier' => 'test-alert-restricted']);
        AlertMessage::factory()->testStatus()->create(['identifier' => 'test-alert-exercise']);

        // Guessing the identifier must not be enough to read a message that was
        // never addressed to the public.
        $this->assertNull($this->overview->detail(self::SOURCE, 'test-alert-restricted'));
        $this->assertNull($this->overview->detail(self::SOURCE, 'test-alert-exercise'));
        $this->assertNull($this->overview->detail(self::SOURCE, 'test-alert-never-received'));
    }

    #[Test]
    public function the_detail_of_a_superseded_warning_still_explains_what_replaced_it(): void
    {
        $this->importBaseline();
        $this->importLifecycle();

        $detail = $this->overview->detail(
            FixtureAlertProvider::SOURCE_KEY,
            'fixture-alert-0001',
            SupportedLocale::English,
        );

        $this->assertNotNull($detail);
        $this->assertSame('fixture-alert-0001', $detail['current']['identifier']);
        $this->assertFalse($detail['current']['isActive']);
        $this->assertSame('2026-01-15T06:30:00Z', $detail['current']['supersededAt']);

        $this->assertSame(
            ['fixture-alert-0001-update-1', 'fixture-alert-0001'],
            array_map(static fn (array $entry): string => $entry['identifier'], $detail['history']),
        );
        $this->assertSame('Update', $detail['history'][0]['messageType']);
        $this->assertNull($detail['history'][0]['supersededAt']);
        $this->assertSame('2026-01-15T06:30:00Z', $detail['history'][1]['supersededAt']);
    }

    private function importBaseline(): AlertImportResult
    {
        return $this->importer->import(new FixtureAlertProvider(FixtureAlertScenario::Baseline));
    }

    private function importLifecycle(): AlertImportResult
    {
        return $this->importer->import(new FixtureAlertProvider(FixtureAlertScenario::Lifecycle));
    }

    private function message(string $identifier): AlertMessage
    {
        return AlertMessage::query()->where('identifier', $identifier)->sole();
    }

    private function moment(string $iso = self::IN_FORCE): Carbon
    {
        return Carbon::parse($iso)->utc();
    }

    private function stamp(?Carbon $moment): ?string
    {
        return $moment?->utc()->format(AlertMessage::TIMESTAMP_FORMAT);
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return list<string>
     */
    private function identifiers(array $alerts): array
    {
        return array_map(static fn (array $alert): string => $alert['identifier'], $alerts);
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
     * A canonical warning shaped exactly like docs/03-data-contracts.md, so a
     * test states one deviation at a time.
     *
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
            'areas' => [$this->areaRow()],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function areaRow(array $overrides = []): array
    {
        return [
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
            ...$overrides,
        ];
    }
}
