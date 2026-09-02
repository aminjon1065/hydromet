<?php

namespace App\Filament\Resources\IntegrationSources\Pages;

use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\IntegrationSources\IntegrationSourceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewIntegrationSource extends ViewRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = IntegrationSourceResource::class;

    /**
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
