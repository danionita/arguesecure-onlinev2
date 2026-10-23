<?php

namespace App\Domain\Identity\TwoFactor\Events;

use App\Models\User;

/** Dispatched for the security event log (PLAN-2.7 W111); nothing here writes a log line. */
final class TwoFactorEnabled
{
    public function __construct(public readonly User $user) {}
}
