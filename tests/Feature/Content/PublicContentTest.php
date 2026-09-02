<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Enums\ContentStatus;
use App\Domain\Content\Exceptions\MissingContentTranslation;
use App\Domain\Content\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicContentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_published_item_is_localized_on_the_web_and_api(): void
    {
        $content = ContentItem::factory()->published()->create([
            'slug' => 'air-quality-guide',
            'title_tj' => 'Роҳнамои озмоишӣ',
            'title_ru' => 'Тестовое руководство',
            'title_en' => 'Test guide',
            'summary_tj' => 'Шарҳи тоҷикӣ',
            'body_tj' => "Матни тоҷикӣ\nБо сатри дуюм.",
        ]);

        // The chosen locale lives in the session, so the page must never be
        // stored by a shared cache.
        $this->withSession(['locale' => 'tj'])
            ->get('/content/air-quality-guide')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, private')
            ->assertHeader('Content-Language', 'tg-TJ')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('content/show')
                ->where('content.slug', 'air-quality-guide')
                ->where('content.title', 'Роҳнамои озмоишӣ')
                ->where('content.summary', 'Шарҳи тоҷикӣ')
                ->where('content.body', "Матни тоҷикӣ\nБо сатри дуюм."));

        $this->withHeader('Accept-Language', 'en-GB')
            ->getJson('/api/v1/content/air-quality-guide')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertHeader('Vary', 'Accept-Language')
            ->assertHeader('Content-Language', 'en-GB')
            ->assertJsonPath('data.slug', $content->slug)
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.title', 'Test guide')
            ->assertJsonPath('data.body', 'This is test content, not Hydromet information.');
    }

    #[Test]
    public function the_session_localized_page_is_never_shared_cacheable(): void
    {
        ContentItem::factory()->published()->create(['slug' => 'cache-scope']);

        $tajik = $this->withSession(['locale' => 'tj'])->get('/content/cache-scope');
        $russian = $this->withSession(['locale' => 'ru'])->get('/content/cache-scope');

        foreach ([$tajik, $russian] as $response) {
            $cacheControl = (string) $response->headers->get('Cache-Control');

            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringNotContainsString('public', $cacheControl);
        }

        $this->assertNotSame(
            $tajik->headers->get('Content-Language'),
            $russian->headers->get('Content-Language'),
        );
    }

    #[Test]
    public function drafts_and_future_publications_are_not_public(): void
    {
        Carbon::setTestNow('2026-09-01T10:00:00Z');

        $draft = ContentItem::factory()->create(['slug' => 'draft-page']);
        $scheduled = ContentItem::factory()->scheduled()->create(['slug' => 'future-page']);

        foreach ([$draft, $scheduled] as $content) {
            $this->get('/content/'.$content->slug)->assertNotFound();
            $this->getJson('/api/v1/content/'.$content->slug)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found')
                ->assertJsonStructure(['error' => ['request_id']]);
        }
    }

    #[Test]
    public function publication_is_blocked_when_a_required_translation_or_date_is_missing(): void
    {
        $this->expectException(ValidationException::class);

        ContentItem::factory()->create([
            'status' => ContentStatus::Published,
            'published_at' => null,
            'title_tj' => '   ',
            'body_en' => null,
        ]);
    }

    #[Test]
    public function a_complete_draft_can_be_published_but_an_incomplete_one_cannot(): void
    {
        $complete = ContentItem::factory()->create();
        $complete->update([
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);

        $this->assertSame(ContentStatus::Published, $complete->fresh()->status);

        $incomplete = ContentItem::factory()->create(['body_tj' => null]);

        try {
            $incomplete->update([
                'status' => ContentStatus::Published,
                'published_at' => now(),
            ]);
            $this->fail('An incomplete translation was published.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('body_tj', $exception->errors());
        }

        $this->assertSame(ContentStatus::Draft, $incomplete->fresh()->status);
    }

    #[Test]
    public function a_missing_required_translation_fails_loudly_instead_of_publishing_a_blank(): void
    {
        // Built in memory only. The publication-completeness constraint makes
        // this state unreachable through any supported write, and this test must
        // not weaken that invariant to reach the reader.
        $content = ContentItem::factory()->published()->make([
            'slug' => 'impossible-state',
            'summary_ru' => null,
            'body_ru' => null,
        ]);

        App::setLocale('ru');

        // An optional field still degrades quietly; a required one must not.
        $this->assertNull($content->localizedSummary());
        $this->assertNotSame('', $content->localizedTitle());

        $this->expectException(MissingContentTranslation::class);
        $this->expectExceptionMessage('Content item [impossible-state] has no body for locale [ru].');

        $content->localizedBody();
    }

    #[Test]
    public function malformed_slugs_do_not_fall_through_to_the_controller(): void
    {
        $this->getJson('/api/v1/content/NOT_VALID')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }
}
