<?php

namespace App\Domain\Audit;

use App\Models\SecurityEventRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * Selects and renders the stored security event log for download (W128).
 *
 * The filters are validated against {@see SecurityEvent} by the request
 * before they reach here; nothing from the request names a column.
 */
final class SecurityEventExport
{
    /** Rows in one download (D99). */
    public const MAX_ROWS = 50_000;

    public const COLUMNS = [
        'id', 'time', 'level', 'event', 'actor_id', 'actor_email',
        'target_type', 'target_id', 'target_email', 'ip', 'details',
    ];

    /** A cell starting with one of these is read as a formula by a spreadsheet. */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /** @return list<string> the first segments of every event name, e.g. `auth` */
    public static function prefixes(): array
    {
        return array_values(array_unique(array_map(
            fn (SecurityEvent $e): string => explode('.', $e->value)[0],
            SecurityEvent::cases(),
        )));
    }

    /** @return list<string> */
    public static function eventNames(): array
    {
        return array_map(fn (SecurityEvent $e): string => $e->value, SecurityEvent::cases());
    }

    /**
     * @return Builder<SecurityEventRecord>
     */
    public static function query(?string $from, ?string $to, ?string $event): Builder
    {
        $query = SecurityEventRecord::query();

        if ($from !== null) {
            $query->where('created_at', '>=', $from.' 00:00:00');
        }

        if ($to !== null) {
            $query->where('created_at', '<', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00');
        }

        if ($event !== null) {
            // Either an exact event or a family (`auth` → `auth.%`). `.` and
            // `_` are fixed text from the validated list, never request input.
            if (str_contains($event, '.')) {
                $query->where('event', $event);
            } else {
                $query->where('event', 'like', $event.'.%');
            }
        }

        return $query;
    }

    /** @return list<string|int|null> one CSV row, formula-neutralised */
    public static function csvRow(SecurityEventRecord $row): array
    {
        $target = $row->target ?? [];
        $cells = [
            $row->id,
            $row->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            $row->level,
            $row->event,
            $row->actor_id,
            $row->actor_email,
            $target['type'] ?? null,
            isset($target['id']) ? (string) $target['id'] : null,
            $target['email'] ?? null,
            $row->ip,
            json_encode($row->details ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        return array_map(fn ($cell) => is_string($cell) ? self::neutralise($cell) : $cell, $cells);
    }

    public static function neutralise(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], self::FORMULA_START, true) ? "'".$cell : $cell;
    }

    /** @return array<string, mixed> */
    public static function jsonRow(SecurityEventRecord $row): array
    {
        return [
            'id' => $row->id,
            'time' => $row->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'level' => $row->level,
            'event' => $row->event,
            'actor' => $row->actor_id === null && $row->actor_email === null
                ? null
                : ['id' => $row->actor_id, 'email' => $row->actor_email],
            'target' => $row->target,
            'ip' => $row->ip,
            'details' => $row->details ?? [],
        ];
    }
}
