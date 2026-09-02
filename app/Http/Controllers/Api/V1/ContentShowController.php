<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\Models\ContentItem;
use App\Http\Controllers\Controller;
use App\Support\Locale\SupportedLocale;
use Illuminate\Http\JsonResponse;

class ContentShowController extends Controller
{
    public function __invoke(string $slug): JsonResponse
    {
        $content = ContentItem::query()
            ->publiclyVisible()
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'slug' => $content->slug,
                'type' => $content->type->value,
                'language' => SupportedLocale::current()->value,
                'title' => $content->localizedTitle(),
                'summary' => $content->localizedSummary(),
                'body' => $content->localizedBody(),
                'published_at' => $content->published_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'updated_at' => $content->updated_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=300',
            'Vary' => 'Accept-Language',
        ]);
    }
}
