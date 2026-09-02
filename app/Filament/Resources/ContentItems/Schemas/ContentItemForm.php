<?php

namespace App\Filament\Resources\ContentItems\Schemas;

use App\Domain\Content\Enums\ContentStatus;
use App\Domain\Content\Enums\ContentType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ContentItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('content.sections.publication'))
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->label(__('content.fields.type'))
                        ->options(self::typeOptions())
                        ->required(),
                    TextInput::make('slug')
                        ->label(__('content.fields.slug'))
                        ->helperText(__('content.slug_help'))
                        ->maxLength(160)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(ignoreRecord: true)
                        ->required(),
                    Select::make('status')
                        ->label(__('content.fields.status'))
                        ->options(self::statusOptions())
                        ->default(ContentStatus::Draft->value)
                        ->live()
                        ->required(),
                    DateTimePicker::make('published_at')
                        ->label(__('content.fields.published_at'))
                        ->helperText(__('content.published_at_help'))
                        ->seconds(false)
                        ->timezone((string) config('app.display_timezone'))
                        ->required(fn (Get $get): bool => $get('status') === ContentStatus::Published->value),
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
                    TextInput::make('title_'.$locale)
                        ->label(__('content.fields.title'))
                        ->maxLength(255)
                        ->required(fn (Get $get): bool => $get('status') === ContentStatus::Published->value),
                    Textarea::make('summary_'.$locale)
                        ->label(__('content.fields.summary'))
                        ->rows(3),
                    Textarea::make('body_'.$locale)
                        ->label(__('content.fields.body'))
                        ->helperText(__('content.body_help'))
                        ->rows(12)
                        ->required(fn (Get $get): bool => $get('status') === ContentStatus::Published->value),
                ]),
            ['tj', 'ru', 'en'],
        );
    }

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        return array_column(array_map(
            static fn (ContentType $type): array => [$type->value, $type->label()],
            ContentType::cases(),
        ), 1, 0);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return array_column(array_map(
            static fn (ContentStatus $status): array => [$status->value, $status->label()],
            ContentStatus::cases(),
        ), 1, 0);
    }
}
