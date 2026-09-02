<?php

namespace Tests\Feature\Security;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Panel-wide access rules, asserted over the registered routes rather than over
 * a hand-written list.
 *
 * Each resource already has its own role tests. What those cannot catch is a
 * resource added later whose author forgets to write them: this sweep fails the
 * moment any panel route becomes reachable without an active account.
 */
class PanelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Panel routes a guest is expected to reach.
     *
     * `logout` is deliberately open: it ends a session, and a guest posting to
     * it achieves nothing.
     */
    private const PUBLIC_PANEL_PATHS = ['admin/login', 'admin/logout'];

    /**
     * @return array<int, string>
     */
    private function guardedPanelPaths(): array
    {
        $paths = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'admin')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($uri, self::PUBLIC_PANEL_PATHS, true)) {
                continue;
            }

            // A record page needs an existing row to be reachable at all, so a
            // redirect from it would prove nothing about authorization. The
            // per-resource tests cover those with real records.
            if (str_contains($uri, '{')) {
                continue;
            }

            $paths[$uri] = $uri;
        }

        return array_values($paths);
    }

    #[Test]
    public function the_sweep_actually_covers_the_panel(): void
    {
        // Guards the guard: a matching bug that silently found nothing would
        // otherwise let both tests below pass while asserting nothing at all.
        $this->assertGreaterThanOrEqual(7, count($this->guardedPanelPaths()));
    }

    #[Test]
    public function no_panel_page_is_reachable_by_a_guest(): void
    {
        foreach ($this->guardedPanelPaths() as $path) {
            $this->get('/'.$path)->assertRedirect('/admin/login');
        }
    }

    #[Test]
    public function no_panel_page_is_reachable_by_a_deactivated_user(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]);

        foreach ($this->guardedPanelPaths() as $path) {
            $this->actingAs($user)->get('/'.$path)->assertForbidden();
        }
    }

    /**
     * Public read models must not become an unauthenticated window onto the
     * panel: nothing under `/api` may require or accept a panel session.
     */
    #[Test]
    public function the_public_api_never_reveals_a_panel_route(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $this->assertNotContains(
                'auth',
                $route->gatherMiddleware(),
                "The public API route [{$route->uri()}] is behind a session guard.",
            );
        }
    }
}
