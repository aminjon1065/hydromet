<?php

namespace App\Http\Controllers;

use App\Domain\Content\Models\ContentItem;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rendered page depends on the locale the visitor chose, which
 * `SetLocale` reads from the session, and on the session-derived props Inertia
 * shares. A shared cache cannot key on that, so the response is private.
 */
class ContentController extends Controller
{
    public function __invoke(string $slug): Response
    {
        $content = ContentItem::query()
            ->publiclyVisible()
            ->where('slug', $slug)
            ->firstOrFail();

        return Inertia::render('content/show', [
            'content' => [
                'slug' => $content->slug,
                'type' => $content->type->value,
                'title' => $content->localizedTitle(),
                'summary' => $content->localizedSummary(),
                'body' => $content->localizedBody(),
                'publishedAt' => $content->published_at?->utc()->toIso8601String(),
            ],
            'labels' => [
                'content' => __('site.content_label'),
                'publishedAt' => __('site.content_published_at'),
            ],
        ])->toResponse(request())->setCache([
            'private' => true,
            'max_age' => 300,
        ]);
    }
}
