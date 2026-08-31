<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected_to_the_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    #[Test]
    public function the_panel_login_page_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    #[Test]
    #[DataProvider('activeRoles')]
    public function active_users_reach_the_dashboard(UserRole $role): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

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
    public function deactivated_users_are_denied_even_after_authenticating(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    #[Test]
    public function the_public_home_page_stays_reachable_without_authentication(): void
    {
        $this->get('/')->assertOk();
    }
}
