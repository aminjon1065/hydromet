<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\AuditEvents\AuditEventLabels;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditAdminResourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_administrator_can_list_and_view_audit_events(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        ContentItem::factory()->create(['created_by' => $administrator->id]);
        $event = AuditEvent::query()->sole();

        $this->actingAs($administrator)->get('/admin/audit-events')->assertOk();
        $this->actingAs($administrator)->get("/admin/audit-events/{$event->id}")->assertOk();
        $this->assertTrue(AuditEventResource::canViewAny());
        $this->assertTrue(AuditEventResource::canView($event));
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function nonAdministratorRoles(): array
    {
        return [
            'operator' => [UserRole::Operator],
            'editor' => [UserRole::Editor],
        ];
    }

    #[Test]
    #[DataProvider('nonAdministratorRoles')]
    public function non_administrator_roles_cannot_read_the_audit_log(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        ContentItem::factory()->create();
        $event = AuditEvent::query()->sole();

        $this->actingAs($user)->get('/admin/audit-events')->assertForbidden();
        $this->actingAs($user)->get("/admin/audit-events/{$event->id}")->assertForbidden();
        $this->assertFalse(AuditEventResource::canViewAny());
        $this->assertFalse(AuditEventResource::canView($event));
    }

    #[Test]
    public function an_untranslated_action_reads_as_its_stable_code_and_stays_filterable(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        ContentItem::factory()->create(['created_by' => $administrator->id]);
        app(AuditRecorder::class)->record(
            action: 'measurement.corrected',
            subjectType: 'measurement',
            subjectId: 42,
            changes: ['fields' => ['value'], 'before' => ['value' => 1.0], 'after' => ['value' => 2.0]],
        );

        $this->assertSame('measurement.corrected', AuditEventLabels::action('measurement.corrected'));
        $this->assertSame('measurement', AuditEventLabels::subjectType('measurement'));
        $this->assertSame(__('audit.actions.content_created'), AuditEventLabels::action('content.created'));
        $this->assertSame([
            'content.created' => __('audit.actions.content_created'),
            'measurement.corrected' => 'measurement.corrected',
        ], AuditEventLabels::actionOptions());
        $this->assertSame([
            'content_item' => __('audit.subject_types.content_item'),
            'measurement' => 'measurement',
        ], AuditEventLabels::subjectTypeOptions());

        $this->actingAs($administrator);

        Livewire::test(ListAuditEvents::class)
            ->assertSee('measurement.corrected')
            ->assertDontSee('audit.actions.')
            ->assertDontSee('audit.subject_types.');
    }

    #[Test]
    public function the_audit_resource_has_no_mutating_routes_or_abilities(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        ContentItem::factory()->create(['created_by' => $administrator->id]);
        $event = AuditEvent::query()->sole();
        $this->actingAs($administrator);

        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter(fn (string $name): bool => str_contains($name, 'resources.audit-events.'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'filament.admin.resources.audit-events.index',
            'filament.admin.resources.audit-events.view',
        ], $names);
        $this->assertFalse(AuditEventResource::canCreate());
        $this->assertFalse(AuditEventResource::canEdit($event));
        $this->assertFalse(AuditEventResource::canDelete($event));
        $this->assertFalse(AuditEventResource::canDeleteAny());
        $this->assertFalse(AuditEventResource::canReplicate($event));
    }
}
