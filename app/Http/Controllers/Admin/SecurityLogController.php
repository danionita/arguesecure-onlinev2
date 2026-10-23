<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityEventExport;
use App\Domain\Audit\SecurityLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SecurityLogRequest;
use App\Models\SecurityEventRecord;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin **Security log** tab (W128): the stored security event log, a
 * preview of the last 50 matching rows, and a CSV or JSON download. The
 * application log is deliberately not here (D96).
 */
class SecurityLogController extends Controller
{
    public function index(SecurityLogRequest $request): Response
    {
        $filters = $request->safe()->only(['from', 'to', 'event']);
        $query = SecurityEventExport::query($filters['from'] ?? null, $filters['to'] ?? null, $filters['event'] ?? null);

        return Inertia::render('Admin/SecurityLog', [
            'filters' => ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'event' => $filters['event'] ?? ''],
            'events' => [
                'families' => SecurityEventExport::prefixes(),
                'names' => SecurityEventExport::eventNames(),
            ],
            'rows' => $query->clone()->orderByDesc('id')->limit(50)->get()->map(fn (SecurityEventRecord $row): array => SecurityEventExport::jsonRow($row))->all(),
            'retentionDays' => (int) config('arguesecure.security_log_retention_days'),
            'maxRows' => SecurityEventExport::MAX_ROWS,
        ]);
    }

    public function download(SecurityLogRequest $request): StreamedResponse
    {
        $format = $request->validated('format');

        $filters = $request->safe()->only(['from', 'to', 'event']);
        $query = SecurityEventExport::query($filters['from'] ?? null, $filters['to'] ?? null, $filters['event'] ?? null);

        // A fixed id list is a consistent snapshot: rows written while the
        // stream runs are not half-included, and memory is bounded by the cap.
        $ids = $query->clone()->orderBy('id')->limit(SecurityEventExport::MAX_ROWS + 1)->pluck('id')->all();
        $truncated = count($ids) > SecurityEventExport::MAX_ROWS;
        $ids = array_slice($ids, 0, SecurityEventExport::MAX_ROWS);

        // Before the first byte, so a download that dies mid-way is recorded.
        SecurityLog::record(SecurityEvent::LogDownloaded, $request->user(), null, [
            'format' => $format,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'event' => $filters['event'] ?? null,
            'rows' => count($ids),
            'truncated' => $truncated,
        ]);

        $name = 'security-log-'.now()->utc()->format('Ymd-His').'.'.$format;
        $headers = [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Rows-Truncated' => $truncated ? '1' : '0',
        ];

        return response()->streamDownload(function () use ($ids, $format, $truncated): void {
            $out = fopen('php://output', 'w');
            $chunks = array_chunk($ids, 1000);

            if ($format === 'csv') {
                fputcsv($out, SecurityEventExport::COLUMNS, ',', '"', '\\');
                foreach ($chunks as $chunk) {
                    foreach (SecurityEventRecord::query()->whereIn('id', $chunk)->orderBy('id')->get() as $row) {
                        fputcsv($out, SecurityEventExport::csvRow($row), ',', '"', '\\');
                    }
                }
            } else {
                fwrite($out, '{"truncated":'.($truncated ? 'true' : 'false').',"events":[');
                $first = true;
                foreach ($chunks as $chunk) {
                    foreach (SecurityEventRecord::query()->whereIn('id', $chunk)->orderBy('id')->get() as $row) {
                        fwrite($out, ($first ? '' : ',').json_encode(SecurityEventExport::jsonRow($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                        $first = false;
                    }
                }
                fwrite($out, ']}');
            }

            fclose($out);
        }, $name, $headers);
    }
}
