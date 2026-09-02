<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Domain\Content\Enums\ContentStatus;
use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContentItem extends CreateRecord
{
    protected static string $resource = ContentItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $userId = auth()->id();
        $data['created_by'] = $userId;
        $data['updated_by'] = $userId;

        if (($data['status'] ?? null) === ContentStatus::Published->value) {
            $data['published_by'] = $userId;
        }

        return $data;
    }
}
