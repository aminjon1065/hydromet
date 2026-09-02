<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewContentItem extends ViewRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = ContentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
