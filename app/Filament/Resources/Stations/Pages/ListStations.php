<?php

namespace App\Filament\Resources\Stations\Pages;

use App\Filament\Resources\Stations\StationResource;
use Filament\Resources\Pages\ListRecords;

class ListStations extends ListRecords
{
    protected static string $resource = StationResource::class;

    /**
     * No create action: the registry is written by the import service only.
     *
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
