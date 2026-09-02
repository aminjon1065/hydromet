<?php

namespace Tests\Feature\Api;

use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Fixtures\FixtureAlertProvider;
use App\Domain\Integrations\Fixtures\FixtureAlertScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlertApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_alert_list_publishes_only_warnings_in_force_with_the_documented_envelope(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');
        // The lifecycle feed updates fixture-alert-0001 and cancels
        // fixture-alert-0002, so the whole publication rule is exercised at
        // once: superseded, cancelled, expired and Test-status messages are all
        // stored and none of them may be published.
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline, FixtureAlertScenario::Lifecycle);

        $response = $this->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertHeader('Vary', 'Accept-Language')
            ->assertHeader('X-Request-Id')
            ->assertJsonStructure(['data', 'meta' => ['generated_at', 'active_at', 'severity_order']])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identifier', 'fixture-alert-0001-update-1')
            ->assertJsonPath('meta.generated_at', '2026-09-02T08:00:00.000000Z')
            ->assertJsonPath('meta.active_at', '2026-09-02T08:00:00.000000Z')
            // The ranking is published; a colour scale is not, because Hydromet
            // has approved none.
            ->assertJsonPath('meta.severity_order', ['Extreme', 'Severe', 'Moderate', 'Minor', 'Unknown']);

        // The superseded original, the cancelled warning, the cancellation
        // itself, the expired message and the Test-status message.
        $response
            ->assertJsonMissing(['fixture-alert-0001'])
            ->assertJsonMissing(['fixture-alert-0002'])
            ->assertJsonMissing(['fixture-alert-0002-cancel-1'])
            ->assertJsonMissing(['fixture-alert-0003'])
            ->assertJsonMissing(['fixture-alert-0004']);

        // Storage is unaffected by publication: the history a client may still
        // ask about must survive in the table.
        $this->assertSame(6, AlertMessage::query()->count());
    }

    #[Test]
    public function every_published_warning_is_snake_case_and_carries_the_contract_fields(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);

        $rain = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/alerts?event_code=FIXTURE_HEAVY_RAIN')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        // Asserting the key list rather than a structure subset is what proves
        // no camelCase presenter key (isMock, eventCode, sentAt) reached the
        // wire, which the internal query object still uses.
        $this->assertSame([
            'identifier',
            'source',
            'is_mock',
            'event_code',
            'severity',
            'urgency',
            'certainty',
            'sender',
            'headline',
            'description',
            'instruction',
            'sent_at',
            'effective_at',
            'onset_at',
            'expires_at',
            'areas',
        ], array_keys($rain));
        $this->assertSame(['description', 'geocodes', 'geometry'], array_keys($rain['areas'][0]));

        $this->assertSame('fixture-alert-0001', $rain['identifier']);
        $this->assertSame('fixture', $rain['source']);
        $this->assertTrue($rain['is_mock']);
        $this->assertSame('FIXTURE_HEAVY_RAIN', $rain['event_code']);
        $this->assertSame('Severe', $rain['severity']);
        $this->assertSame('Expected', $rain['urgency']);
        $this->assertSame('Likely', $rain['certainty']);
        $this->assertSame('fixture-warning-desk (synthetic sender, not Hydromet)', $rain['sender']);
        $this->assertSame('Fixture warning: heavy rain (fixture)', $rain['headline']);
        $this->assertSame(
            'Demonstration data used to exercise the portal. This is not a real warning.',
            $rain['description'],
        );
        $this->assertSame('This is demonstration text, not an official recommendation.', $rain['instruction']);
        $this->assertSame('2026-01-15T05:00:00Z', $rain['sent_at']);
        $this->assertSame('2026-01-15T05:00:00Z', $rain['effective_at']);
        $this->assertSame('2026-01-15T09:00:00Z', $rain['onset_at']);
        $this->assertSame('2030-01-01T00:00:00Z', $rain['expires_at']);

        $this->assertSame('Fixture region A (fixture)', $rain['areas'][0]['description']);
        $this->assertSame(
            [['name' => 'FIXTURE_REGION', 'value' => 'FIXTURE-REGION-A']],
            $rain['areas'][0]['geocodes'],
        );
        $this->assertSame('Polygon', $rain['areas'][0]['geometry']['type']);
        $this->assertCount(5, $rain['areas'][0]['geometry']['coordinates'][0]);

        $wind = $this->getJson('/api/v1/alerts?event_code=FIXTURE_STRONG_WIND')
            ->assertOk()
            ->json('data.0');

        // An absent instruction is null, never an empty string, and a warning
        // may carry more than one affected area.
        $this->assertNull($wind['instruction']);
        $this->assertNull($wind['onset_at']);
        $this->assertCount(2, $wind['areas']);
        $this->assertSame('MultiPolygon', $wind['areas'][1]['geometry']['type']);
    }

    #[Test]
    public function active_at_answers_for_the_moment_the_client_asks_about(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);

        // 05:05 is after the rain warning became effective and before the wind
        // warning did, so the parameter — not the wall clock — decides.
        $this->getJson('/api/v1/alerts?active_at=2026-01-15T05%3A05%3A00Z')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identifier', 'fixture-alert-0001')
            ->assertJsonPath('meta.active_at', '2026-01-15T05:05:00.000000Z');

        $this->getJson('/api/v1/alerts?active_at=2026-01-15T04%3A59%3A59Z')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/alerts?active_at=2030-01-02T00%3A00%3A00Z')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function severity_and_event_code_filters_narrow_the_published_list(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);

        $this->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/alerts?severity=Severe')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identifier', 'fixture-alert-0001');

        $this->getJson('/api/v1/alerts?event_code=FIXTURE_STRONG_WIND')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identifier', 'fixture-alert-0002');

        // The only Minor message is the expired one, so a filter can never
        // widen the publication rule it is applied on top of.
        $this->getJson('/api/v1/alerts?severity=Minor')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_bbox_filter_keeps_only_warnings_overlapping_the_requested_extent(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);

        // Inside the rain warning's polygon and outside both wind areas.
        $this->getJson('/api/v1/alerts?bbox=68.5,38.4,68.9,38.7')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identifier', 'fixture-alert-0001');

        $this->getJson('/api/v1/alerts?bbox=0,0,1,1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function list_query_errors_use_the_stable_envelope_and_request_id(): void
    {
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);

        $response = $this->getJson('/api/v1/alerts?bbox=69,39,68,38')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_bbox')
            ->assertJsonPath('error.details.field', 'bbox')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        $this->assertSame($response->json('error.request_id'), $response->headers->get('X-Request-Id'));

        $this->getJson('/api/v1/alerts?bbox=68.5,38.4,68.9')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_bbox');

        $this->getJson('/api/v1/alerts?active_at=yesterday')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_datetime')
            ->assertJsonPath('error.details.field', 'active_at')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        // A timestamp without a zone would be read in whichever timezone the
        // reader happened to assume, which is exactly the ambiguity the
        // canonical reader refuses.
        $this->getJson('/api/v1/alerts?active_at=2026-01-15T05%3A00%3A00')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_datetime');
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function warningLanguages(): array
    {
        return [
            'tajik' => [
                'tj',
                'tg-TJ',
                'Огоҳии озмоишӣ: борони шадид (fixture)',
                'Маълумоти намунавӣ барои санҷиши портал. Ин огоҳии воқеӣ нест.',
            ],
            'russian' => [
                'ru',
                'ru-RU',
                'Тестовое предупреждение: сильный дождь (fixture)',
                'Демонстрационные данные для проверки портала. Это не настоящее предупреждение.',
            ],
            'english' => [
                'en',
                'en-GB',
                'Fixture warning: heavy rain (fixture)',
                'Demonstration data used to exercise the portal. This is not a real warning.',
            ],
        ];
    }

    #[Test]
    #[DataProvider('warningLanguages')]
    public function each_language_is_served_from_its_own_stored_text(
        string $acceptLanguage,
        string $contentLanguage,
        string $headline,
        string $description,
    ): void {
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);

        $this->withHeader('Accept-Language', $acceptLanguage)
            ->getJson('/api/v1/alerts?event_code=FIXTURE_HEAVY_RAIN')
            ->assertOk()
            // The internal `tj` key is mapped to the BCP 47 tag only here, at
            // the protocol boundary.
            ->assertHeader('Content-Language', $contentLanguage)
            ->assertJsonPath('data.0.headline', $headline)
            ->assertJsonPath('data.0.description', $description)
            ->assertJsonPath('data.0.areas.0.description', $this->areaDescriptions()[$acceptLanguage]);
    }

    #[Test]
    public function no_warning_language_falls_back_to_another(): void
    {
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);
        $headlines = [];

        foreach (['tj', 'ru', 'en'] as $language) {
            $headlines[] = $this->withHeader('Accept-Language', $language)
                ->getJson('/api/v1/alerts?event_code=FIXTURE_HEAVY_RAIN')
                ->assertOk()
                ->json('data.0.headline');
        }

        // Three identical headlines would mean one language silently stood in
        // for the others, which no approved rule permits for a warning.
        $this->assertCount(3, array_unique($headlines));
    }

    #[Test]
    public function the_detail_endpoint_reports_lifecycle_state_and_the_message_chain(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline, FixtureAlertScenario::Lifecycle);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/alerts/fixture/fixture-alert-0001-update-1')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertJsonPath('data.identifier', 'fixture-alert-0001-update-1')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.status', 'Actual')
            ->assertJsonPath('data.message_type', 'Update')
            ->assertJsonPath('data.superseded_at', null)
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.0.identifier', 'fixture-alert-0001-update-1')
            ->assertJsonPath('data.history.1.identifier', 'fixture-alert-0001');

        // A client that stored the superseded identifier must still be able to
        // explain what happened to it, which is the reason the chain is kept.
        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/alerts/fixture/fixture-alert-0001')
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.message_type', 'Alert')
            // The moment the replacement was sent, not the moment it was read.
            ->assertJsonPath('data.superseded_at', '2026-01-15T06:30:00Z')
            ->assertJsonPath('data.headline', 'Fixture warning: heavy rain (fixture)')
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.0.message_type', 'Update')
            ->assertJsonPath('data.history.1.superseded_at', '2026-01-15T06:30:00Z');

        // An expired warning is history, not a missing resource.
        $this->getJson('/api/v1/alerts/fixture/fixture-alert-0003')
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.superseded_at', null);
    }

    #[Test]
    public function the_detail_endpoint_does_not_reveal_that_a_non_public_message_exists(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);
        $restricted = AlertMessage::factory()->restricted()->create([
            'source' => 'fixture',
            'identifier' => 'fixture-restricted-0001',
            'headline_en' => 'Restricted operational warning',
        ]);

        $unknown = $this->getJson('/api/v1/alerts/fixture/fixture-alert-9999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        // A Test-status message is stored so an operator can see it arrived and
        // is unreadable publicly; fixture-alert-0004 is that message.
        $test = $this->getJson('/api/v1/alerts/fixture/fixture-alert-0004')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $withheld = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/v1/alerts/fixture/{$restricted->identifier}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        // Identical bodies apart from the request id: distinguishing "withheld"
        // from "absent" would let anyone enumerate restricted identifiers.
        $this->assertSame(
            $this->withoutRequestId($unknown->json()),
            $this->withoutRequestId($test->json()),
        );
        $this->assertSame(
            $this->withoutRequestId($unknown->json()),
            $this->withoutRequestId($withheld->json()),
        );
        $this->assertStringNotContainsString(
            'Restricted operational warning',
            (string) $withheld->getContent(),
        );
    }

    #[Test]
    public function metadata_announces_the_alert_capability_without_claiming_an_approved_palette(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');

        $this->getJson('/api/v1/metadata')
            ->assertOk()
            ->assertJsonPath('data.alerts_available', true)
            ->assertJsonPath('data.alert_severity_order', ['Extreme', 'Severe', 'Moderate', 'Minor', 'Unknown'])
            // Hydromet has approved no national severity colour scale, so the
            // portal must not let a client render one as official.
            ->assertJsonPath('data.alert_severity_palette_approved', false);
    }

    #[Test]
    public function the_alert_list_does_not_leak_provider_internals(): void
    {
        Carbon::setTestNow('2026-09-02T08:00:00Z');
        $this->importFixtureAlerts(FixtureAlertScenario::Baseline);

        $response = $this->getJson('/api/v1/alerts')->assertOk();
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('raw_payload', $body);
        $this->assertStringNotContainsString('imported_at', $body);
        $this->assertStringNotContainsString('superseded_by_id', $body);
        // Neither the fixture file nor the directory it was read from may reach
        // a client, in either raw or JSON-escaped form.
        $this->assertStringNotContainsString('.fixture.json', $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString(str_replace('\\', '\\\\', base_path()), $body);

        // The provider desk name is publishable only as the documented sender;
        // any further occurrence would be an origin or credential field.
        $this->assertSame(
            count((array) $response->json('data')),
            substr_count($body, 'fixture-warning-desk'),
        );
    }

    private function importFixtureAlerts(FixtureAlertScenario ...$scenarios): void
    {
        $importer = new AlertImporter;

        foreach ($scenarios as $scenario) {
            $importer->import(new FixtureAlertProvider($scenario));
        }
    }

    /**
     * @return array<string, string>
     */
    private function areaDescriptions(): array
    {
        return [
            'tj' => 'Минтақаи озмоишии A (fixture)',
            'ru' => 'Тестовый регион A (fixture)',
            'en' => 'Fixture region A (fixture)',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function withoutRequestId(?array $payload): array
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        unset($error['request_id']);

        return $error;
    }
}
