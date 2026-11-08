<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * How code that is not the security log reports an event to it (two-factor
 * and single sign-on dispatch, the security log records), so the line's
 * shape and its redaction stay in one place:
 *
 *     SecurityEventOccurred::dispatch(SecurityEvent::MfaEnabled, $user, SecurityLog::user($user));
 */
class SecurityEventOccurred
{
    use Dispatchable;

    /**
     * @param  array{type: string, id: int|string|null, email?: string}|null  $target
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly SecurityEvent $event,
        public readonly ?User $actor,
        public readonly ?array $target = null,
        public readonly array $details = [],
    ) {}
}
