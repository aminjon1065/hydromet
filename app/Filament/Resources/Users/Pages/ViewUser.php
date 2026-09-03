<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = UserResource::class;

    /**
     * @return array<int, EditAction>
     */
    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
