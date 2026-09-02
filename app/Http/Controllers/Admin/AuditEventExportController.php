<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Queries\AuditEventExportRows;
use App\Domain\Audit\Services\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Requests\AuditEventExportRequest;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the immutable audit log to the administrator who asked for it.
 *
 * Taking a copy of the audit log is itself an administrative act, so it is
 * recorded in the log before anything is written to the client. That entry is
 * also the export's upper bound: every row already present when the request
 * arrived is included, and nothing written during or after the download is.
 */
class AuditEventExportController extends Controller
{
    public function __invoke(
        AuditEventExportRequest $request,
        AuditEventExportRows $rows,
        AuditRecorder $recorder,
    ): StreamedResponse {
        $requestedAt = CarbonImmutable::now('UTC');
        $from = $request->windowStart();
        $to = $request->windowEnd();
        $actor = $request->user();

        $marker = $recorder->record(
            action: 'audit_exported',
            subjectType: 'audit_log',
            subjectId: $requestedAt->format('Y-m-d\TH:i:s\Z'),
            changes: [
                'window' => [
                    'from' => $from?->format('Y-m-d\TH:i:s\Z'),
                    'to' => $to?->format('Y-m-d\TH:i:s\Z'),
                ],
            ],
            actorId: $actor === null ? null : (int) $actor->getAuthIdentifier(),
        );

        return response()->streamDownload(
            function () use ($rows, $marker, $from, $to): void {
                $output = fopen('php://output', 'wb');

                if ($output === false) {
                    return;
                }

                fputcsv($output, AuditEventExportRows::HEADER, ',', '"', '');

                foreach ($rows->get($marker->id, $from, $to) as $row) {
                    fputcsv($output, $row, ',', '"', '');
                }

                fclose($output);
            },
            'audit-events-'.$requestedAt->format('Ymd-His').'.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // Administrative evidence must not be held by a shared cache.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
