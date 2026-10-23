<?php

namespace App\Domain\Identity\TwoFactor\Events;

use App\Models\User;

/** An admin took `$user`'s second factor away (a lost phone). */
final class TwoFactorRemovedByAdmin
{
    public function __construct(public readonly User $user, public readonly User $admin) {}
}
