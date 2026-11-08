<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An address was proven by following its link. Carries what the sender bound
 * the link to; whoever sent it creates or activates whatever it stands for.
 */
class EmailAddressVerified
{
    use Dispatchable;

    public function __construct(public readonly string $email, public readonly ?string $reference) {}
}
