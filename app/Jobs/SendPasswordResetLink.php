<?php

namespace App\Jobs;

use App\Domain\Mail\PasswordResets;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks the address up and, only if an account may be sent a link, sends one.
 * Queued for every address, so the request does the same work whether or not
 * there is an account, and the answer cannot be told apart by its timing.
 */
class SendPasswordResetLink implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $email) {}

    public function handle(): void
    {
        $user = User::query()->where('email', $this->email)->first();

        if (PasswordResets::available() && PasswordResets::eligible($user)) {
            PasswordResets::send($user);
        }
    }
}
