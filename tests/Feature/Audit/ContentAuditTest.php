<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentAuditTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function content_creation_records_an_immutable_snapshot_and_actor(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $content = ContentItem::factory()->create([
            'slug' => 'audited-content',
            'created_by' => $editor->id,
            'updated_by' => $editor->id,
        ]);

        $event = AuditEvent::query()->sole();

        $this->assertSame('content.created', $event->action);
        $this->assertSame('content_item', $event->subject_type);
        $this->assertSame((string) $content->id, $event->subject_id);
        $this->assertSame('audited-content', $event->subject_label);
        $this->assertSame($editor->id, $event->actor_id);
        $this->assertSame([], (array) $event->changes['before']);
        $this->assertSame('audited-content', $event->changes['after']['slug']);
        $this->assertContains('body_tj', $event->changes['fields']);
    }

    #[Test]
    public function content_updates_record_only_changed_business_fields_with_before_and_after_values(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $content = ContentItem::factory()->create([
            'title_ru' => 'До изменения',
            'created_by' => $editor->id,
            'updated_by' => $editor->id,
        ]);

        $content->update([
            'title_ru' => 'После изменения',
            'updated_by' => $administrator->id,
        ]);

        $event = AuditEvent::query()->where('action', 'content.updated')->sole();

        $this->assertSame($administrator->id, $event->actor_id);
        $this->assertSame(['title_ru'], $event->changes['fields']);
        $this->assertSame('До изменения', $event->changes['before']['title_ru']);
        $this->assertSame('После изменения', $event->changes['after']['title_ru']);
    }

    #[Test]
    public function an_unauthenticated_update_is_never_attributed_to_the_previous_editor(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $content = ContentItem::factory()->create([
            'created_by' => $editor->id,
            'updated_by' => $editor->id,
        ]);

        // Console-style write: no session, and nothing refreshes `updated_by`.
        $content->update(['title_ru' => 'Изменено сценарием']);

        $event = AuditEvent::query()->where('action', 'content.updated')->sole();

        $this->assertNull($event->actor_id);
        $this->assertSame(['title_ru'], $event->changes['fields']);
    }

    #[Test]
    public function the_signed_in_user_outranks_a_stale_provenance_column(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $content = ContentItem::factory()->create([
            'created_by' => $editor->id,
            'updated_by' => $editor->id,
        ]);

        $this->actingAs($administrator);
        $content->update(['title_ru' => 'Изменено администратором']);

        $event = AuditEvent::query()->where('action', 'content.updated')->sole();

        $this->assertSame($administrator->id, $event->actor_id);
    }

    #[Test]
    public function creation_falls_back_to_the_provenance_column_without_a_session(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);

        ContentItem::factory()->create(['slug' => 'no-session', 'created_by' => $editor->id]);
        $this->actingAs($administrator);
        ContentItem::factory()->create(['slug' => 'with-session', 'created_by' => $editor->id]);

        $this->assertSame(
            $editor->id,
            AuditEvent::query()->where('subject_label', 'no-session')->sole()->actor_id,
        );
        $this->assertSame(
            $administrator->id,
            AuditEvent::query()->where('subject_label', 'with-session')->sole()->actor_id,
        );
    }

    #[Test]
    public function eloquent_refuses_to_change_or_delete_an_audit_event(): void
    {
        ContentItem::factory()->create();
        $event = AuditEvent::query()->sole();

        try {
            $event->update(['subject_label' => 'tampered']);
            $this->fail('An audit event was changed through Eloquent.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit events are immutable.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    #[Test]
    public function the_database_rejects_direct_audit_updates(): void
    {
        ContentItem::factory()->create();
        $event = AuditEvent::query()->sole();

        $this->expectException(QueryException::class);

        DB::table('audit_events')->where('id', $event->id)->update(['subject_label' => 'tampered']);
    }

    #[Test]
    public function the_database_rejects_direct_audit_deletes(): void
    {
        ContentItem::factory()->create();
        $event = AuditEvent::query()->sole();

        $this->expectException(QueryException::class);

        DB::table('audit_events')->where('id', $event->id)->delete();
    }

    #[Test]
    public function an_actor_with_audit_history_must_be_deactivated_instead_of_deleted(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        ContentItem::factory()->create(['created_by' => $editor->id]);

        $this->expectException(QueryException::class);

        $editor->delete();
    }
}
