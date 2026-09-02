<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Enums\AlertCertainty;
use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Enums\AlertUrgency;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Support\Locale\SupportedLocale;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema-level guarantees for warnings.
 *
 * Uniqueness, casts, cascades and the lifecycle predicates run on every driver.
 * The CHECK-constraint tests are PostgreSQL-only, because SQLite cannot add
 * table constraints after creation; on SQLite the same rules are enforced by
 * the import service and covered by the alert import tests.
 *
 * Every lifecycle assertion passes an explicit moment. A warning is "active"
 * relative to an instant, so a test that let the wall clock supply that instant
 * would change meaning as the suite ages.
 */
class AlertSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Raw inserts need their own identifier per call: several of these tests
     * write more than one row, and colliding on the source/identifier key would
     * mask the constraint actually under test.
     */
    private int $rawSequence = 0;

    #[Test]
    public function every_expected_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumns('alert_messages', [
            'source', 'identifier', 'sender', 'status', 'message_type', 'scope', 'event_code',
            'severity', 'urgency', 'certainty', 'categories', 'references', 'parameters',
            'sent_at', 'effective_at', 'onset_at', 'expires_at',
            'headline_tj', 'headline_ru', 'headline_en',
            'description_tj', 'description_ru', 'description_en',
            'instruction_tj', 'instruction_ru', 'instruction_en',
            'superseded_by_id', 'superseded_at', 'raw_payload', 'imported_at',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('alert_areas', [
            'alert_message_id', 'description_tj', 'description_ru', 'description_en',
            'geocodes', 'geometry', 'bbox_west', 'bbox_south', 'bbox_east', 'bbox_north',
            'altitude_m', 'ceiling_m', 'created_at', 'updated_at',
        ]));
    }

    #[Test]
    public function the_message_casts_return_the_declared_types(): void
    {
        $message = AlertMessage::factory()->create([
            'categories' => ['Met', 'Geo'],
            'references' => ['test-alert-parent'],
            'parameters' => ['wind_gust_ms' => '25'],
            'sent_at' => Carbon::parse('2026-01-15T05:00:00Z'),
            'onset_at' => Carbon::parse('2026-01-15T09:00:00Z'),
        ]);

        $message->refresh();

        $this->assertSame(AlertStatus::Actual, $message->status);
        $this->assertSame(AlertMessageType::Alert, $message->message_type);
        $this->assertSame(AlertScope::Public, $message->scope);
        $this->assertSame(AlertSeverity::Moderate, $message->severity);
        $this->assertSame(AlertUrgency::Expected, $message->urgency);
        $this->assertSame(AlertCertainty::Likely, $message->certainty);
        $this->assertSame(['Met', 'Geo'], $message->categories);
        $this->assertSame(['test-alert-parent'], $message->references);
        $this->assertSame(['wind_gust_ms' => '25'], $message->parameters);
        $this->assertInstanceOf(Carbon::class, $message->sent_at);
        $this->assertSame('2026-01-15 05:00:00', $message->sent_at->utc()->toDateTimeString());
        $this->assertInstanceOf(Carbon::class, $message->effective_at);
        $this->assertInstanceOf(Carbon::class, $message->onset_at);
        $this->assertSame('2026-01-15 09:00:00', $message->onset_at->utc()->toDateTimeString());
        $this->assertInstanceOf(Carbon::class, $message->expires_at);
        $this->assertInstanceOf(Carbon::class, $message->imported_at);
        $this->assertNull($message->superseded_at);
        // The column is reserved by the data contract, but no sanitization rule
        // exists yet, so nothing may write an upstream document into it.
        $this->assertNull($message->raw_payload);
    }

    #[Test]
    public function the_area_casts_return_the_declared_types(): void
    {
        $area = AlertArea::factory()->create([
            'altitude_m' => 800,
            'ceiling_m' => 2400,
        ]);

        $area->refresh();

        $this->assertIsArray($area->geometry);
        $this->assertSame('Polygon', $area->geometry['type']);
        // Vertex values are not asserted: a JSON round-trip returns a whole
        // coordinate as an int, and the extent this test cares about is the
        // decimal bbox below.
        $this->assertCount(5, $area->geometry['coordinates'][0]);
        $this->assertSame([['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']], $area->geocodes);
        // Decimal casts hand back strings, which is what keeps a re-imported
        // unchanged extent from looking like a change.
        $this->assertSame('68.400000', $area->bbox_west);
        $this->assertSame('38.300000', $area->bbox_south);
        $this->assertSame('69.000000', $area->bbox_east);
        $this->assertSame('38.800000', $area->bbox_north);
        $this->assertSame('800.00', $area->altitude_m);
        $this->assertSame('2400.00', $area->ceiling_m);
    }

    #[Test]
    public function an_identifier_is_unique_within_its_source(): void
    {
        AlertMessage::factory()->create(['source' => 'test', 'identifier' => 'TJ-ALERT-1']);

        $this->expectException(UniqueConstraintViolationException::class);

        AlertMessage::factory()->create(['source' => 'test', 'identifier' => 'TJ-ALERT-1']);
    }

    #[Test]
    public function the_same_identifier_may_be_reused_by_another_source(): void
    {
        // A CAP identifier is unique within its sender only, so two feeds may
        // legitimately number their messages the same way.
        AlertMessage::factory()->create(['source' => 'test', 'identifier' => 'TJ-ALERT-1']);
        AlertMessage::factory()->create(['source' => 'fixture', 'identifier' => 'TJ-ALERT-1']);

        $this->assertSame(2, AlertMessage::query()->count());
    }

    #[Test]
    public function an_actual_public_warning_is_active_inside_its_window(): void
    {
        $message = AlertMessage::factory()->create();

        $moment = Carbon::parse('2026-06-01T00:00:00Z');

        $this->assertTrue($message->isActiveAt($moment));
        $this->assertFalse($message->isExpiredAt($moment));
        $this->assertFalse($message->isSuperseded());
    }

    #[Test]
    public function a_warning_is_not_active_before_it_takes_effect(): void
    {
        $message = AlertMessage::factory()->create([
            'effective_at' => Carbon::parse('2026-01-20T00:00:00Z'),
        ]);

        $moment = Carbon::parse('2026-01-16T00:00:00Z');

        $this->assertFalse($message->isActiveAt($moment));
        $this->assertFalse($message->isExpiredAt($moment));
    }

    #[Test]
    public function an_expired_warning_is_not_active(): void
    {
        $message = AlertMessage::factory()->expired()->create();

        $moment = Carbon::parse('2026-06-01T00:00:00Z');

        $this->assertTrue($message->isExpiredAt($moment));
        $this->assertFalse($message->isActiveAt($moment));
    }

    #[Test]
    public function a_superseded_warning_is_not_active(): void
    {
        $original = AlertMessage::factory()->create(['identifier' => 'TJ-ALERT-1']);
        $replacement = AlertMessage::factory()->update('TJ-ALERT-1')->create();

        $original->update([
            'superseded_by_id' => $replacement->id,
            'superseded_at' => Carbon::parse('2026-02-01T00:00:00Z'),
        ]);

        $moment = Carbon::parse('2026-06-01T00:00:00Z');

        $this->assertTrue($original->isSuperseded());
        $this->assertFalse($original->isActiveAt($moment));
        // The replacement is what the public sees; the predecessor stays only
        // so the published history can be reconstructed.
        $this->assertTrue($replacement->isActiveAt($moment));
    }

    #[Test]
    public function a_non_actual_status_is_never_active(): void
    {
        $message = AlertMessage::factory()->testStatus()->create();

        $moment = Carbon::parse('2026-06-01T00:00:00Z');

        $this->assertFalse($message->isActiveAt($moment));
        // Still inside its window and not withdrawn: only the status keeps it
        // off the portal, which is the distinction this guards.
        $this->assertFalse($message->isExpiredAt($moment));
        $this->assertFalse($message->isSuperseded());
    }

    #[Test]
    public function a_restricted_warning_is_never_active(): void
    {
        $message = AlertMessage::factory()->restricted()->create();

        $moment = Carbon::parse('2026-06-01T00:00:00Z');

        $this->assertFalse($message->isActiveAt($moment));
        $this->assertFalse($message->isExpiredAt($moment));
    }

    #[Test]
    public function a_cancellation_is_never_active(): void
    {
        // A Cancel withdraws its references and is not itself a warning:
        // displaying it would put a card on the map where the warning was.
        $message = AlertMessage::factory()->cancellation('TJ-ALERT-1')->create();

        $moment = Carbon::parse('2026-06-01T00:00:00Z');

        $this->assertFalse($message->isActiveAt($moment));
        $this->assertFalse($message->isExpiredAt($moment));
    }

    #[Test]
    public function each_locale_reads_its_own_translation(): void
    {
        $message = AlertMessage::factory()->create([
            'headline_tj' => 'headline-tj',
            'headline_ru' => 'headline-ru',
            'headline_en' => 'headline-en',
            'description_tj' => 'description-tj',
            'description_ru' => 'description-ru',
            'description_en' => 'description-en',
            'instruction_tj' => 'instruction-tj',
            'instruction_ru' => 'instruction-ru',
            'instruction_en' => 'instruction-en',
        ]);

        foreach (SupportedLocale::cases() as $locale) {
            $this->assertSame('headline-'.$locale->value, $message->localizedHeadline($locale));
            $this->assertSame('description-'.$locale->value, $message->localizedDescription($locale));
            $this->assertSame('instruction-'.$locale->value, $message->localizedInstruction($locale));
        }
    }

    #[Test]
    public function a_missing_instruction_stays_null_in_every_locale(): void
    {
        // No approved rule says which language may stand in for another, so an
        // absent instruction must read as absent rather than borrow a
        // neighbouring column.
        $message = AlertMessage::factory()->create();

        foreach (SupportedLocale::cases() as $locale) {
            $this->assertNull($message->localizedInstruction($locale));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function controlledVocabularyColumns(): array
    {
        return [
            'status' => ['status', 'Rehearsal'],
            'message type' => ['message_type', 'Withdraw'],
            'scope' => ['scope', 'Internal'],
            'severity' => ['severity', 'Catastrophic'],
            'urgency' => ['urgency', 'Soon'],
            'certainty' => ['certainty', 'Probable'],
        ];
    }

    #[Test]
    #[DataProvider('controlledVocabularyColumns')]
    public function postgresql_rejects_a_value_outside_a_controlled_vocabulary(string $column, string $value): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([$column => $value]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function identityColumns(): array
    {
        return [
            'source' => ['source'],
            'identifier' => ['identifier'],
            'sender' => ['sender'],
            'event code' => ['event_code'],
        ];
    }

    #[Test]
    #[DataProvider('identityColumns')]
    public function postgresql_rejects_a_blank_identity_column(string $column): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([$column => '   ']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredTranslationColumns(): array
    {
        return [
            'tajik headline' => ['headline_tj'],
            'russian headline' => ['headline_ru'],
            'english headline' => ['headline_en'],
            'tajik description' => ['description_tj'],
            'russian description' => ['description_ru'],
            'english description' => ['description_en'],
        ];
    }

    #[Test]
    #[DataProvider('requiredTranslationColumns')]
    public function postgresql_rejects_a_missing_required_translation(string $column): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([$column => '   ']));
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function partialInstructionSets(): array
    {
        return [
            'tajik only' => [['instruction_tj' => 'instruction-tj']],
            'russian only' => [['instruction_ru' => 'instruction-ru']],
            'english only' => [['instruction_en' => 'instruction-en']],
            'tajik and russian' => [['instruction_tj' => 'instruction-tj', 'instruction_ru' => 'instruction-ru']],
            'russian and english' => [['instruction_ru' => 'instruction-ru', 'instruction_en' => 'instruction-en']],
        ];
    }

    /**
     * @param  array<string, string>  $instruction
     */
    #[Test]
    #[DataProvider('partialInstructionSets')]
    public function postgresql_rejects_an_instruction_supplied_in_only_some_languages(array $instruction): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert($instruction));
    }

    #[Test]
    public function postgresql_accepts_an_instruction_given_in_all_three_languages_or_in_none(): void
    {
        $this->requirePostgres();

        DB::table('alert_messages')->insert($this->rawAlert([
            'instruction_tj' => 'instruction-tj',
            'instruction_ru' => 'instruction-ru',
            'instruction_en' => 'instruction-en',
        ]));

        DB::table('alert_messages')->insert($this->rawAlert());

        $this->assertSame(2, AlertMessage::query()->count());
    }

    #[Test]
    public function postgresql_rejects_a_message_that_expires_before_it_was_sent(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([
            'sent_at' => '2026-01-15 05:00:00',
            'expires_at' => '2026-01-15 05:00:00',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_message_effective_before_it_was_sent(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([
            'sent_at' => '2026-01-15 05:00:00',
            'effective_at' => '2026-01-15 04:00:00',
        ]));
    }

    #[Test]
    public function postgresql_rejects_an_onset_before_the_message_takes_effect(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([
            'effective_at' => '2026-01-15 06:00:00',
            'onset_at' => '2026-01-15 05:30:00',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_supersession_without_its_timestamp(): void
    {
        $this->requirePostgres();

        $replacement = AlertMessage::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([
            'superseded_by_id' => $replacement->id,
            'superseded_at' => null,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_supersession_timestamp_without_its_successor(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([
            'superseded_by_id' => null,
            'superseded_at' => '2026-01-16 05:00:00',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_message_that_supersedes_itself(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        // An explicit id is the only way to express the self-reference the
        // constraint exists to refuse.
        DB::table('alert_messages')->insert($this->rawAlert([
            'id' => 900001,
            'superseded_by_id' => 900001,
            'superseded_at' => '2026-01-16 05:00:00',
        ]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedMessageJson(): array
    {
        return [
            'categories as an object' => ['categories', '{"kind": "Met"}'],
            'references as an object' => ['references', '{"ref": "TJ-ALERT-1"}'],
            'parameters as a list' => ['parameters', '["wind_gust_ms"]'],
            'raw payload as a list' => ['raw_payload', '["upstream"]'],
        ];
    }

    #[Test]
    #[DataProvider('malformedMessageJson')]
    public function postgresql_rejects_a_json_column_of_the_wrong_shape(string $column, string $value): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_messages')->insert($this->rawAlert([$column => $value]));
    }

    #[Test]
    public function postgresql_accepts_a_message_without_a_raw_payload(): void
    {
        $this->requirePostgres();

        DB::table('alert_messages')->insert($this->rawAlert(['raw_payload' => null]));

        $this->assertSame(1, AlertMessage::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function undrawableGeometryTypes(): array
    {
        return [
            'point' => ['{"type": "Point", "coordinates": [68.8, 38.5]}'],
            'line string' => ['{"type": "LineString", "coordinates": [[68.4, 38.3], [69.0, 38.8]]}'],
            'geometry collection' => ['{"type": "GeometryCollection", "geometries": []}'],
        ];
    }

    #[Test]
    #[DataProvider('undrawableGeometryTypes')]
    public function postgresql_rejects_an_area_geometry_that_cannot_be_drawn(string $geometry): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea(['geometry' => $geometry]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function areaDescriptionColumns(): array
    {
        return [
            'tajik' => ['description_tj'],
            'russian' => ['description_ru'],
            'english' => ['description_en'],
        ];
    }

    #[Test]
    #[DataProvider('areaDescriptionColumns')]
    public function postgresql_rejects_a_blank_area_description(string $column): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea([$column => '   ']));
    }

    #[Test]
    public function postgresql_rejects_geocodes_that_are_not_a_json_array(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea([
            'geocodes' => '{"name": "TEST_REGION", "value": "TEST-REGION-A"}',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_geometry_without_a_bounding_box(): void
    {
        $this->requirePostgres();

        // The bbox is the only extent filter that reads identically on both
        // drivers, so a drawable area without one is unfilterable.
        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea([
            'bbox_west' => null,
            'bbox_south' => null,
            'bbox_east' => null,
            'bbox_north' => null,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_bounding_box_without_a_geometry(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea(['geometry' => null]));
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function boundingBoxValuesOutsideWgs84(): array
    {
        return [
            'west past the antimeridian' => ['bbox_west', -190.5],
            'east past the antimeridian' => ['bbox_east', 190.5],
            'south past the pole' => ['bbox_south', -95.5],
            'north past the pole' => ['bbox_north', 95.5],
        ];
    }

    #[Test]
    #[DataProvider('boundingBoxValuesOutsideWgs84')]
    public function postgresql_rejects_a_bounding_box_outside_wgs84(string $column, float $value): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea([$column => $value]));
    }

    #[Test]
    public function postgresql_rejects_a_bounding_box_whose_west_is_past_its_east(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea([
            'bbox_west' => 69.0,
            'bbox_east' => 68.4,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_bounding_box_whose_south_is_past_its_north(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea([
            'bbox_south' => 38.8,
            'bbox_north' => 38.3,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_ceiling_below_its_altitude(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('alert_areas')->insert($this->rawArea([
            'altitude_m' => 2400.0,
            'ceiling_m' => 800.0,
        ]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function messageInstantColumns(): array
    {
        return [
            'sent at' => ['sent_at'],
            'effective at' => ['effective_at'],
            'onset at' => ['onset_at'],
            'expires at' => ['expires_at'],
            'superseded at' => ['superseded_at'],
            'imported at' => ['imported_at'],
        ];
    }

    #[Test]
    #[DataProvider('messageInstantColumns')]
    public function postgresql_stores_every_message_instant_with_a_time_zone(string $column): void
    {
        $this->requirePostgres();

        $type = DB::selectOne(<<<'SQL'
            SELECT data_type
            FROM information_schema.columns
            WHERE table_name = 'alert_messages' AND column_name = ?
        SQL, [$column]);

        $this->assertNotNull($type);
        $this->assertSame('timestamp with time zone', $type->data_type);
    }

    #[Test]
    public function postgresql_stores_a_message_instant_with_microsecond_precision(): void
    {
        $this->requirePostgres();

        // Two messages of one chain can be sent inside the same second, and the
        // sub-second order decides which one the public sees.
        $message = AlertMessage::factory()->create([
            'sent_at' => Carbon::parse('2026-01-15T05:00:00.123456Z'),
            // Kept at or after sent_at, which the validity-order constraint
            // requires once sent_at carries a sub-second part.
            'effective_at' => Carbon::parse('2026-01-15T05:00:00.234567Z'),
            'expires_at' => Carbon::parse('2030-01-01T00:00:00.654321Z'),
        ]);

        $stored = DB::selectOne(<<<'SQL'
            SELECT to_char(sent_at      AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS sent,
                   to_char(effective_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS effective,
                   to_char(expires_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS expires
            FROM alert_messages WHERE id = ?
        SQL, [$message->id]);

        $this->assertNotNull($stored);
        $this->assertSame('2026-01-15 05:00:00.123456', $stored->sent);
        $this->assertSame('2026-01-15 05:00:00.234567', $stored->effective);
        $this->assertSame('2030-01-01 00:00:00.654321', $stored->expires);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Warning CHECK constraints and timestamptz storage are verified on PostgreSQL only.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawAlert(array $overrides = []): array
    {
        $this->rawSequence++;

        return [
            'source' => 'test',
            'identifier' => 'raw-alert-'.$this->rawSequence,
            'sender' => 'test-warning-desk',
            'status' => 'Actual',
            'message_type' => 'Alert',
            'scope' => 'Public',
            'event_code' => 'TEST_EVENT',
            'severity' => 'Moderate',
            'urgency' => 'Expected',
            'certainty' => 'Likely',
            'categories' => '["Met"]',
            'references' => '[]',
            'parameters' => '{}',
            'sent_at' => '2026-01-15 05:00:00',
            'effective_at' => '2026-01-15 05:00:00',
            'onset_at' => null,
            'expires_at' => '2026-01-16 05:00:00',
            'headline_tj' => 'headline-tj',
            'headline_ru' => 'headline-ru',
            'headline_en' => 'headline-en',
            'description_tj' => 'description-tj',
            'description_ru' => 'description-ru',
            'description_en' => 'description-en',
            'instruction_tj' => null,
            'instruction_ru' => null,
            'instruction_en' => null,
            'superseded_by_id' => null,
            'superseded_at' => null,
            'raw_payload' => null,
            'imported_at' => '2026-01-15 05:05:00',
            'created_at' => '2026-01-15 05:05:00',
            'updated_at' => '2026-01-15 05:05:00',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawArea(array $overrides = []): array
    {
        return [
            // Only created when the caller did not supply one, so a test that
            // reuses a message does not silently write a second warning.
            'alert_message_id' => $overrides['alert_message_id'] ?? AlertMessage::factory()->create()->id,
            'description_tj' => 'description-tj',
            'description_ru' => 'description-ru',
            'description_en' => 'description-en',
            'geocodes' => '[]',
            'geometry' => '{"type": "Polygon", "coordinates": [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8], [68.4, 38.3]]]}',
            'bbox_west' => 68.4,
            'bbox_south' => 38.3,
            'bbox_east' => 69.0,
            'bbox_north' => 38.8,
            'altitude_m' => null,
            'ceiling_m' => null,
            'created_at' => '2026-01-15 05:05:00',
            'updated_at' => '2026-01-15 05:05:00',
            ...$overrides,
        ];
    }
}
