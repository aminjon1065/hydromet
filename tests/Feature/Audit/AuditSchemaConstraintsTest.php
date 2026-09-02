<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Content\Models\ContentItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRUNCATE only exists on PostgreSQL. SQLite has no equivalent statement, and
 * its truncate optimisation for an unqualified DELETE is disabled on a table
 * that carries row triggers, so the audit log is already covered there.
 */
class AuditSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function postgresql_rejects_truncating_the_audit_log(): void
    {
        $this->requirePostgres();
        ContentItem::factory()->create();

        $this->assertSame(1, AuditEvent::query()->count());
        $this->expectException(QueryException::class);

        DB::statement('TRUNCATE TABLE audit_events');
    }

    #[Test]
    public function postgresql_guards_the_audit_log_against_every_mutating_statement(): void
    {
        $this->requirePostgres();

        $events = DB::table('pg_trigger')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')
            ->where('pg_class.relname', 'audit_events')
            ->where('pg_trigger.tgisinternal', false)
            ->orderBy('pg_trigger.tgname')
            ->pluck('pg_trigger.tgname')
            ->all();

        $this->assertSame([
            'audit_events_reject_truncate',
            'audit_events_reject_update_or_delete',
        ], $events);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('TRUNCATE and its trigger guard exist on PostgreSQL only.');
        }
    }
}
