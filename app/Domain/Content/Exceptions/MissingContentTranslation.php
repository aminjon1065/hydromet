<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

/**
 * A record is missing a translation that publication requires.
 *
 * `ContentItem::booted()` and the publication-completeness constraint added in
 * `2026_09_02_120006_add_content_items_publication_completeness_constraint`
 * make this unreachable through any supported write, so reaching it means the
 * invariant was bypassed. The reader fails loudly instead of publishing an empty
 * title or body, and no substitute language is invented:
 * docs/03-data-contracts.md section 9 forbids a fallback policy until Hydromet
 * approves one.
 */
final class MissingContentTranslation extends RuntimeException
{
    public static function for(string $slug, string $field, string $locale): self
    {
        return new self("Content item [{$slug}] has no {$field} for locale [{$locale}].");
    }
}
