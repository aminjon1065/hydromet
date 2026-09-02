<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\AlertMessages\AlertMessageResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlertAdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The moment the lifecycle assertions are evaluated at.
     *
     * The lifecycle badge is derived from the clock, so the tests that assert
     * it freeze time instead of trusting the day the suite happens to run: it
     * sits after the expired factory state (2026-01-02) and well before the
     * default warning's expiry (2030-01-01).
     */
    private const LIFECYCLE_MOMENT = '2026-06-01T09:00:00Z';

    /**
     * @return array<string, array{UserRole}>
     */
    public static function activeRoles(): array
    {
        return [
            'administrator' => [UserRole::Administrator],
            'operator' => [UserRole::Operator],
            'editor' => [UserRole::Editor],
        ];
    }

    #[Test]
    #[DataProvider('activeRoles')]
    public function active_users_can_list_warning_messages(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        AlertMessage::factory()->create();

        $this->actingAs($user)->get('/admin/alert-messages')->assertOk();
    }

    #[Test]
    #[DataProvider('activeRoles')]
    public function active_users_can_view_a_warning_message(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $message = AlertMessage::factory()->create();
        AlertArea::factory()->create(['alert_message_id' => $message->id]);

        $this->actingAs($user)->get("/admin/alert-messages/{$message->id}")->assertOk();
    }

    #[Test]
    public function a_deactivated_user_cannot_reach_the_resource(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]);
        $message = AlertMessage::factory()->create();

        $this->actingAs($user)->get('/admin/alert-messages')->assertForbidden();
        $this->actingAs($user)->get("/admin/alert-messages/{$message->id}")->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_the_panel_login(): void
    {
        $message = AlertMessage::factory()->create();

        $this->get('/admin/alert-messages')->assertRedirect('/admin/login');
        $this->get("/admin/alert-messages/{$message->id}")->assertRedirect('/admin/login');
    }

    #[Test]
    public function only_list_and_view_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter(fn (string $name): bool => str_contains($name, 'resources.alert-messages.'))
            ->values()
            ->all();

        sort($names);

        // Issuing or withdrawing a public warning is an authority decision the
        // panel must not be able to make: the absence of the route is the
        // guarantee, not a hidden button.
        $this->assertSame([
            'filament.admin.resources.alert-messages.index',
            'filament.admin.resources.alert-messages.view',
        ], $names);
    }

    #[Test]
    public function mutating_resource_paths_return_not_found(): void
    {
        $user = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $message = AlertMessage::factory()->create();

        $this->actingAs($user)->get('/admin/alert-messages/create')->assertNotFound();
        $this->actingAs($user)->get("/admin/alert-messages/{$message->id}/edit")->assertNotFound();
    }

    #[Test]
    public function the_resource_registers_only_list_and_view_pages(): void
    {
        $this->assertSame(['index', 'view'], array_keys(AlertMessageResource::getPages()));
    }

    #[Test]
    public function every_mutating_ability_is_denied(): void
    {
        $user = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $this->actingAs($user);

        $message = AlertMessage::factory()->create();

        $this->assertTrue(AlertMessageResource::canViewAny());
        $this->assertTrue(AlertMessageResource::canView($message));
        $this->assertFalse(AlertMessageResource::canCreate());
        $this->assertFalse(AlertMessageResource::canEdit($message));
        $this->assertFalse(AlertMessageResource::canDelete($message));
        $this->assertFalse(AlertMessageResource::canDeleteAny());
        $this->assertFalse(AlertMessageResource::canForceDelete($message));
        $this->assertFalse(AlertMessageResource::canForceDeleteAny());
        $this->assertFalse(AlertMessageResource::canRestore($message));
        $this->assertFalse(AlertMessageResource::canRestoreAny());
        $this->assertFalse(AlertMessageResource::canReplicate($message));
        $this->assertFalse(AlertMessageResource::canReorder());
    }

    #[Test]
    public function the_list_shows_the_identifier_and_the_lifecycle_state(): void
    {
        Carbon::setTestNow(Carbon::parse(self::LIFECYCLE_MOMENT));
        // The badge labels are translated, so the run is pinned to one locale:
        // the assertion is about the lifecycle state, not about which language
        // the panel happens to default to.
        App::setLocale('en');

        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        AlertMessage::factory()->create(['identifier' => 'ALERT-LIST-001']);

        // Ordered rather than a bare assertSee: the same wording labels the
        // "in force" filter above the table, so only the occurrence that
        // follows the identifier proves the row's own badge.
        $this->actingAs($user)
            ->get('/admin/alert-messages')
            ->assertOk()
            ->assertSeeInOrder(['ALERT-LIST-001', 'In force'])
            ->assertDontSee('alerts.lifecycle.active');
    }

    #[Test]
    public function the_view_page_shows_every_language_headline(): void
    {
        App::setLocale('en');

        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        $message = AlertMessage::factory()->create([
            'identifier' => 'ALERT-VIEW-001',
            'headline_tj' => 'Сарлавҳаи тоҷикӣ',
            'headline_ru' => 'Русский заголовок',
            'headline_en' => 'English headline',
        ]);

        // All three are shown side by side and never substituted for one
        // another, so an operator can see that a translation is missing rather
        // than reading a warning silently rendered in the wrong language.
        $this->actingAs($user)
            ->get("/admin/alert-messages/{$message->id}")
            ->assertOk()
            ->assertSee('ALERT-VIEW-001')
            ->assertSee('Сарлавҳаи тоҷикӣ')
            ->assertSee('Русский заголовок')
            ->assertSee('English headline');
    }

    #[Test]
    public function a_replaced_or_run_out_message_is_never_listed_as_in_force(): void
    {
        Carbon::setTestNow(Carbon::parse(self::LIFECYCLE_MOMENT));
        App::setLocale('en');

        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);

        // A test message is stored so an operator can see that it arrived, and
        // it must be labelled as never published rather than as a live warning.
        AlertMessage::factory()->testStatus()->create([
            'identifier' => 'ALERT-NOT-PUBLISHED',
            'sent_at' => Carbon::parse('2026-01-20T05:00:00Z'),
            'effective_at' => Carbon::parse('2026-01-20T05:00:00Z'),
        ]);

        $successor = AlertMessage::factory()
            ->update('ALERT-CHAIN-ORIGINAL')
            ->create(['identifier' => 'ALERT-CHAIN-SUCCESSOR']);

        // The predecessor keeps its own content and stays in the table: the
        // history is what lets the portal answer "what did we publish then".
        AlertMessage::factory()->create([
            'identifier' => 'ALERT-CHAIN-ORIGINAL',
            'sent_at' => Carbon::parse('2026-01-10T05:00:00Z'),
            'effective_at' => Carbon::parse('2026-01-10T05:00:00Z'),
            'superseded_by_id' => $successor->id,
            'superseded_at' => Carbon::parse('2026-01-15T05:00:00Z'),
        ]);

        AlertMessage::factory()->expired()->create(['identifier' => 'ALERT-RUN-OUT']);

        // Newest first, so each identifier is followed by its own badge: the
        // order is what ties a state to a row rather than to the page.
        $this->actingAs($user)
            ->get('/admin/alert-messages')
            ->assertOk()
            ->assertSeeInOrder([
                'ALERT-NOT-PUBLISHED',
                'Not published',
                'ALERT-CHAIN-SUCCESSOR',
                'In force',
                'ALERT-CHAIN-ORIGINAL',
                'Superseded',
                'ALERT-RUN-OUT',
                'Expired',
            ]);
    }

    /**
     * A message whose start has not arrived is queued, not live.
     *
     * Calling it "in force" in the panel would tell an operator the public can
     * see a warning they cannot, which is the worst direction for this
     * particular mistake to go.
     */
    #[Test]
    public function a_message_that_has_not_started_yet_is_shown_as_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse(self::LIFECYCLE_MOMENT));
        App::setLocale('en');

        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);

        AlertMessage::factory()->create([
            'identifier' => 'ALERT-NOT-STARTED',
            'sent_at' => Carbon::parse('2026-07-01T05:00:00Z'),
            'effective_at' => Carbon::parse('2026-07-01T05:00:00Z'),
            'expires_at' => Carbon::parse('2026-08-01T05:00:00Z'),
        ]);

        // The same defect in its other shape: no effective time at all, and a
        // send time still in the future.
        AlertMessage::factory()->create([
            'identifier' => 'ALERT-NO-EFFECTIVE-TIME',
            'sent_at' => Carbon::parse('2026-06-20T05:00:00Z'),
            'effective_at' => null,
            'expires_at' => Carbon::parse('2026-08-01T05:00:00Z'),
        ]);

        $response = $this->actingAs($user)->get('/admin/alert-messages')->assertOk();

        $response->assertSeeInOrder(['ALERT-NOT-STARTED', 'Scheduled']);
        $response->assertSeeInOrder(['ALERT-NO-EFFECTIVE-TIME', 'Scheduled']);
        $response->assertDontSee('alerts.lifecycle.scheduled');

        // And the filter an operator uses agrees with the badge.
        $this->assertSame(
            0,
            AlertMessage::query()->activeAt(Carbon::parse(self::LIFECYCLE_MOMENT))->count(),
        );
        $this->assertSame(
            2,
            AlertMessage::query()->scheduledAt(Carbon::parse(self::LIFECYCLE_MOMENT))->count(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lifecycleStates(): array
    {
        return [
            'active' => ['active'],
            'scheduled' => ['scheduled'],
            'superseded' => ['superseded'],
            'expired' => ['expired'],
            'withheld' => ['withheld'],
        ];
    }

    #[Test]
    #[DataProvider('lifecycleStates')]
    public function every_lifecycle_state_is_translated_in_every_language(string $state): void
    {
        foreach (['tj', 'ru', 'en'] as $locale) {
            App::setLocale($locale);

            $label = __('alerts.lifecycle.'.$state);

            $this->assertIsString($label);
            $this->assertNotSame('alerts.lifecycle.'.$state, $label, "Missing {$locale} label for {$state}.");
        }
    }
}
