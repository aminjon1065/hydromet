<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /alerts` — every warning the portal has published, newest first.
 *
 * The overview answers "is anything happening now" and drops a warning the
 * moment it expires or is withdrawn. Its own empty state has been telling
 * people that those warnings "remain available through the warning history"
 * while there was no such list: the detail page could be reached only if you
 * already knew an identifier.
 *
 * The list is deliberately unfiltered. A region filter needs an approved
 * region vocabulary, a date range needs the feed's refresh semantics, and
 * whether a `Test` message may ever be published is a Hydromet decision — all
 * three are open (`docs/05-api-contract.md`, section 2). Chronological order is
 * the one thing that needs nobody's approval.
 */
class PublicAlertHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-06-01T12:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    // --- What the list contains --------------------------------------------

    /**
     * The point of the page: a warning that has left the overview is still
     * here, and says which of the two things happened to it.
     */
    #[Test]
    public function it_lists_warnings_that_are_no_longer_in_force(): void
    {
        // Every send time is stated, so the expected order below is the
        // ordering rule rather than an accident of the factory's defaults.
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

        $this->withSession(['locale' => 'en'])
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('alerts/index')
                ->has('alerts', 4)
                // Newest first.
                ->where('alerts.0.identifier', 'IN-FORCE')
                ->where('alerts.0.isActive', true)
                ->where('alerts.1.identifier', 'EXPIRED')
                ->where('alerts.1.isActive', false)
                ->where('alerts.1.supersededAt', null)
                ->where('alerts.3.identifier', 'WITHDRAWN')
                ->where('alerts.3.isActive', false)
                ->where('alerts.3.supersededAt', '2026-04-02T00:00:00Z'));
    }

    #[Test]
    public function each_entry_carries_what_the_list_renders_and_nothing_heavier(): void
    {
        $warning = $this->warning('DETAILED', [
            'severity' => AlertSeverity::Extreme,
            'headline_en' => 'Extreme rainfall',
        ]);
        // Through the factory, which derives the bounding box a geometry must
        // carry: `alert_areas_bbox_pairing_check` refuses a polygon without
        // one, so the map never has to scan a table to find what to draw.
        AlertArea::factory()->for($warning, 'message')->create([
            'description_tj' => 'Минтақа',
            'description_ru' => 'Район',
            'description_en' => 'Test district',
        ]);

        $this->withSession(['locale' => 'en'])
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alerts.0.severity', AlertSeverity::Extreme->value)
                ->where('alerts.0.messageType', AlertMessageType::Alert->value)
                ->where('alerts.0.headline', 'Extreme rainfall')
                ->where('alerts.0.areas', ['Test district'])
                // Sourced from the fixture feed, so the row says so and the
                // page can label it. A real feed's warning must never be.
                ->where('alerts.0.isMock', true)
                // The list draws no map, so no geometry is shipped to it. Every
                // polygon on the page would be paid for on every request.
                ->missing('alerts.0.areas.0.geometry'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function locales(): array
    {
        return [
            'Tajik' => ['tj', 'Огоҳии озмоишӣ'],
            'Russian' => ['ru', 'Тестовое предупреждение'],
            'English' => ['en', 'Test warning'],
        ];
    }

    #[Test]
    #[DataProvider('locales')]
    public function the_list_is_rendered_in_the_chosen_language(string $locale, string $headline): void
    {
        $this->warning('LOCALIZED');

        $this->withSession(['locale' => $locale])
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alerts.0.headline', $headline));
    }

    #[Test]
    public function timestamps_leave_the_server_in_utc(): void
    {
        $this->warning('UTC', [
            'sent_at' => Carbon::parse('2026-05-30T04:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-30T06:00:00Z'),
            'expires_at' => Carbon::parse('2026-06-30T18:00:00Z'),
        ]);

        $this->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alerts.0.sentAt', '2026-05-30T04:00:00Z')
                ->where('alerts.0.effectiveAt', '2026-05-30T06:00:00Z')
                ->where('alerts.0.expiresAt', '2026-06-30T18:00:00Z'));
    }

    #[Test]
    public function an_installation_with_no_warnings_renders_an_empty_list(): void
    {
        $this->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('alerts/index')
                ->has('alerts', 0)
                ->where('older', null)
                ->where('newer', null));
    }

    // --- What the list must never contain ----------------------------------

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
     * A history list is exactly where a non-public message would leak: it is
     * the one surface that deliberately shows what is no longer current, so
     * "it expired anyway" is not a reason to relax the rule.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[Test]
    #[DataProvider('messagesThatAreNotPublic')]
    public function a_message_that_is_not_public_never_appears(array $attributes): void
    {
        $this->warning('HIDDEN', $attributes);
        $this->warning('VISIBLE');

        $this->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('alerts', 1)
                ->where('alerts.0.identifier', 'VISIBLE'));
    }

    // --- Paging ------------------------------------------------------------

    #[Test]
    public function a_long_history_is_paged_rather_than_returned_whole(): void
    {
        for ($index = 1; $index <= 25; $index++) {
            $this->warning(sprintf('PAGED-%02d', $index), [
                'sent_at' => Carbon::parse('2026-01-01T00:00:00Z')->addDays($index),
                'effective_at' => Carbon::parse('2026-01-01T00:00:00Z')->addDays($index),
            ]);
        }

        $first = $this->get('/alerts');

        $first->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('alerts', 20)
            // Newest first: the last one sent leads the list.
            ->where('alerts.0.identifier', 'PAGED-25')
            ->where('newer', null)
            ->whereNot('older', null));

        $older = $first->viewData('page')['props']['older'];

        $this->assertIsString($older);

        $this->get('/alerts?cursor='.urlencode($older))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('alerts', 5)
                ->where('alerts.0.identifier', 'PAGED-05')
                ->where('alerts.4.identifier', 'PAGED-01')
                // A way back, and nothing further to walk to.
                ->whereNot('newer', null)
                ->where('older', null));
    }

    /**
     * A cursor is opaque and arrives from the URL, so a broken one must be an
     * ordinary empty page rather than a stack trace.
     */
    #[Test]
    public function a_malformed_cursor_does_not_break_the_page(): void
    {
        $this->warning('ONLY');

        $this->get('/alerts?cursor=not-a-real-cursor')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('alerts/index'));
    }

    #[Test]
    public function an_over_long_cursor_is_refused_before_it_reaches_the_query(): void
    {
        $this->get('/alerts?cursor='.str_repeat('a', 3000))->assertStatus(302);
    }

    // --- Helpers -----------------------------------------------------------

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
