<?php

namespace Tests\Feature\Api;

use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/v1/alerts/history`, docs/05-api-contract.md section 2.
 *
 * `/api/v1/alerts` answers "what is in force", and drops a warning the moment
 * it expires or is withdrawn — which is right for that endpoint and leaves an
 * API client unable to do what the portal's own history page does. Until now
 * the only way to reach a past warning over the API was to already know its
 * identifier and ask for it by name.
 *
 * The contract mirrors the web page deliberately: same query, same ordering,
 * same publication rule. What differs is the envelope, which follows the API's
 * own conventions — snake_case keys and a cursor in `meta`.
 */
class AlertHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-06-01T12:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    // --- What it returns ---------------------------------------------------

    #[Test]
    public function it_returns_warnings_that_are_no_longer_in_force(): void
    {
        $this->warning('IN-FORCE', [
            'headline_en' => 'Still in force',
            'sent_at' => Carbon::parse('2026-05-15T00:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-15T00:00:00Z'),
            'expires_at' => Carbon::parse('2027-01-01T00:00:00Z'),
        ]);

        $this->warning('EXPIRED', [
            'headline_en' => 'Already expired',
            'sent_at' => Carbon::parse('2026-05-01T00:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-01T00:00:00Z'),
            'expires_at' => Carbon::parse('2026-05-02T00:00:00Z'),
        ]);

        $withdrawn = $this->warning('WITHDRAWN', [
            'headline_en' => 'Withdrawn warning',
            'sent_at' => Carbon::parse('2026-04-01T00:00:00Z'),
            'effective_at' => Carbon::parse('2026-04-01T00:00:00Z'),
        ]);
        $withdrawn->forceFill([
            'superseded_at' => Carbon::parse('2026-04-02T00:00:00Z'),
            'superseded_by_id' => $this->warning('CANCELLATION', [
                'message_type' => AlertMessageType::Cancel,
                'references' => ['WITHDRAWN'],
                'sent_at' => Carbon::parse('2026-04-02T00:00:00Z'),
                'effective_at' => Carbon::parse('2026-04-02T00:00:00Z'),
            ])->id,
        ])->save();

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/alerts/history')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertHeader('Vary', 'Accept-Language')
            ->assertJsonCount(4, 'data')
            // Newest first.
            ->assertJsonPath('data.0.identifier', 'IN-FORCE')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.1.identifier', 'EXPIRED')
            ->assertJsonPath('data.1.is_active', false)
            ->assertJsonPath('data.1.superseded_at', null)
            ->assertJsonPath('data.3.identifier', 'WITHDRAWN')
            ->assertJsonPath('data.3.is_active', false)
            ->assertJsonPath('data.3.superseded_at', '2026-04-02T00:00:00Z');
    }

    /**
     * The envelope is the contract. Every key is asserted, so a field cannot be
     * added or renamed without a client-visible change being noticed here.
     */
    #[Test]
    public function each_entry_carries_the_documented_fields(): void
    {
        $warning = $this->warning('SHAPED', [
            'severity' => AlertSeverity::Extreme,
            'headline_en' => 'Extreme rainfall',
            'sent_at' => Carbon::parse('2026-05-30T04:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-30T06:00:00Z'),
            'expires_at' => Carbon::parse('2026-06-30T18:00:00Z'),
        ]);
        AlertArea::factory()->for($warning, 'message')->create([
            'description_en' => 'Test district',
        ]);

        $response = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/alerts/history')
            ->assertOk();

        $this->assertSame([
            'identifier' => 'SHAPED',
            'source' => 'fixture',
            'is_mock' => true,
            'message_type' => AlertMessageType::Alert->value,
            'severity' => AlertSeverity::Extreme->value,
            'headline' => 'Extreme rainfall',
            'sent_at' => '2026-05-30T04:00:00Z',
            'effective_at' => '2026-05-30T06:00:00Z',
            'expires_at' => '2026-06-30T18:00:00Z',
            'superseded_at' => null,
            'is_active' => true,
            'areas' => ['Test district'],
        ], $response->json('data.0'));

        // Deliberately absent: the list carries no geometry, description or
        // instruction. A client that needs them asks for the one warning.
        $this->assertArrayNotHasKey('geometry', $response->json('data.0'));
        $this->assertNull($response->json('meta.next_cursor'));
        $this->assertIsString($response->json('meta.generated_at'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function languages(): array
    {
        return [
            'Tajik' => ['tg-TJ', 'Огоҳии озмоишӣ'],
            'Russian' => ['ru', 'Тестовое предупреждение'],
            'English' => ['en-GB', 'Test warning'],
        ];
    }

    #[Test]
    #[DataProvider('languages')]
    public function it_is_localized_by_the_accept_language_header(string $language, string $headline): void
    {
        $this->warning('LOCALIZED');

        $this->withHeader('Accept-Language', $language)
            ->getJson('/api/v1/alerts/history')
            ->assertOk()
            ->assertJsonPath('data.0.headline', $headline);
    }

    #[Test]
    public function an_installation_with_no_warnings_returns_an_empty_list(): void
    {
        $this->getJson('/api/v1/alerts/history')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.next_cursor', null);
    }

    // --- What it must never return -----------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function messagesThatAreNotPublic(): array
    {
        return [
            'a test message' => [['status' => AlertStatus::Test]],
            'an exercise message' => [['status' => AlertStatus::Exercise]],
            'a draft message' => [['status' => AlertStatus::Draft]],
            'a system message' => [['status' => AlertStatus::System]],
            'a restricted message' => [['scope' => AlertScope::Restricted]],
            'a private message' => [['scope' => AlertScope::Private]],
        ];
    }

    /**
     * A history endpoint is where a non-public message would leak, because it
     * is the one that deliberately returns what is no longer current. "It
     * expired anyway" is not a reason to relax the rule.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[Test]
    #[DataProvider('messagesThatAreNotPublic')]
    public function a_message_that_is_not_public_never_appears(array $attributes): void
    {
        $this->warning('HIDDEN', $attributes);
        $this->warning('VISIBLE');

        $this->getJson('/api/v1/alerts/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identifier', 'VISIBLE');
    }

    /**
     * The two surfaces answer the same question, so they must agree about what
     * is public. A message hidden from one and shown by the other would let a
     * client learn what the portal is withholding.
     */
    #[Test]
    public function it_agrees_with_the_web_history_page_about_what_is_public(): void
    {
        $this->warning('PUBLIC-ONE');
        $this->warning('HIDDEN-ONE', ['status' => AlertStatus::Test]);

        $api = $this->identifiers($this->getJson('/api/v1/alerts/history')->assertOk()->json('data'));
        $page = $this->identifiers($this->get('/alerts')->assertOk()->viewData('page')['props']['alerts']);

        $this->assertSame(['PUBLIC-ONE'], $api);
        $this->assertSame($api, $page);
    }

    // --- Paging ------------------------------------------------------------

    #[Test]
    public function a_long_history_is_paged_with_a_cursor(): void
    {
        for ($index = 1; $index <= 105; $index++) {
            $this->warning(sprintf('PAGED-%03d', $index), [
                'sent_at' => Carbon::parse('2026-01-01T00:00:00Z')->addMinutes($index),
                'effective_at' => Carbon::parse('2026-01-01T00:00:00Z')->addMinutes($index),
            ]);
        }

        $first = $this->getJson('/api/v1/alerts/history')->assertOk();

        $first->assertJsonCount(100, 'data')
            ->assertJsonPath('data.0.identifier', 'PAGED-105');

        $cursor = $first->json('meta.next_cursor');

        $this->assertIsString($cursor);

        $this->getJson('/api/v1/alerts/history?cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.identifier', 'PAGED-005')
            ->assertJsonPath('data.4.identifier', 'PAGED-001')
            ->assertJsonPath('meta.next_cursor', null);
    }

    /**
     * The cursor is opaque and arrives from the client, so a broken one must be
     * an ordinary first page, and an oversized one must be refused by
     * validation before it reaches the query — with the API's own error
     * envelope, not a stack trace.
     */
    #[Test]
    public function a_malformed_cursor_returns_the_first_page(): void
    {
        $this->warning('ONLY');

        $this->getJson('/api/v1/alerts/history?cursor=not-a-real-cursor')
            ->assertOk()
            ->assertJsonPath('data.0.identifier', 'ONLY');
    }

    #[Test]
    public function an_over_long_cursor_is_refused_in_the_api_envelope(): void
    {
        $response = $this->getJson('/api/v1/alerts/history?cursor='.str_repeat('a', 3000))
            ->assertStatus(422);

        $this->assertIsString($response->json('error.code'));
        $this->assertStringNotContainsString('vendor', (string) $response->getContent());
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * The identifiers in a list of rows, whichever surface produced them.
     *
     * The two surfaces name their keys differently — snake_case over the API,
     * camelCase in the Inertia props — but `identifier` is spelled the same in
     * both, which is what makes them directly comparable.
     *
     * @return list<string>
     */
    private function identifiers(mixed $rows): array
    {
        $this->assertIsArray($rows);

        $identifiers = [];

        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $this->assertArrayHasKey('identifier', $row);

            $identifiers[] = (string) $row['identifier'];
        }

        return $identifiers;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function warning(string $identifier, array $attributes = []): AlertMessage
    {
        return AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => $identifier,
            ...$attributes,
        ]);
    }
}
