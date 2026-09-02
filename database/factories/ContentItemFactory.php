<?php

namespace Database\Factories;

use App\Domain\Content\Enums\ContentStatus;
use App\Domain\Content\Enums\ContentType;
use App\Domain\Content\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Test-only content. Nothing generated here is approved Hydromet copy.
 *
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $suffix = Str::lower(Str::random(8));

        return [
            'type' => ContentType::Page,
            'slug' => 'test-page-'.$suffix,
            'title_tj' => 'Саҳифаи озмоишӣ '.$suffix,
            'title_ru' => 'Тестовая страница '.$suffix,
            'title_en' => 'Test page '.$suffix,
            'summary_tj' => 'Матни сунъӣ барои санҷиш.',
            'summary_ru' => 'Искусственный текст для теста.',
            'summary_en' => 'Synthetic text for a test.',
            'body_tj' => 'Ин маводи озмоишӣ аст ва маълумоти Гидромет нест.',
            'body_ru' => 'Это тестовый материал, а не информация Гидромета.',
            'body_en' => 'This is test content, not Hydromet information.',
            'status' => ContentStatus::Draft,
            'published_at' => null,
            'created_by' => null,
            'updated_by' => null,
            'published_by' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContentStatus::Published,
            'published_at' => Carbon::parse('2026-08-31T06:00:00Z'),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContentStatus::Published,
            'published_at' => now()->addDay(),
        ]);
    }
}
