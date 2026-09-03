<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may reach account administration, and what the panel refuses to offer.
 *
 * The interesting half is the negative one: an operator must not merely lack a
 * navigation entry, they must be refused at the URL. A hidden button is a
 * courtesy; the check behind it is the control.
 */
class UserAdminResourceTest extends TestCase
{
    use RefreshDatabase;

    // --- Access ------------------------------------------------------------

    #[Test]
    public function a_guest_is_sent_to_the_panel_login(): void
    {
        $this->get('/admin/users')->assertRedirect('/admin/login');
    }

    #[Test]
    public function an_active_administrator_reaches_every_page(): void
    {
        $administrator = $this->administrator();
        $account = User::factory()->create(['role' => UserRole::Editor]);

        $this->actingAs($administrator)->get('/admin/users')->assertOk();
        $this->actingAs($administrator)->get('/admin/users/create')->assertOk();
        $this->actingAs($administrator)->get("/admin/users/{$account->id}")->assertOk();
        $this->actingAs($administrator)->get("/admin/users/{$account->id}/edit")->assertOk();

        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(UserResource::canCreate());
        $this->assertTrue(UserResource::canEdit($account));
    }

    /**
     * @return array<string, array{UserRole, bool}>
     */
    public static function callersWithoutAccess(): array
    {
        return [
            'operator' => [UserRole::Operator, true],
            'editor' => [UserRole::Editor, true],
            'deactivated administrator' => [UserRole::Administrator, false],
        ];
    }

