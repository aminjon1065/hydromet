<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;

    /**
     * The only header action is a download. Nothing here may create, edit or
     * delete an audit event; the resource denies those abilities outright.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('audit.export.action'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => route('filament.admin.exports.audit-events'))
                ->openUrlInNewTab(),
        ];
    }
}
