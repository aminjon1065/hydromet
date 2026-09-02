<?php

namespace App\Filament\Resources\SynchronizationRuns\Pages;

use App\Filament\Resources\SynchronizationRuns\SynchronizationRunResource;
use Filament\Resources\Pages\ListRecords;

class ListSynchronizationRuns extends ListRecords
{
    protected static string $resource = SynchronizationRunResource::class;

    /**
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
