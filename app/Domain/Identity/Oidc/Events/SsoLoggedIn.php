<?php

namespace App\Domain\Identity\Oidc\Events;

use App\Models\User;

/** Dispatched for the security event log (PLAN-2.7 W111); nothing here writes a log line. */
final class SsoLoggedIn
{
    public function __construct(public readonly User $user) {}
}