    #[Test]
    #[DataProvider('callersWithoutAccess')]
    public function every_page_is_refused_at_the_url(UserRole $role, bool $isActive): void
    {
        $actor = User::factory()->create(['role' => $role, 'is_active' => $isActive]);
        $account = User::factory()->create(['role' => UserRole::Editor]);

        foreach ([
            '/admin/users',
            '/admin/users/create',
            "/admin/users/{$account->id}",
            "/admin/users/{$account->id}/edit",
        ] as $path) {
            $response = $this->actingAs($actor)->get($path);

            // A deactivated account is refused by the panel itself; an active
            // one with the wrong role is refused by the resource. Either way
            // the page is not served.
            $this->assertContains(
                $response->getStatusCode(),
                [403, 302],
                "[{$path}] was reachable by a {$role->value}.",
            );
            $this->assertNotSame(200, $response->getStatusCode());
        }
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function rolesWithoutManagement(): array
    {
        return [
            'operator' => [UserRole::Operator],
            'editor' => [UserRole::Editor],
        ];
    }

    /**
     * Asserted through the panel's own navigation decision rather than by
     * searching the rendered sidebar: the decision is what hides the entry, and
     * a text search would pass or fail on markup and translation instead.
     */
    #[Test]
    #[DataProvider('rolesWithoutManagement')]
    public function the_resource_is_absent_from_navigation(UserRole $role): void
    {
        $actor = User::factory()->create(['role' => $role, 'is_active' => true]);

        $this->actingAs($actor);

        $this->assertFalse(UserResource::shouldRegisterNavigation());
        $this->assertFalse(UserResource::canViewAny());
        $this->assertNotContains(
            UserResource::class,
            $this->navigableResources(),
        );
    }

    #[Test]
    public function a_deactivated_administrator_sees_no_navigation_entry_either(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]));

        $this->assertFalse(UserResource::shouldRegisterNavigation());
        $this->assertNotContains(UserResource::class, $this->navigableResources());
    }

    #[Test]
    public function the_navigation_entry_is_present_for_an_administrator(): void
    {
        $this->actingAs($this->administrator());

        $this->assertTrue(UserResource::shouldRegisterNavigation());
        $this->assertContains(UserResource::class, $this->navigableResources());
    }

    // --- Deletion is unavailable -------------------------------------------

    #[Test]
    public function no_deletion_ability_is_granted(): void
    {
        $account = User::factory()->create();

        $this->actingAs($this->administrator());

        $this->assertFalse(UserResource::canDelete($account));
        $this->assertFalse(UserResource::canDeleteAny());
        $this->assertFalse(UserResource::canForceDelete($account));
        $this->assertFalse(UserResource::canForceDeleteAny());
        $this->assertFalse(UserResource::canRestore($account));
        $this->assertFalse(UserResource::canRestoreAny());
        $this->assertFalse(UserResource::canReplicate($account));
        $this->assertFalse(UserResource::canReorder());
    }

    #[Test]
    public function only_the_four_read_and_write_pages_are_registered(): void
    {
        $this->assertSame(
            ['index', 'create', 'view', 'edit'],
            array_keys(UserResource::getPages()),
        );

        $this->assertSame([
            ListUsers::class,
            CreateUser::class,
            ViewUser::class,
            EditUser::class,
        ], array_map(
            static fn (object $page): string => $page->getPage(),
            array_values(UserResource::getPages()),
        ));
    }

    #[Test]
    public function no_delete_route_exists_under_the_resource(): void
    {
        $paths = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'admin/users')) {
                $paths[] = $route->uri();
            }
        }

        sort($paths);

        $this->assertSame([
            'admin/users',
            'admin/users/create',
            'admin/users/{record}',
            'admin/users/{record}/edit',
        ], $paths);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('delete', $path);
            $this->assertStringNotContainsString('restore', $path);
        }
    }

    #[Test]
    public function deleting_an_account_through_the_model_is_refused(): void
    {
        $account = User::factory()->create(['role' => UserRole::Editor]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('never deleted');

        $account->delete();
    }

    #[Test]
    public function deleting_an_account_by_a_statement_that_loads_no_model_is_refused(): void
    {
        $account = User::factory()->create(['role' => UserRole::Editor]);

        $this->assertRefused(
            static fn () => DB::table('users')->where('id', $account->id)->delete(),
            'The database accepted a delete on the users table.',
        );

        $this->assertDatabaseHas('users', ['id' => $account->id]);
    }

    /**
     * The statement that empties a table fastest needs its own guard: an
     * unqualified DELETE on SQLite, and TRUNCATE on PostgreSQL, which row
     * triggers never see.
     */
    #[Test]
    public function the_users_table_cannot_be_emptied_wholesale(): void
    {
        User::factory()->count(2)->create();

        $postgres = DB::connection()->getDriverName() === 'pgsql';

        $this->assertRefused(
            static fn () => $postgres
                ? DB::statement('TRUNCATE TABLE users CASCADE')
                : DB::table('users')->delete(),
            'The database emptied the users table.',
        );

        $this->assertGreaterThan(0, DB::table('users')->count());
    }

    #[Test]
    public function postgresql_refuses_a_role_outside_the_portal_vocabulary(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite cannot add a table constraint after creation.');
        }

        $account = User::factory()->create(['role' => UserRole::Editor]);

        $this->assertRefused(
            static fn () => DB::table('users')->where('id', $account->id)->update(['role' => 'superuser']),
            'The database accepted a role the portal does not define.',
        );

        $this->assertSame(UserRole::Editor, $account->fresh()?->role);
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * PostgreSQL aborts the whole transaction on a failed statement, and
     * `RefreshDatabase` runs each test inside one. A nested transaction turns
     * the failing statement into a savepoint, which rolls back on its own and
     * leaves the rest of the test usable.
     *
     * @param  \Closure(): mixed  $statement
     */
    private function assertRefused(\Closure $statement, string $failure): void
    {
        try {
            DB::transaction(static function () use ($statement): void {
                $statement();
            });
        } catch (QueryException) {
            return;
        }

        $this->fail($failure);
    }

    /**
     * The panel's registered resources that currently offer a navigation
     * entry to whoever is signed in.
     *
     * @return array<int, string>
     */
    private function navigableResources(): array
    {
        return array_values(array_filter(
            Filament::getPanel('admin')->getResources(),
            /** @param class-string $resource */
            static fn (string $resource): bool => $resource::shouldRegisterNavigation(),
        ));
    }

    private function administrator(string $email = 'admin@example.tj'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);
    }
}
