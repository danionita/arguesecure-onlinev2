<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-time emailed link. Created and consumed only by
 * App\Domain\Mail\MailTokens; the token itself is never stored.
 *
 * @property int $id
 * @property string $purpose
 * @property int|null $user_id
 * @property string $email
 * @property string|null $reference
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class MailToken extends Model
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
