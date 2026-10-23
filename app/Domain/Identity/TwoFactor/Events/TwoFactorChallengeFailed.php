<?php

namespace App\Domain\Identity\TwoFactor\Events;

/** A wrong code at the sign-in challenge. Carries the account id, never the code. */
final class TwoFactorChallengeFailed
{
    public function __construct(public readonly int $userId, public readonly ?string $ip) {}
}
