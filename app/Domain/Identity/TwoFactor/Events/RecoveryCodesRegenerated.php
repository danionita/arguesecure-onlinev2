<?php

namespace App\Domain\Identity\TwoFactor\Events;

use App\Models\User;

/** Dispatched for the security event log (security.md §11.1); nothing here writes a log line. */
final class RecoveryCodesRegenerated
{
    public function __construct(public readonly User $user) {}
}
