<?php

namespace App\Domain\Identity\Oidc\Events;

/** Dispatched for the security event log (PLAN-2.7 W111); nothing here writes a log line. */
final class SsoLoginFailed
{
    public function __construct(public readonly string $reason, public readonly ?string $ip) {}
}
