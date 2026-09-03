<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Models\AlertMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /alerts/{source}/{identifier}` — the public page for one warning.
 *
 * The home page answers "is anything happening". This answers the question a
 * person asks immediately afterwards: what was issued, what am I told to do,
 * and is this still the current version. It is also the page the home page's
 * own empty state has been promising, which said that withdrawn and expired
 * warnings remain available through the warning history while nothing public
 * could reach one.
 *
 * What the page may show is decided by `PublicAlertOverview::detail()`, the
 * same query the API endpoint uses. These tests assert the page, and that the
 * two surfaces agree — a message that 404s on the API must not be readable
 * here.
 */
class PublicAlertPageTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-06-01T12:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    // --- The warning itself ------------------------------------------------

    #[Test]
    public function a_public_warning_is_readable_at_its_own_url(): void
    {
        $warning = AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'TJ-ALERT-1',
            'severity' => AlertSeverity::Severe,
            'headline_en' => 'Heavy rainfall expected',
            'description_en' => 'Continuous rainfall across the valley.',
            // All three or none: `alert_messages_instruction_all_or_none_check`
            // refuses an instruction that exists in one language only, so a
            // reader is never told to act in a language they did not choose.
            'instruction_tj' => 'Аз соҳил дур бошед.',
            'instruction_ru' => 'Держитесь подальше от берега.',
            'instruction_en' => 'Avoid the riverbank.',
        ]);
        $warning->areas()->create([
            'description_tj' => 'Минтақа',
            'description_ru' => 'Район',
            'description_en' => 'Test district',
            'geocodes' => [],
            'geometry' => null,
        ]);

        $this->withSession(['locale' => 'en'])
            ->get('/alerts/fixture/TJ-ALERT-1')
            ->assertOk()
            // The rendered language comes from the session, so a shared cache
            // must never keep this response.
            ->assertHeader('Cache-Control', 'max-age=60, private')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('alerts/show')
                ->where('alert.identifier', 'TJ-ALERT-1')
                ->where('alert.source', 'fixture')
                ->where('alert.severity', AlertSeverity::Severe->value)
                ->where('alert.headline', 'Heavy rainfall expected')
                ->where('alert.description', 'Continuous rainfall across the valley.')
                ->where('alert.instruction', 'Avoid the riverbank.')
                ->where('alert.isActive', true)
                ->where('alert.areas.0.description', 'Test district')
                ->has('history', 1));
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
    public function the_warning_is_rendered_in_the_chosen_language(string $locale, string $headline): void
    {
        AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'TJ-ALERT-LOCALE',
        ]);

        $this->withSession(['locale' => $locale])
            ->get('/alerts/fixture/TJ-ALERT-LOCALE')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alert.headline', $headline));
    }

    /**
     * Every timestamp leaves the server in UTC. Converting to `Asia/Dushanbe`
     * is the browser's job, from the shared `displayTimezone` prop, so one
     * cached component cannot render a time in the timezone of whoever
     * requested the page before.
     */
    #[Test]
    public function timestamps_leave_the_server_in_utc(): void
    {
        AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'TJ-ALERT-UTC',
            'sent_at' => Carbon::parse('2026-05-30T04:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-30T06:00:00Z'),
            'expires_at' => Carbon::parse('2026-06-30T18:00:00Z'),
        ]);

        $this->get('/alerts/fixture/TJ-ALERT-UTC')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alert.sentAt', '2026-05-30T04:00:00Z')
                ->where('alert.effectiveAt', '2026-05-30T06:00:00Z')
                ->where('alert.expiresAt', '2026-06-30T18:00:00Z'));
    }

    // --- Warnings that are no longer in force -------------------------------

    /**
     * A permalink that 404s once the warning expires reads as "nothing was ever
     * wrong". Somebody following a forwarded link has to be told what happened
     * instead.
     */
    #[Test]
    public function an_expired_warning_is_still_readable_and_marked_as_not_in_force(): void
    {
        AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'TJ-ALERT-EXPIRED',
            'sent_at' => Carbon::parse('2026-05-01T00:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-01T00:00:00Z'),
            'expires_at' => Carbon::parse('2026-05-02T00:00:00Z'),
        ]);

        $this->get('/alerts/fixture/TJ-ALERT-EXPIRED')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alert.isActive', false)
                ->where('alert.supersededAt', null));
    }

    #[Test]
    public function a_superseded_warning_reports_when_it_was_replaced(): void
    {
        $original = AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'TJ-ALERT-ORIGINAL',
        ]);

        $original->forceFill([
            'superseded_at' => Carbon::parse('2026-05-20T09:00:00Z'),
            'superseded_by_id' => AlertMessage::factory()->create([
                'source' => 'fixture',
                'identifier' => 'TJ-ALERT-UPDATE',
                'message_type' => AlertMessageType::Update,
                'references' => ['TJ-ALERT-ORIGINAL'],
            ])->id,
        ])->save();

        $this->get('/alerts/fixture/TJ-ALERT-ORIGINAL')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alert.isActive', false)
                ->where('alert.supersededAt', '2026-05-20T09:00:00Z'));
    }

    /**
     * The chain, not a window: asking about any link of `Alert → Update →
     * Cancel` returns all three, newest first, each addressable in its own
     * right. This is what makes `ALERT-03` — a cancelled warning leaves the
     * active view without history loss — visible to the public rather than only
     * to an administrator.
     */
    #[Test]
    public function the_whole_message_chain_is_offered_from_any_of_its_links(): void
    {
        $alert = AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'CHAIN-ALERT',
            'sent_at' => Carbon::parse('2026-05-01T00:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-01T00:00:00Z'),
        ]);

        $update = AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'CHAIN-UPDATE',
            'message_type' => AlertMessageType::Update,
            'references' => ['CHAIN-ALERT'],
            'sent_at' => Carbon::parse('2026-05-02T00:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-02T00:00:00Z'),
        ]);
        $alert->forceFill([
            'superseded_at' => Carbon::parse('2026-05-02T00:00:00Z'),
            'superseded_by_id' => $update->id,
        ])->save();

        $cancel = AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'CHAIN-CANCEL',
            'message_type' => AlertMessageType::Cancel,
            'references' => ['CHAIN-UPDATE'],
            'sent_at' => Carbon::parse('2026-05-03T00:00:00Z'),
            'effective_at' => Carbon::parse('2026-05-03T00:00:00Z'),
        ]);
        $update->forceFill([
            'superseded_at' => Carbon::parse('2026-05-03T00:00:00Z'),
            'superseded_by_id' => $cancel->id,
        ])->save();

        foreach (['CHAIN-ALERT', 'CHAIN-UPDATE', 'CHAIN-CANCEL'] as $entry) {
            $this->get("/alerts/fixture/{$entry}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('history', 3)
                    // Newest first.
                    ->where('history.0.identifier', 'CHAIN-CANCEL')
                    ->where('history.1.identifier', 'CHAIN-UPDATE')
                    ->where('history.2.identifier', 'CHAIN-ALERT'));
        }
    }

    // --- What the page must refuse -----------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function messagesThatAreNotPublic(): array
    {
        return [
            'a test message' => [['status' => AlertStatus::Test]],
            'an exercise message' => [['status' => AlertStatus::Exercise]],
            'a draft message' => [['status' => AlertStatus::Draft]],
            'a restricted message' => [['scope' => AlertScope::Restricted]],
            'a private message' => [['scope' => AlertScope::Private]],
        ];
    }

    /**
     * Not found, never forbidden: the existence of a non-public message is
     * itself not public, and answering differently would let anyone enumerate
     * restricted identifiers by watching the status code.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[Test]
    #[DataProvider('messagesThatAreNotPublic')]
    public function a_message_that_is_not_public_is_reported_as_missing(array $attributes): void
    {
        AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'HIDDEN-1',
            ...$attributes,
        ]);

        $this->get('/alerts/fixture/HIDDEN-1')->assertNotFound();

        // The same answer the API gives, so neither surface can be used to
        // learn what the other hides.
        $this->getJson('/api/v1/alerts/fixture/HIDDEN-1')->assertNotFound();
    }

    #[Test]
    public function an_unknown_warning_is_reported_as_missing(): void
    {
        $this->get('/alerts/fixture/NOTHING-HERE')->assertNotFound();
    }

    /**
     * A CAP identifier is unique within its sender, not globally, so the source
     * is part of the address. Guessing the wrong one must not resolve.
     */
    #[Test]
    public function the_source_is_part_of_the_address(): void
    {
        AlertMessage::factory()->create([
            'source' => 'hydromet-meteoalert',
            'identifier' => 'SHARED-ID',
            'headline_en' => 'From the real feed',
        ]);

        $this->withSession(['locale' => 'en'])
            ->get('/alerts/hydromet-meteoalert/SHARED-ID')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alert.headline', 'From the real feed')
                // Nothing from a real feed may be labelled demonstration data.
                ->where('alert.isMock', false));

        $this->get('/alerts/fixture/SHARED-ID')->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function addressesTheRouterMustRefuse(): array
    {
        return [
            'a traversal attempt in the identifier' => ['/alerts/fixture/..%2F..%2Fetc%2Fpasswd'],
            'a traversal attempt in the source' => ['/alerts/..%2F..%2Fadmin/ID'],
            'an over-long source' => ['/alerts/'.str_repeat('a', 33).'/ID'],
            'an over-long identifier' => ['/alerts/fixture/'.str_repeat('a', 191)],
            'an empty identifier' => ['/alerts/fixture/'],
        ];
    }

    #[Test]
    #[DataProvider('addressesTheRouterMustRefuse')]
    public function an_address_outside_the_allowed_shape_never_reaches_the_query(string $path): void
    {
        $this->get($path)->assertNotFound();
    }
}
