<?php

namespace Tests\Feature\Stations;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use App\Filament\Resources\Parameters\ParameterResource;
use App\Filament\Resources\Stations\StationResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationAdminResourcesTest extends TestCase
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
    public function active_users_can_list_stations_and_parameters(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);

        $this->actingAs($user)->get('/admin/stations')->assertOk();
        $this->actingAs($user)->get('/admin/parameters')->assertOk();
    }

    #[Test]
    #[DataProvider('activeRoles')]
    public function active_users_can_view_a_single_station_and_parameter(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $station = Station::factory()->create();
        $parameter = Parameter::factory()->create();

        $this->actingAs($user)->get("/admin/stations/{$station->id}")->assertOk();
        $this->actingAs($user)->get("/admin/parameters/{$parameter->id}")->assertOk();
    }

    #[Test]
    public function a_deactivated_user_cannot_reach_the_resources(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]);
        $station = Station::factory()->create();

        $this->actingAs($user)->get('/admin/stations')->assertForbidden();
        $this->actingAs($user)->get('/admin/parameters')->assertForbidden();
        $this->actingAs($user)->get("/admin/stations/{$station->id}")->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_the_panel_login(): void
    {
        $this->get('/admin/stations')->assertRedirect('/admin/login');
        $this->get('/admin/parameters')->assertRedirect('/admin/login');
    }

    #[Test]
    public function no_create_edit_or_delete_route_is_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter(fn (string $name): bool => str_contains($name, 'resources.stations.')
                || str_contains($name, 'resources.parameters.'))
            ->values()
            ->all();

        sort($names);

        $this->assertSame([
            'filament.admin.resources.parameters.index',
            'filament.admin.resources.parameters.view',
            'filament.admin.resources.stations.index',
            'filament.admin.resources.stations.view',
        ], $names);
    }

    #[Test]
    public function the_mutating_routes_return_not_found(): void
    {
        $user = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $station = Station::factory()->create();

        $this->actingAs($user)->get('/admin/stations/create')->assertNotFound();
        $this->actingAs($user)->get("/admin/stations/{$station->id}/edit")->assertNotFound();
        $this->actingAs($user)->get('/admin/parameters/create')->assertNotFound();
    }

    #[Test]
    public function the_resources_only_register_a_list_and_a_view_page(): void
    {
        $this->assertSame(['index', 'view'], array_keys(StationResource::getPages()));
        $this->assertSame(['index', 'view'], array_keys(ParameterResource::getPages()));
    }

    #[Test]
    public function every_mutating_ability_is_denied_for_an_administrator(): void
    {
        $user = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $this->actingAs($user);

        $station = Station::factory()->create();
        $parameter = Parameter::factory()->create();

        $this->assertTrue(StationResource::canViewAny());
        $this->assertTrue(StationResource::canView($station));
        $this->assertFalse(StationResource::canCreate());
        $this->assertFalse(StationResource::canEdit($station));
        $this->assertFalse(StationResource::canDelete($station));
        $this->assertFalse(StationResource::canDeleteAny());
        $this->assertFalse(StationResource::canReorder());
        $this->assertFalse(StationResource::canReplicate($station));

        $this->assertTrue(ParameterResource::canViewAny());
        $this->assertTrue(ParameterResource::canView($parameter));
        $this->assertFalse(ParameterResource::canCreate());
        $this->assertFalse(ParameterResource::canEdit($parameter));
        $this->assertFalse(ParameterResource::canDelete($parameter));
        $this->assertFalse(ParameterResource::canDeleteAny());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function navigationGroupTranslations(): array
    {
        return [
            'tj' => ['tj', 'Маълумотномаҳо'],
            'ru' => ['ru', 'Справочные данные'],
            'en' => ['en', 'Reference data'],
        ];
    }

    #[Test]
    #[DataProvider('navigationGroupTranslations')]
    public function the_navigation_group_is_translated(string $locale, string $expected): void
    {
        App::setLocale($locale);

        $this->assertSame($expected, StationResource::getNavigationGroup());
        $this->assertSame($expected, ParameterResource::getNavigationGroup());
    }

    #[Test]
    #[DataProvider('navigationGroupTranslations')]
    public function the_navigation_group_is_rendered_in_the_active_locale(string $locale, string $expected): void
    {
        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);

        App::setLocale($locale);

        $this->actingAs($user)
            ->get('/admin/stations')
            ->assertOk()
            // Resolved while the page renders. A static property would have
            // frozen one language at class-definition time.
            ->assertSee($expected)
            ->assertDontSee('stations.navigation_group');
    }

    #[Test]
    public function the_station_list_shows_the_parameter_count(): void
    {
        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        $station = Station::factory()->create(['code' => 'COUNT-001']);
        $station->parameters()->attach(Parameter::factory()->count(3)->create());

        $this->actingAs($user)
            ->get('/admin/stations')
            ->assertOk()
            ->assertSee('COUNT-001');

        $this->assertSame(3, Station::withCount('parameters')->sole()->parameters_count);
    }
}
