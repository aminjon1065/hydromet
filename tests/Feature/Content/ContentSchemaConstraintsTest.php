<?php

namespace Tests\Feature\Content;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Publication completeness is enforced on both drivers, so those tests run
 * everywhere. The CHECK constraints that SQLite cannot express are asserted on
 * PostgreSQL only.
 */
class ContentSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function postgresql_rejects_an_unknown_content_type(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        DB::table('content_items')->insert($this->rawContent(['type' => 'advertisement']));
    }

    #[Test]
    public function postgresql_rejects_an_unknown_publication_status(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        DB::table('content_items')->insert($this->rawContent(['status' => 'archived']));
    }

    #[Test]
    public function postgresql_rejects_a_noncanonical_slug(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        DB::table('content_items')->insert($this->rawContent(['slug' => 'Invalid Slug']));
    }

    #[Test]
    public function postgresql_stores_publication_time_with_a_time_zone(): void
    {
        $this->requirePostgres();

        $type = DB::selectOne(<<<'SQL'
            SELECT data_type FROM information_schema.columns
            WHERE table_name = 'content_items' AND column_name = 'published_at'
        SQL);

        $this->assertNotNull($type);
        $this->assertSame('timestamp with time zone', $type->data_type);
    }

    #[Test]
    public function the_database_accepts_a_complete_publication(): void
    {
        DB::table('content_items')->insert($this->rawPublication(['slug' => 'complete-publication']));

        $this->assertDatabaseHas('content_items', ['slug' => 'complete-publication']);
    }

    #[Test]
    public function the_database_still_accepts_an_incomplete_draft(): void
    {
        DB::table('content_items')->insert($this->rawContent([
            'slug' => 'incomplete-draft',
            'title_ru' => null,
            'body_en' => null,
        ]));

        $this->assertDatabaseHas('content_items', ['slug' => 'incomplete-draft']);
    }

    #[Test]
    public function the_database_rejects_publishing_without_a_publication_time(): void
    {
        $this->expectException(QueryException::class);

        DB::table('content_items')->insert($this->rawPublication(['published_at' => null]));
    }

    #[Test]
    public function the_database_rejects_publishing_with_a_missing_translation(): void
    {
        $this->expectException(QueryException::class);

        DB::table('content_items')->insert($this->rawPublication(['title_en' => null]));
    }

    #[Test]
    public function the_database_rejects_publishing_with_a_whitespace_only_translation(): void
    {
        $this->expectException(QueryException::class);

        DB::table('content_items')->insert($this->rawPublication(['body_tj' => "  \n\t "]));
    }

    #[Test]
    public function the_database_rejects_publishing_an_incomplete_draft_through_an_update(): void
    {
        DB::table('content_items')->insert($this->rawContent([
            'slug' => 'promoted-draft',
            'title_tj' => 'Сарлавҳа',
            'title_ru' => 'Заголовок',
            'title_en' => 'Title',
            'body_tj' => 'Матн',
            'body_ru' => null,
            'body_en' => 'Body',
        ]));

        $this->expectException(QueryException::class);

        DB::table('content_items')->where('slug', 'promoted-draft')->update([
            'status' => 'published',
            'published_at' => '2026-09-01 06:00:00+00',
        ]);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CMS CHECK constraints and timestamptz storage are verified on PostgreSQL only.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawContent(array $overrides): array
    {
        return [
            'type' => 'page',
            'slug' => 'constraint-check',
            'status' => 'draft',
            'created_at' => '2026-09-01 06:00:00+00',
            'updated_at' => '2026-09-01 06:00:00+00',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawPublication(array $overrides = []): array
    {
        return $this->rawContent([
            'status' => 'published',
            'published_at' => '2026-09-01 06:00:00+00',
            'title_tj' => 'Сарлавҳаи санҷишӣ',
            'title_ru' => 'Тестовый заголовок',
            'title_en' => 'Test title',
            'body_tj' => 'Матни санҷишӣ',
            'body_ru' => 'Тестовый текст',
            'body_en' => 'Test body',
            ...$overrides,
        ]);
    }
}
