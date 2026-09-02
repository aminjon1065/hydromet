<?php

namespace Tests\Unit\Alerts;

use App\Domain\Alerts\Data\AlertAreaRecord;
use App\Domain\Alerts\Data\AlertRecord;
use App\Domain\Alerts\Enums\AlertCertainty;
use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Enums\AlertUrgency;
use App\Support\Canonical\InvalidCanonicalRow;
use App\Support\Canonical\RejectionReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlertRecordTest extends TestCase
{
    #[Test]
    public function a_complete_canonical_warning_is_read_into_typed_values(): void
    {
        $record = AlertRecord::fromCanonical(self::alertRow([
            'references' => ['test-alert-0000'],
            'parameters' => ['TEST_RAINFALL_MM' => '40-60', 'TEST_WINDOW' => 'PT12H'],
        ]));

        $this->assertSame('test', $record->source);
        $this->assertSame('test-alert-0001', $record->identifier);
        $this->assertSame('test-warning-desk', $record->sender);
        $this->assertSame(AlertStatus::Actual, $record->status);
        $this->assertSame(AlertMessageType::Alert, $record->messageType);
        $this->assertSame(AlertScope::Public, $record->scope);
        $this->assertSame('TEST_HEAVY_RAIN', $record->eventCode);
        $this->assertSame(AlertSeverity::Severe, $record->severity);
        $this->assertSame(AlertUrgency::Expected, $record->urgency);
        $this->assertSame(AlertCertainty::Likely, $record->certainty);
        $this->assertSame(['Met'], $record->categories);
        $this->assertSame(['test-alert-0000'], $record->references);
        $this->assertSame(['TEST_RAINFALL_MM' => '40-60', 'TEST_WINDOW' => 'PT12H'], $record->parameters);

        $this->assertSame('2026-01-15 05:00:00', $record->sentAt->toDateTimeString());
        $this->assertSame('UTC', $record->sentAt->getTimezone()->getName());
        $this->assertSame('2026-01-15 05:00:00', $record->effectiveAt?->toDateTimeString());
        $this->assertSame('2026-01-15 09:00:00', $record->onsetAt?->toDateTimeString());
        $this->assertSame('2030-01-01 00:00:00', $record->expiresAt->toDateTimeString());
        $this->assertSame('UTC', $record->expiresAt->getTimezone()->getName());

        $this->assertSame('Огоҳии озмоишӣ', $record->headlineTj);
        $this->assertSame('Тестовое предупреждение', $record->headlineRu);
        $this->assertSame('Test warning', $record->headlineEn);
        $this->assertSame('Тавсифи озмоишӣ.', $record->descriptionTj);
        $this->assertSame('Тестовое описание.', $record->descriptionRu);
        $this->assertSame('Test description.', $record->descriptionEn);

        $this->assertCount(1, $record->areas);
        $area = $record->areas[0];
        $this->assertSame('Test region', $area->descriptionEn);
        $this->assertSame([['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']], $area->geocodes);
        $this->assertSame(self::polygon(), $area->geometry);
        $this->assertSame(
            ['west' => 68.4, 'south' => 38.3, 'east' => 69.0, 'north' => 38.8],
            $area->bbox,
        );
        $this->assertNull($area->altitudeM);
        $this->assertNull($area->ceilingM);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'source' => ['source'],
            'identifier' => ['identifier'],
            'sender' => ['sender'],
            'status' => ['status'],
            'message_type' => ['message_type'],
            'scope' => ['scope'],
            'event_code' => ['event_code'],
            'severity' => ['severity'],
            'urgency' => ['urgency'],
            'certainty' => ['certainty'],
            'category' => ['category'],
            'references' => ['references'],
            'sent_at' => ['sent_at'],
            'expires_at' => ['expires_at'],
            'headline' => ['headline'],
            'description' => ['description'],
        ];
    }

    #[Test]
    #[DataProvider('requiredFields')]
    public function a_missing_required_field_is_reported_as_a_malformed_row(string $field): void
    {
        $row = self::alertRow();
        unset($row[$field]);

        try {
            AlertRecord::fromCanonical($row);
            $this->fail("Expected '{$field}' to be required.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::MalformedRow, $exception->reason);
            $this->assertStringContainsString($field, $exception->safeDetail());
        }
    }

    #[Test]
    public function an_empty_reference_list_is_accepted_because_a_first_alert_supersedes_nothing(): void
    {
        $record = AlertRecord::fromCanonical(self::alertRow(['references' => []]));

        $this->assertSame([], $record->references);
    }

    #[Test]
    public function an_absent_references_key_is_a_mapping_fault_rather_than_an_empty_list(): void
    {
        // Keeping the two apart is what lets the import service treat "this
        // Cancel names nothing to withdraw" as a fact about the message rather
        // than as a field the adapter forgot to map.
        $row = self::alertRow();
        unset($row['references']);

        try {
            AlertRecord::fromCanonical($row);
            $this->fail('Expected an absent references key to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::MalformedRow, $exception->reason);
            $this->assertStringContainsString('references', $exception->safeDetail());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unreadableCollections(): array
    {
        return [
            'parameters as a list' => [['parameters' => ['40-60']]],
            'parameter value as a number' => [['parameters' => ['TEST_RAINFALL_MM' => 40]]],
            'category as an object' => [['category' => ['primary' => 'Met']]],
            'category holding a number' => [['category' => ['Met', 7]]],
            'reference holding a number' => [['references' => ['test-alert-0000', 7]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('unreadableCollections')]
    public function a_collection_that_is_not_the_canonical_shape_is_rejected(array $overrides): void
    {
        try {
            AlertRecord::fromCanonical(self::alertRow($overrides));
            $this->fail('Expected a collection of the wrong shape to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::InvalidFieldType, $exception->reason);
        }
    }

    #[Test]
    public function parameters_are_carried_verbatim_and_default_to_none(): void
    {
        // The portal does not interpret a provider's physical values, so an
        // absent object means "none stated", not a reading of zero.
        $row = self::alertRow();
        unset($row['parameters']);

        $this->assertSame([], AlertRecord::fromCanonical($row)->parameters);
    }

    #[Test]
    public function a_warning_without_an_instruction_leaves_all_three_translations_null(): void
    {
        $absent = self::alertRow();
        unset($absent['instruction']);

        foreach ([$absent, self::alertRow(['instruction' => null])] as $row) {
            $record = AlertRecord::fromCanonical($row);

            $this->assertNull($record->instructionTj);
            $this->assertNull($record->instructionRu);
            $this->assertNull($record->instructionEn);
        }
    }

    #[Test]
    public function a_supplied_instruction_is_read_in_all_three_application_locales(): void
    {
        $record = AlertRecord::fromCanonical(self::alertRow());

        $this->assertSame('Дастури озмоишӣ.', $record->instructionTj);
        $this->assertSame('Тестовая инструкция.', $record->instructionRu);
        $this->assertSame('Test instruction.', $record->instructionEn);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function incompleteInstructions(): array
    {
        return [
            'only tajik' => [['tj' => 'Дастури озмоишӣ.'], 'ru'],
            'only russian' => [['ru' => 'Тестовая инструкция.'], 'tj'],
            'only english' => [['en' => 'Test instruction.'], 'tj'],
            'english omitted' => [['tj' => 'Дастури озмоишӣ.', 'ru' => 'Тестовая инструкция.'], 'en'],
            'english blank' => [['tj' => 'Дастури озмоишӣ.', 'ru' => 'Тестовая инструкция.', 'en' => '   '], 'en'],
        ];
    }

    /**
     * @param  array<string, string>  $instruction
     */
    #[Test]
    #[DataProvider('incompleteInstructions')]
    public function an_instruction_in_only_some_languages_is_rejected(array $instruction, string $missing): void
    {
        // Safety advice is the part of a warning a reader acts on. No approved
        // rule says which language may stand in for another, so a half-supplied
        // instruction is refused rather than rendered with a silent fallback.
        try {
            AlertRecord::fromCanonical(self::alertRow(['instruction' => $instruction]));
            $this->fail("Expected an instruction missing '{$missing}' to be rejected.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::MalformedRow, $exception->reason);
            $this->assertStringContainsString("'{$missing}'", $exception->safeDetail());
        }
    }

    /**
     * @return array<string, array{string, RejectionReason}>
     */
    public static function untrustworthySendTimes(): array
    {
        return [
            'no timezone' => ['2026-01-15T05:00:00', RejectionReason::InvalidFieldType],
            'natural language' => ['tomorrow', RejectionReason::InvalidFieldType],
            'seven fractional digits' => [
                '2026-01-15T05:00:00.1234567Z',
                RejectionReason::UnsupportedTimestampPrecision,
            ],
        ];
    }

    #[Test]
    #[DataProvider('untrustworthySendTimes')]
    public function a_send_time_the_portal_cannot_trust_is_rejected(
        string $supplied,
        RejectionReason $expected,
    ): void {
        try {
            AlertRecord::fromCanonical(self::alertRow(['sent_at' => $supplied]));
            $this->fail("Expected '{$supplied}' to be rejected as sent_at.");
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame($expected, $exception->reason);
            $this->assertStringContainsString('sent_at', $exception->safeDetail());
        }
    }

    #[Test]
    public function a_send_time_with_an_explicit_offset_is_normalized_to_utc(): void
    {
        $record = AlertRecord::fromCanonical(self::alertRow([
            'sent_at' => '2026-01-15T10:00:00+05:00',
            'effective_at' => '2026-01-15T10:00:00+05:00',
        ]));

        $this->assertSame('2026-01-15 05:00:00', $record->sentAt->toDateTimeString());
        $this->assertSame('UTC', $record->sentAt->getTimezone()->getName());
        $this->assertSame('2026-01-15 05:00:00', $record->effectiveAt?->toDateTimeString());
    }

    #[Test]
    public function a_warning_naming_no_affected_area_is_rejected(): void
    {
        // Nothing could be drawn or listed for it, so the message would appear
        // in the portal as a warning about nowhere.
        try {
            AlertRecord::fromCanonical(self::alertRow(['areas' => []]));
            $this->fail('Expected a warning with no affected area to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::MissingAffectedArea, $exception->reason);
        }
    }

    #[Test]
    public function an_areas_field_that_is_not_a_list_of_areas_is_rejected(): void
    {
        $absent = self::alertRow();
        unset($absent['areas']);

        foreach ([$absent, self::alertRow(['areas' => ['first' => self::areaRow()]])] as $row) {
            try {
                AlertRecord::fromCanonical($row);
                $this->fail('Expected an unreadable areas field to be rejected.');
            } catch (InvalidCanonicalRow $exception) {
                $this->assertSame(RejectionReason::InvalidFieldType, $exception->reason);
                $this->assertStringContainsString('areas', $exception->safeDetail());
            }
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, array{west: float, south: float, east: float, north: float}}>
     */
    public static function drawableGeometries(): array
    {
        return [
            'polygon' => [
                self::polygon(),
                ['west' => 68.4, 'south' => 38.3, 'east' => 69.0, 'north' => 38.8],
            ],
            'multi polygon' => [
                self::multiPolygon(),
                ['west' => 71.2, 'south' => 37.7, 'east' => 72.4, 'north' => 38.5],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @param  array{west: float, south: float, east: float, north: float}  $extent
     */
    #[Test]
    #[DataProvider('drawableGeometries')]
    public function a_drawable_geometry_is_kept_intact_and_reduced_to_its_extent(
        array $geometry,
        array $extent,
    ): void {
        $record = AlertRecord::fromCanonical(self::alertRow([
            'areas' => [self::areaRow(['geometry' => $geometry])],
        ]));

        $this->assertSame($geometry, $record->areas[0]->geometry);
        $this->assertSame($extent, $record->areas[0]->bbox);
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @param  array{west: float, south: float, east: float, north: float}  $extent
     */
    #[Test]
    #[DataProvider('drawableGeometries')]
    public function the_bounding_box_spans_every_position_of_the_geometry(
        array $geometry,
        array $extent,
    ): void {
        // The bbox is the portable map filter: a value too small would hide a
        // warning from the very viewport it covers.
        $this->assertSame($extent, AlertAreaRecord::boundingBox($geometry));
    }

    /**
     * @return array<string, array{mixed, RejectionReason}>
     */
    public static function undrawableGeometries(): array
    {
        return [
            'line string' => [
                ['type' => 'LineString', 'coordinates' => [[68.4, 38.3], [69.0, 38.8]]],
                RejectionReason::UnsupportedGeometry,
            ],
            'point' => [
                ['type' => 'Point', 'coordinates' => [68.4, 38.3]],
                RejectionReason::UnsupportedGeometry,
            ],
            'no type' => [
                ['coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.3]]]],
                RejectionReason::UnsupportedGeometry,
            ],
            'geometry as a list' => [
                [['type' => 'Polygon']],
                RejectionReason::InvalidFieldType,
            ],
            'no coordinates' => [
                ['type' => 'Polygon', 'coordinates' => []],
                RejectionReason::InvalidFieldType,
            ],
            'ring of three positions' => [
                ['type' => 'Polygon', 'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [68.4, 38.3]]]],
                RejectionReason::InvalidFieldType,
            ],
            'unclosed ring' => [
                ['type' => 'Polygon', 'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8]]]],
                RejectionReason::InvalidFieldType,
            ],
            'unclosed ring inside a multi polygon' => [
                [
                    'type' => 'MultiPolygon',
                    'coordinates' => [[[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8]]]],
                ],
                RejectionReason::InvalidFieldType,
            ],
            'longitude as a string' => [
                ['type' => 'Polygon', 'coordinates' => [[['68.4', 38.3], [69.0, 38.3], [69.0, 38.8], ['68.4', 38.3]]]],
                RejectionReason::InvalidFieldType,
            ],
            'position without a latitude' => [
                ['type' => 'Polygon', 'coordinates' => [[[68.4], [69.0, 38.3], [69.0, 38.8], [68.4]]]],
                RejectionReason::InvalidFieldType,
            ],
            'longitude beyond the antimeridian' => [
                ['type' => 'Polygon', 'coordinates' => [[[190.0, 38.3], [69.0, 38.3], [69.0, 38.8], [190.0, 38.3]]]],
                RejectionReason::LongitudeOutOfRange,
            ],
            'latitude beyond the pole' => [
                ['type' => 'Polygon', 'coordinates' => [[[68.4, 95.0], [69.0, 38.3], [69.0, 38.8], [68.4, 95.0]]]],
                RejectionReason::LatitudeOutOfRange,
            ],
        ];
    }

    #[Test]
    #[DataProvider('undrawableGeometries')]
    public function a_geometry_the_portal_cannot_draw_is_rejected(
        mixed $geometry,
        RejectionReason $expected,
    ): void {
        // A warning polygon goes straight onto a public map, and the adapter
        // that supplied it is untrusted input.
        try {
            AlertRecord::fromCanonical(self::alertRow([
                'areas' => [self::areaRow(['geometry' => $geometry])],
            ]));
            $this->fail('Expected an undrawable geometry to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame($expected, $exception->reason);
        }
    }

    #[Test]
    public function an_area_identified_only_by_geocode_is_accepted_without_a_bounding_box(): void
    {
        // Hydromet has not supplied the administrative boundary dataset, so a
        // named district with no polygon is an ordinary case, not a fault.
        $withoutGeometryKey = self::areaRow();
        unset($withoutGeometryKey['geometry']);

        foreach ([self::areaRow(['geometry' => null]), $withoutGeometryKey] as $area) {
            $record = AlertRecord::fromCanonical(self::alertRow(['areas' => [$area]]));

            $this->assertNull($record->areas[0]->geometry);
            $this->assertNull($record->areas[0]->bbox);
            $this->assertSame('Test region', $record->areas[0]->descriptionEn);
        }
    }

    #[Test]
    public function a_hostile_geometry_type_is_truncated_before_it_reaches_an_operator(): void
    {
        // The type is provider text quoted back in a rejection an operator
        // reads and a log stores; echoing it whole would let the provider
        // decide how much of both it fills.
        $type = str_repeat('A', 40).'-tail-that-must-not-be-echoed';

        try {
            AlertRecord::fromCanonical(self::alertRow([
                'areas' => [self::areaRow(['geometry' => ['type' => $type, 'coordinates' => [[68.4, 38.3]]]])],
            ]));
            $this->fail('Expected an unsupported geometry type to be rejected.');
        } catch (InvalidCanonicalRow $exception) {
            $this->assertSame(RejectionReason::UnsupportedGeometry, $exception->reason);
            $this->assertStringContainsString(str_repeat('A', 40), $exception->safeDetail());
            $this->assertStringNotContainsString('tail-that-must-not-be-echoed', $exception->safeDetail());
        }
    }

    #[Test]
    public function the_row_reference_names_the_source_and_the_provider_identifier(): void
    {
        $record = AlertRecord::fromCanonical(self::alertRow([
            'source' => 'test-source',
            'identifier' => 'TEST-2026-0001',
        ]));

        $this->assertSame('test-source:TEST-2026-0001', $record->identity());
    }

    /**
     * A complete canonical warning, shaped like docs/03-data-contracts.md
     * section 7 and the baseline fixture feed. Every value is invented and
     * exists only so a test can state one deviation at a time.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function alertRow(array $overrides = []): array
    {
        return [
            'source' => 'test',
            'identifier' => 'test-alert-0001',
            'sender' => 'test-warning-desk',
            'status' => 'Actual',
            'message_type' => 'Alert',
            'scope' => 'Public',
            'event_code' => 'TEST_HEAVY_RAIN',
            'severity' => 'Severe',
            'urgency' => 'Expected',
            'certainty' => 'Likely',
            'category' => ['Met'],
            'references' => [],
            'parameters' => ['TEST_RAINFALL_MM' => '40-60'],
            'sent_at' => '2026-01-15T05:00:00Z',
            'effective_at' => '2026-01-15T05:00:00Z',
            'onset_at' => '2026-01-15T09:00:00Z',
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
            'instruction' => [
                'tj' => 'Дастури озмоишӣ.',
                'ru' => 'Тестовая инструкция.',
                'en' => 'Test instruction.',
            ],
            'areas' => [self::areaRow()],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function areaRow(array $overrides = []): array
    {
        return [
            'description' => [
                'tj' => 'Минтақаи озмоишӣ',
                'ru' => 'Тестовый регион',
                'en' => 'Test region',
            ],
            'geocodes' => [['name' => 'TEST_REGION', 'value' => 'TEST-REGION-A']],
            'geometry' => self::polygon(),
            'altitude_m' => null,
            'ceiling_m' => null,
            ...$overrides,
        ];
    }

    /**
     * An invented rectangle spanning 68.4..69.0 E and 38.3..38.8 N.
     *
     * @return array<string, mixed>
     */
    private static function polygon(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[[68.4, 38.3], [69.0, 38.3], [69.0, 38.8], [68.4, 38.8], [68.4, 38.3]]],
        ];
    }

    /**
     * Two disjoint invented rectangles, so the extent of the whole geometry is
     * wider than either part.
     *
     * @return array<string, mixed>
     */
    private static function multiPolygon(): array
    {
        return [
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[71.2, 37.7], [71.8, 37.7], [71.8, 38.1], [71.2, 38.1], [71.2, 37.7]]],
                [[[72.0, 38.2], [72.4, 38.2], [72.4, 38.5], [72.0, 38.5], [72.0, 38.2]]],
            ],
        ];
    }
}
