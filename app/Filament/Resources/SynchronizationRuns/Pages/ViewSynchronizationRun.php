<?php

namespace App\Filament\Resources\SynchronizationRuns\Pages;

use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\SynchronizationRuns\SynchronizationRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSynchronizationRun extends ViewRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = SynchronizationRunResource::class;

    /**
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
