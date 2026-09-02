<?php

/* Provisional CMS terminology pending Hydromet's language approval. */

return [
    'navigation_group' => 'Publication',
    'item' => 'Content item',
    'items' => 'Content',
    'sections' => [
        'publication' => 'Publication',
    ],
    'fields' => [
        'type' => 'Type',
        'slug' => 'URL slug',
        'title' => 'Title',
        'summary' => 'Summary',
        'body' => 'Body',
        'status' => 'Status',
        'published_at' => 'Publish at',
        'created_by' => 'Created by',
        'updated_by' => 'Last edited by',
        'published_by' => 'Published by',
        'updated_at' => 'Last changed',
    ],
    'types' => [
        'page' => 'Static page',
        'news' => 'News',
        'bulletin' => 'Bulletin',
        'health_advice' => 'Health advice',
    ],
    'statuses' => [
        'draft' => 'Draft',
        'published' => 'Published',
    ],
    'languages' => [
        'tj' => 'Тоҷикӣ (tj)',
        'ru' => 'Русский (ru)',
        'en' => 'English (en)',
    ],
    'slug_help' => 'Lowercase Latin letters, numbers and hyphens only.',
    'published_at_help' => 'A future time schedules publication. Public timestamps use the portal timezone.',
    'body_help' => 'Plain text only; HTML is not rendered.',
    'not_supplied' => 'Not supplied',
    'validation' => [
        'required_for_publication' => 'This translation is required for publication.',
        'published_at_required' => 'A publication time is required for publication.',
    ],
];
