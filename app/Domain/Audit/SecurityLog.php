<?php

namespace App\Domain\Audit;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Writes one line to the `security` channel (security.md §11): stderr, one
 * JSON object per line, so an operator can ship it to a SIEM without parsing
 * prose. The line's message is the event name, and its context always has the
 * same five keys — `event`, `actor`, `target`, `ip`, `details` — null or
 * empty when they do not apply, so a consumer never has to guess the shape.
 *
 * Nothing secret is passed here by any caller, and the channel's redaction
 * processor (RedactSensitiveData) is the second line: it would mask a
 * password, a token or a session id that a future caller put in `details`.
 */
class SecurityLog
{
    /**
     * @param  array{type: string, id: int|string|null, email?: string}|null  $target
     * @param  array<string, mixed>  $details
     */
    public static function record(SecurityEvent $event, ?User $actor, ?array $target = null, array $details = []): void
    {
        Log::channel('security')->log($event->level()->toPsrLogLevel(), $event->value, [
            'event' => $event->value,
            'actor' => $actor === null ? null : ['id' => $actor->id, 'email' => $actor->email],
            'target' => $target,
            'ip' => request()->ip(),
            'details' => $details,
        ]);
    }

    /** @return array{type: string, id: int, email: string} */
    public static function user(User $user): array
    {
        return ['type' => 'user', 'id' => $user->id, 'email' => $user->email];
    }

    /**
     * By uuid, the identifier every URL and the history page already show,
     * so a log line can be matched to what a user saw.
     *
     * @return array{type: string, id: string}
     */
    public static function assessment(Assessment $assessment): array
    {
        return ['type' => 'assessment', 'id' => $assessment->uuid];
    }
}
