<?php

namespace App\Domain\Mail;

use App\Models\MailToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Issues and spends one-time links.
 *
 * The token is 48 random characters, so a plain SHA-256 of it is enough to
 * store: there is nothing to guess offline. Issuing a token voids the person's
 * earlier unused one for the same purpose, and spending is one conditional
 * UPDATE, so two clicks cannot both succeed.
 */
final class MailTokens
{
    public const INVITE = 'invite';

    public const VERIFY = 'verify';

    public const RESET = 'reset';

    /** Returns the plain token, shown once: it goes into the email and nowhere else. */
    public static function issue(string $purpose, string $email, ?User $user, \DateInterval $ttl, ?string $reference = null): string
    {
        $query = MailToken::query()->where('purpose', $purpose)->whereNull('used_at');
        $user !== null ? $query->where('user_id', $user->id) : $query->where('email', $email)->whereNull('user_id');
        $query->delete();

        $token = Str::random(48);

        $row = new MailToken;
        $row->purpose = $purpose;
        $row->user_id = $user?->id;
        $row->email = $email;
        $row->reference = $reference;
        $row->token_hash = self::hash($token);
        $row->expires_at = now()->add($ttl);
        $row->save();

        return $token;
    }

    /** The token's row if it is still good, without spending it. */
    public static function peek(string $purpose, string $token): ?MailToken
    {
        return MailToken::query()
            ->where('token_hash', self::hash($token))
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /** Spends the token. Null if it is unknown, used, expired, or another request just spent it. */
    public static function consume(string $purpose, string $token): ?MailToken
    {
        $row = self::peek($purpose, $token);

        if ($row === null) {
            return null;
        }

        $spent = MailToken::query()->whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]);

        return $spent === 1 ? $row : null;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
