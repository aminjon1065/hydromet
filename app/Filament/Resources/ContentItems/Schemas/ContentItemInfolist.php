<?php

namespace App\Filament\Resources\ContentItems\Schemas;

use App\Domain\Content\Enums\ContentStatus;
use App\Domain\Content\Enums\ContentType;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContentItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('content.sections.publication'))
                ->columns(3)
                ->schema([
                    TextEntry::make('slug')->label(__('content.fields.slug')),
                    TextEntry::make('type')
                        ->label(__('content.fields.type'))
                        ->badge()
                        ->formatStateUsing(fn (ContentType $state): string => $state->label()),
                    TextEntry::make('status')
                        ->label(__('content.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (ContentStatus $state): string => $state->label()),
                    TextEntry::make('published_at')
                        ->label(__('content.fields.published_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone'))
                        ->placeholder(__('content.not_supplied')),
                    TextEntry::make('creator.name')
                        ->label(__('content.fields.created_by'))
                        ->placeholder(__('content.not_supplied')),
                    TextEntry::make('updater.name')
                        ->label(__('content.fields.updated_by'))
                        ->placeholder(__('content.not_supplied')),
                    TextEntry::make('publisher.name')
                        ->label(__('content.fields.published_by'))
                        ->placeholder(__('content.not_supplied')),
                    TextEntry::make('updated_at')
                        ->label(__('content.fields.updated_at'))
                        ->dateTime('d M Y, H:i', config('app.display_timezone')),
                ]),

            ...self::translationSections(),
        ]);
    }

    /**
     * @return array<int, Section>
     */
    private static function translationSections(): array
    {
        return array_map(
            static fn (string $locale): Section => Section::make(__('content.languages.'.$locale))
                ->schema([
                    TextEntry::make('title_'.$locale)
                        ->label(__('content.fields.title'))
                        ->placeholder(__('content.not_supplied')),
                    TextEntry::make('summary_'.$locale)
                        ->label(__('content.fields.summary'))
                        ->placeholder(__('content.not_supplied')),
                    TextEntry::make('body_'.$locale)
                        ->label(__('content.fields.body'))
                        ->placeholder(__('content.not_supplied')),
                ]),
            ['tj', 'ru', 'en'],
        );
    }
}
