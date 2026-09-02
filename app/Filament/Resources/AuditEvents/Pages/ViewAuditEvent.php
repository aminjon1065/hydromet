<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditEvent extends ViewRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = AuditEventResource::class;

    /** @return array<int, never> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
