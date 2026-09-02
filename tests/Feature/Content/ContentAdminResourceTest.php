<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Enums\ContentStatus;
use App\Domain\Content\Enums\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\ContentItems\Pages\CreateContentItem;
use App\Filament\Resources\ContentItems\Pages\EditContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentAdminResourceTest extends TestCase
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
    public function every_active_panel_role_can_list_and_view_content(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $content = ContentItem::factory()->create(['slug' => 'role-visible-content']);

        $this->actingAs($user)->get('/admin/content-items')->assertOk();
        $this->actingAs($user)->get("/admin/content-items/{$content->id}")->assertOk();
    }

    #[Test]
    #[DataProvider('managerRoles')]
    public function administrators_and_editors_can_open_create_and_edit_pages(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $content = ContentItem::factory()->create();

        $this->actingAs($user)->get('/admin/content-items/create')->assertOk();
        $this->actingAs($user)->get("/admin/content-items/{$content->id}/edit")->assertOk();
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function managerRoles(): array
    {
        return [
            'administrator' => [UserRole::Administrator],
            'editor' => [UserRole::Editor],
        ];
    }

    #[Test]
    public function an_operator_cannot_create_or_edit_content(): void
    {
        $operator = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        $content = ContentItem::factory()->create();

        $this->actingAs($operator)->get('/admin/content-items/create')->assertForbidden();
        $this->actingAs($operator)->get("/admin/content-items/{$content->id}/edit")->assertForbidden();
        $this->assertFalse(ContentItemResource::canCreate());
        $this->assertFalse(ContentItemResource::canEdit($content));
    }

    #[Test]
    public function a_deactivated_user_cannot_reach_content_management(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]);

        $this->actingAs($user)->get('/admin/content-items')->assertForbidden();
    }

    #[Test]
    public function the_content_list_tolerates_a_draft_without_the_active_translation(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);
        ContentItem::factory()->create(['slug' => 'incomplete-draft', 'title_ru' => null]);

        $this->actingAs($editor)
            ->withSession(['locale' => 'ru'])
            ->get('/admin/content-items')
            ->assertOk()
            ->assertSee('incomplete-draft');
    }

    #[Test]
    public function hard_delete_routes_and_actions_do_not_exist(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $content = ContentItem::factory()->create();
        $this->actingAs($administrator);

        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter(fn (string $name): bool => str_contains($name, 'resources.content-items.'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'filament.admin.resources.content-items.create',
            'filament.admin.resources.content-items.edit',
            'filament.admin.resources.content-items.index',
            'filament.admin.resources.content-items.view',
        ], $names);
        $this->assertFalse(ContentItemResource::canDelete($content));
        $this->assertFalse(ContentItemResource::canDeleteAny());
        $this->assertFalse(ContentItemResource::canReplicate($content));
    }

    #[Test]
    public function create_and_edit_record_the_responsible_user(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $this->actingAs($editor);

        Livewire::test(CreateContentItem::class)
            ->fillForm($this->completeFormData('cms-provenance'))
            ->call('create')
            ->assertHasNoFormErrors();

        $content = ContentItem::query()->where('slug', 'cms-provenance')->sole();
        $this->assertSame($editor->id, $content->created_by);
        $this->assertSame($editor->id, $content->updated_by);
        $this->assertSame($editor->id, $content->published_by);

        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($administrator);

        Livewire::test(EditContentItem::class, ['record' => $content->id])
            ->fillForm(['title_en' => 'Updated English title'])
            ->call('save')
            ->assertHasNoFormErrors();

        $content->refresh();
        $this->assertSame('Updated English title', $content->title_en);
        $this->assertSame($administrator->id, $content->updated_by);
        $this->assertSame($editor->id, $content->published_by);
    }

    #[Test]
    public function the_form_rejects_incomplete_publication(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $this->actingAs($editor);
        $data = $this->completeFormData('incomplete-publication');
        $data['body_tj'] = null;

        Livewire::test(CreateContentItem::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasFormErrors(['body_tj' => 'required']);

        $this->assertDatabaseMissing('content_items', ['slug' => 'incomplete-publication']);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeFormData(string $slug): array
    {
        return [
            'type' => ContentType::Page->value,
            'slug' => $slug,
            'title_tj' => 'Сарлавҳаи санҷишӣ',
            'title_ru' => 'Тестовый заголовок',
            'title_en' => 'Test title',
            'summary_tj' => 'Шарҳ',
            'summary_ru' => 'Описание',
            'summary_en' => 'Summary',
            'body_tj' => 'Матни санҷишӣ',
            'body_ru' => 'Тестовый текст',
            'body_en' => 'Test body',
            'status' => ContentStatus::Published->value,
            'published_at' => '2026-09-01 12:00:00',
        ];
    }
}
