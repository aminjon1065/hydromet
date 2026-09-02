<?php

namespace Tests\Feature\Integrations;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRejectedRow;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Filament\Resources\IntegrationSources\IntegrationSourceResource;
use App\Filament\Resources\SynchronizationRuns\SynchronizationRunResource;
use App\Support\Canonical\RejectionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SynchronizationAdminResourcesTest extends TestCase
{
    use RefreshDatabase;

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
    public function active_users_can_list_sources_and_runs(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);

        $this->actingAs($user)->get('/admin/integration-sources')->assertOk();
        $this->actingAs($user)->get('/admin/synchronization-runs')->assertOk();
    }

    #[Test]
    #[DataProvider('activeRoles')]
    public function active_users_can_view_a_source_and_a_run(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $source = IntegrationSource::factory()->http()->create();
        $run = SynchronizationRun::factory()->partial()->create(['source_id' => $source->id]);

        $this->actingAs($user)->get("/admin/integration-sources/{$source->id}")->assertOk();
        $this->actingAs($user)->get("/admin/synchronization-runs/{$run->id}")->assertOk();
    }

    #[Test]
    public function a_deactivated_user_cannot_reach_the_resources(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]);
        $source = IntegrationSource::factory()->create();
        $run = SynchronizationRun::factory()->create(['source_id' => $source->id]);

        $this->actingAs($user)->get('/admin/integration-sources')->assertForbidden();
        $this->actingAs($user)->get('/admin/synchronization-runs')->assertForbidden();
        $this->actingAs($user)->get("/admin/integration-sources/{$source->id}")->assertForbidden();
        $this->actingAs($user)->get("/admin/synchronization-runs/{$run->id}")->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_the_panel_login(): void
    {
        $this->get('/admin/integration-sources')->assertRedirect('/admin/login');
        $this->get('/admin/synchronization-runs')->assertRedirect('/admin/login');
    }

    #[Test]
    public function only_list_and_view_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter(fn (string $name): bool => str_contains($name, 'resources.integration-sources.')
                || str_contains($name, 'resources.synchronization-runs.'))
            ->values()
            ->all();

        sort($names);

        $this->assertSame([
            'filament.admin.resources.integration-sources.index',
            'filament.admin.resources.integration-sources.view',
            'filament.admin.resources.synchronization-runs.index',
            'filament.admin.resources.synchronization-runs.view',
        ], $names);
    }

    #[Test]
    public function mutating_resource_paths_return_not_found(): void
    {
        $user = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $source = IntegrationSource::factory()->create();
        $run = SynchronizationRun::factory()->create(['source_id' => $source->id]);

        $this->actingAs($user)->get('/admin/integration-sources/create')->assertNotFound();
        $this->actingAs($user)->get("/admin/integration-sources/{$source->id}/edit")->assertNotFound();
        $this->actingAs($user)->get('/admin/synchronization-runs/create')->assertNotFound();
        $this->actingAs($user)->get("/admin/synchronization-runs/{$run->id}/edit")->assertNotFound();
    }

    #[Test]
    public function resources_register_only_list_and_view_pages(): void
    {
        $this->assertSame(['index', 'view'], array_keys(IntegrationSourceResource::getPages()));
        $this->assertSame(['index', 'view'], array_keys(SynchronizationRunResource::getPages()));
    }

    #[Test]
    public function every_mutating_ability_is_denied(): void
    {
        $user = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $this->actingAs($user);

        $source = IntegrationSource::factory()->create();
        $run = SynchronizationRun::factory()->create(['source_id' => $source->id]);

        foreach ([
            [IntegrationSourceResource::class, $source],
            [SynchronizationRunResource::class, $run],
        ] as [$resource, $record]) {
            $this->assertTrue($resource::canViewAny());
            $this->assertTrue($resource::canView($record));
            $this->assertFalse($resource::canCreate());
            $this->assertFalse($resource::canEdit($record));
            $this->assertFalse($resource::canDelete($record));
            $this->assertFalse($resource::canDeleteAny());
            $this->assertFalse($resource::canForceDelete($record));
            $this->assertFalse($resource::canForceDeleteAny());
            $this->assertFalse($resource::canRestore($record));
            $this->assertFalse($resource::canRestoreAny());
            $this->assertFalse($resource::canReplicate($record));
            $this->assertFalse($resource::canReorder());
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function navigationGroupTranslations(): array
    {
        return [
            'tj' => ['tj', 'Интегратсияҳо'],
            'ru' => ['ru', 'Интеграции'],
            'en' => ['en', 'Integrations'],
        ];
    }

    #[Test]
    #[DataProvider('navigationGroupTranslations')]
    public function navigation_group_is_translated_and_rendered(string $locale, string $expected): void
    {
        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        App::setLocale($locale);

        $this->assertSame($expected, IntegrationSourceResource::getNavigationGroup());
        $this->assertSame($expected, SynchronizationRunResource::getNavigationGroup());

        $this->actingAs($user)
            ->get('/admin/synchronization-runs')
            ->assertOk()
            ->assertSee($expected)
            ->assertDontSee('integrations.navigation_group');
    }

    #[Test]
    public function source_view_shows_non_secret_configuration_and_mapping(): void
    {
        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        $source = IntegrationSource::factory()->http()->create([
            'code' => 'HYDROMET-TEST',
            'parameter_mapping' => ['provider_pm25' => 'PM25'],
        ]);

        $this->actingAs($user)
            ->get("/admin/integration-sources/{$source->id}")
            ->assertOk()
            ->assertSee('HYDROMET-TEST')
            ->assertSee('provider_pm25')
            ->assertSee('PM25');
    }

    #[Test]
    public function run_view_shows_the_safe_rejection_summary(): void
    {
        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        $source = IntegrationSource::factory()->create(['code' => 'HYDROMET-JOURNAL']);
        $run = SynchronizationRun::factory()->partial()->create(['source_id' => $source->id]);
        SynchronizationRejectedRow::factory()->create([
            'synchronization_run_id' => $run->id,
            'reference' => 'hydromet:station-404:PM25:2026-08-31T06:00:00.000000Z:-',
            'reason_code' => RejectionReason::UnknownStation,
            'safe_detail' => 'No canonical station matched this provider identifier.',
        ]);

        $this->actingAs($user)
            ->get("/admin/synchronization-runs/{$run->id}")
            ->assertOk()
            ->assertSee('HYDROMET-JOURNAL')
            ->assertSee('unknown_station')
            ->assertSee('No canonical station matched this provider identifier.');
    }
}
