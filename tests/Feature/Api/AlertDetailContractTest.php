<?php

namespace Tests\Feature\Api;

use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Models\AlertMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/v1/alerts/{source}/{identifier}`.
 *
 * A CAP identifier is unique within its sender, not globally, so the public
 * identity of a warning is the pair. The endpoint used to take the identifier
 * alone and resolve the source to the literal `fixture`, which meant a real
 * feed's warning could appear in the list and then 404 on its own detail URL.
 */
class AlertDetailContractTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-06-01T00:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    #[Test]
    public function a_warning_from_a_source_that_is_not_the_fixture_feed_is_addressable(): void
    {
        AlertMessage::factory()->create([
            'source' => 'hydromet-meteoalert',
            'identifier' => 'TJ-2026-000123',
            'headline_en' => 'Warning from a real feed',
        ]);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/alerts/hydromet-meteoalert/TJ-2026-000123')
            ->assertOk()
            ->assertJsonPath('data.source', 'hydromet-meteoalert')
            ->assertJsonPath('data.identifier', 'TJ-2026-000123')
            ->assertJsonPath('data.headline', 'Warning from a real feed')
            // Nothing from a real feed may be labelled demonstration data.
            ->assertJsonPath('data.is_mock', false);
    }

    #[Test]
    public function the_same_identifier_in_two_sources_returns_two_different_warnings(): void
    {
        foreach ([
            'fixture' => 'Synthetic warning',
            'hydromet-meteoalert' => 'Real warning',
        ] as $source => $headline) {
            AlertMessage::factory()->create([
                'source' => $source,
                'identifier' => 'TJ-ALERT-1',
                'headline_en' => $headline,
            ]);
        }

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/alerts/fixture/TJ-ALERT-1')
            ->assertOk()
            ->assertJsonPath('data.headline', 'Synthetic warning')
            ->assertJsonPath('data.is_mock', true);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/alerts/hydromet-meteoalert/TJ-ALERT-1')
            ->assertOk()
            ->assertJsonPath('data.headline', 'Real warning')
            ->assertJsonPath('data.is_mock', false);
    }

    /**
     * The list is what a client builds a detail URL from, so every entry has to
     * carry both halves of the identity.
     */
    #[Test]
    public function every_listed_warning_can_be_opened_from_what_the_list_returned(): void
    {
        AlertMessage::factory()->create(['source' => 'fixture', 'identifier' => 'TJ-ALERT-1']);
        AlertMessage::factory()->create(['source' => 'hydromet-meteoalert', 'identifier' => 'TJ-ALERT-1']);

        $listed = $this->getJson('/api/v1/alerts')->assertOk()->json('data');

        $this->assertCount(2, $listed);

        foreach ($listed as $entry) {
            $this->getJson("/api/v1/alerts/{$entry['source']}/{$entry['identifier']}")
                ->assertOk()
                ->assertJsonPath('data.source', $entry['source'])
                ->assertJsonPath('data.identifier', $entry['identifier']);
        }
    }

    /**
     * No hidden fallback: asking the wrong source must not quietly find the
     * right warning somewhere else.
     */
    #[Test]
    public function the_source_segment_is_not_ignored(): void
    {
        AlertMessage::factory()->create([
            'source' => 'hydromet-meteoalert',
            'identifier' => 'TJ-ALERT-1',
        ]);

        $this->getJson('/api/v1/alerts/fixture/TJ-ALERT-1')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    /**
     * Provider identifiers are not the tidy slugs the fixtures happen to use.
     *
     * @return array<string, array{string}>
     */
    public static function realisticIdentifiers(): array
    {
        return [
            'urn style' => ['urn:oid:2.49.0.1.762.0.20260601120000'],
            'at sign and timestamp' => ['NWS-IDP-PROD-1@2026-06-01T00:00:00Z'],
            'underscores' => ['TJ_ALERT_2026_000123'],
            'dots' => ['tj.alert.2026.000123'],
            'mixed' => ['MeteoAlert-TJ.2026_06@01:00'],
            'tilde' => ['alert~2026~1'],
            'plus' => ['alert+2026+1'],
        ];
    }

    #[Test]
    #[DataProvider('realisticIdentifiers')]
    public function a_realistic_provider_identifier_is_addressable(string $identifier): void
    {
        AlertMessage::factory()->create([
            'source' => 'hydromet-meteoalert',
            'identifier' => $identifier,
        ]);

        $this->getJson('/api/v1/alerts/hydromet-meteoalert/'.rawurlencode($identifier))
            ->assertOk()
            ->assertJsonPath('data.identifier', $identifier);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedPaths(): array
    {
        return [
            'traversal' => ['/api/v1/alerts/fixture/../../metadata'],
            'encoded traversal' => ['/api/v1/alerts/fixture/%2E%2E%2F%2E%2E%2Fmetadata'],
            'encoded slash' => ['/api/v1/alerts/fixture/a%2Fb'],
            'space' => ['/api/v1/alerts/fixture/a b'],
            'angle bracket' => ['/api/v1/alerts/fixture/%3Cscript%3E'],
            'over-long identifier' => ['/api/v1/alerts/fixture/'.str_repeat('a', 191)],
            'over-long source' => ['/api/v1/alerts/'.str_repeat('a', 33).'/TJ-ALERT-1'],
            'empty identifier' => ['/api/v1/alerts/fixture/'],
        ];
    }

    #[Test]
    #[DataProvider('refusedPaths')]
    public function an_unroutable_path_answers_with_the_stable_error_envelope(string $path): void
    {
        AlertMessage::factory()->create(['source' => 'fixture', 'identifier' => 'TJ-ALERT-1']);

        $response = $this->getJson($path);

        // The point is that nothing unbounded reaches the query: whether the
        // router or the controller refuses it, the client gets the documented
        // envelope and never a stack trace.
        $this->assertContains($response->getStatusCode(), [404, 405]);
        $response->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);
        $this->assertStringNotContainsString('Exception', (string) $response->getContent());
    }

    #[Test]
    public function a_withheld_warning_from_any_source_stays_invisible(): void
    {
        AlertMessage::factory()->restricted()->create([
            'source' => 'hydromet-meteoalert',
            'identifier' => 'TJ-RESTRICTED-1',
            'headline_en' => 'Restricted operational warning',
        ]);

        AlertMessage::factory()->testStatus()->create([
            'source' => 'hydromet-meteoalert',
            'identifier' => 'TJ-TEST-1',
            'headline_en' => 'Exercise warning',
        ]);

        foreach (['TJ-RESTRICTED-1', 'TJ-TEST-1', 'TJ-ABSENT-1'] as $identifier) {
            $response = $this->getJson('/api/v1/alerts/hydromet-meteoalert/'.$identifier)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found');

            $body = (string) $response->getContent();

            $this->assertStringNotContainsString('Restricted operational warning', $body);
            $this->assertStringNotContainsString('Exercise warning', $body);
        }
    }

    /**
     * The route is the only place a source may be named, and it names none.
     */
    #[Test]
    public function the_detail_route_carries_no_default_source(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.alerts.show');

        $this->assertNotNull($route);
        $this->assertSame(['source', 'identifier'], $route->parameterNames());
        $this->assertSame([], $route->defaults);

        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/AlertShowController.php'));

        $this->assertIsString($controller);
        // A literal source key in the controller is the defect this replaced.
        $this->assertStringNotContainsString("'fixture'", $controller);
    }

    #[Test]
    public function the_public_alert_read_model_names_no_source_of_its_own(): void
    {
        foreach ([
            app_path('Domain/Alerts/Queries/PublicAlertOverview.php'),
            app_path('Http/Controllers/Api/V1/AlertIndexController.php'),
        ] as $path) {
            $contents = file_get_contents($path);

            $this->assertIsString($contents);
            $this->assertStringNotContainsString("'fixture'", $contents, basename($path).' hard-codes a source.');
        }
    }

    /**
     * Ordering is a display decision the API owes its clients, and sorting the
     * stored strings alphabetically gets it wrong twice: `Extreme` lands after
     * `Severe`, and `Minor` before `Moderate`.
     */
    #[Test]
    public function the_list_is_ordered_by_actual_severity_not_alphabetically(): void
    {
        // Created in a deliberately unhelpful order so neither insertion order
        // nor alphabetical order could produce the expected result by accident.
        foreach ([
            AlertSeverity::Minor,
            AlertSeverity::Extreme,
            AlertSeverity::Unknown,
            AlertSeverity::Moderate,
            AlertSeverity::Severe,
        ] as $severity) {
            AlertMessage::factory()->severity($severity)->create([
                'source' => 'fixture',
                'identifier' => 'TJ-'.$severity->value,
            ]);
        }

        $response = $this->getJson('/api/v1/alerts')->assertOk();

        $this->assertSame(
            ['Extreme', 'Severe', 'Moderate', 'Minor', 'Unknown'],
            array_column($response->json('data'), 'severity'),
        );

        // And the published ranking says the same thing as the data.
        $this->assertSame(
            $response->json('meta.severity_order'),
            array_column($response->json('data'), 'severity'),
        );
    }

    #[Test]
    public function warnings_of_equal_severity_fall_back_to_the_most_recent(): void
    {
        foreach ([
            'TJ-OLD' => '2026-01-10T05:00:00Z',
            'TJ-NEW' => '2026-01-20T05:00:00Z',
            'TJ-MID' => '2026-01-15T05:00:00Z',
        ] as $identifier => $sentAt) {
            AlertMessage::factory()->severity(AlertSeverity::Severe)->create([
                'source' => 'fixture',
                'identifier' => $identifier,
                'sent_at' => Carbon::parse($sentAt),
                'effective_at' => Carbon::parse($sentAt),
            ]);
        }

        $listed = $this->getJson('/api/v1/alerts')->assertOk()->json('data');

        $this->assertSame(['TJ-NEW', 'TJ-MID', 'TJ-OLD'], array_column($listed, 'identifier'));
    }

    #[Test]
    public function warnings_identical_on_severity_and_time_keep_a_stable_order(): void
    {
        foreach (['TJ-B', 'TJ-A', 'TJ-C'] as $identifier) {
            AlertMessage::factory()->severity(AlertSeverity::Moderate)->create([
                'source' => 'fixture',
                'identifier' => $identifier,
                'sent_at' => Carbon::parse('2026-01-15T05:00:00Z'),
                'effective_at' => Carbon::parse('2026-01-15T05:00:00Z'),
            ]);
        }

        $first = array_column($this->getJson('/api/v1/alerts')->json('data'), 'identifier');
        $second = array_column($this->getJson('/api/v1/alerts')->json('data'), 'identifier');

        $this->assertSame(['TJ-A', 'TJ-B', 'TJ-C'], $first);
        $this->assertSame($first, $second);
    }
}
