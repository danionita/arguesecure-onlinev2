<?php

namespace App\Domain\Audit;

use App\Models\SecurityEventRecord;
use Illuminate\Support\Carbon;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Inserts one `security_events` row per security-channel record (D97).
 *
 * A failed insert must never fail the request that caused the event — a
 * sign-in, a refusal — so it is caught and reported on stderr instead.
 */
class SecurityEventStoreHandler extends AbstractProcessingHandler
{
    public function __construct()
    {
        parent::__construct(Level::Info);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $context = $record->context;
            $actor = is_array($context['actor'] ?? null) ? $context['actor'] : null;

            $row = new SecurityEventRecord;
            $row->created_at = Carbon::instance($record->datetime)->utc();
            $row->level = strtolower($record->level->getName());
            $row->event = (string) ($context['event'] ?? $record->message);
            $row->actor_id = $actor['id'] ?? null;
            $row->actor_email = isset($actor['email']) ? mb_substr((string) $actor['email'], 0, 254) : null;
            $row->target = $this->capEmail($context['target'] ?? null);
            $row->ip = isset($context['ip']) ? mb_substr((string) $context['ip'], 0, 45) : null;
            $row->details = $context['details'] ?? [];
            $row->save();
        } catch (Throwable $e) {
            error_log('security_events insert failed: '.$e::class);
        }
    }

    /** @return array<string, mixed>|null */
    private function capEmail(mixed $target): ?array
    {
        if (! is_array($target)) {
            return null;
        }

        if (isset($target['email'])) {
            $target['email'] = mb_substr((string) $target['email'], 0, 254);
        }

        return $target;
    }
}
