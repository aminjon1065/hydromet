<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Domain\Content\Enums\ContentStatus;
use App\Domain\Content\Models\ContentItem;
use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Resources\Pages\EditRecord;

class EditContentItem extends EditRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = ContentItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof ContentItem) {
            throw new \LogicException('The content edit page received an unexpected model.');
        }

        $data['updated_by'] = auth()->id();

        if (($data['status'] ?? null) === ContentStatus::Published->value
            && ($record->published_by === null
                || $record->status !== ContentStatus::Published)) {
            $data['published_by'] = auth()->id();
        }

        return $data;
    }

    /** @return array<int, never> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
