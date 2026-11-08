<?php

namespace App\Domain\Identity\Oidc\Events;

/** Dispatched for the security event log (security.md §11.1); nothing here writes a log line. */
final class SsoLoginFailed
{
    public function __construct(public readonly string $reason, public readonly ?string $ip) {}
}
