<?php

namespace App\Domain\Identity\Oidc;

use App\Domain\Identity\Oidc\Events\SsoAccountCreated;
use App\Domain\Identity\Oidc\Events\SsoIdentityLinked;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Who a validated ID token is (D76). In order:
 *
 *  1. the account already linked to `(iss, sub)`, whatever its email now says;
 *  2. the active account with the token's email, linked on this first
 *     sign-in, if the email is verified (or the operator trusts the provider's
 *     emails) and that account has no identity from this issuer yet;
 *  3. a new non-admin account, if the email's domain is on the operator's list
 *     and the email is verified.
 *
 * Anything else is refused. Linking or creating only on a verified email is
 * what stops a provider account that merely *claims* an address taking over an
 * ArgueSecure account, or minting one.
 */
final class SsoAccounts
{
    /**
     * @param  array<string, mixed>  $claims  validated ID-token claims
     *
     * @throws OidcException
     */
    public function resolve(array $claims): User
    {
        $issuer = (string) $claims['iss'];
        $subject = (string) $claims['sub'];

        $identity = UserIdentity::query()->where('issuer', $issuer)->where('subject', $subject)->first();

        if ($identity !== null) {
            $user = $identity->user;
            $this->ensureActive($user);
            $identity->forceFill(['last_used_at' => now()])->save();

            return $user;
        }

        $email = $this->verifiedEmail($claims);

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null) {
            $this->ensureActive($user);

            if ($user->identities()->where('issuer', $issuer)->exists()) {
                throw new OidcException('The account is already linked to a different subject.');
            }

            return $this->link($user, $issuer, $subject);
        }

        $domain = Str::after($email, '@');

        if (! in_array($domain, OidcConfig::allowedDomains(), true)) {
            throw new OidcException('No account, and the email domain is not allowed to create one.');
        }

        return $this->create($email, $claims, $issuer, $subject);
    }

    /** @param array<string, mixed> $claims */
    private function verifiedEmail(array $claims): string
    {
        $email = $claims['email'] ?? null;

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OidcException('The token carries no usable email.');
        }

        $verified = ($claims['email_verified'] ?? null) === true || ($claims['email_verified'] ?? null) === 'true';

        if (! $verified && ! OidcConfig::trustEmail()) {
            throw new OidcException('The email is not verified.');
        }

        return strtolower($email);
    }

    private function ensureActive(User $user): void
    {
        if (! $user->is_active) {
            throw new OidcException('The account is deactivated.');
        }
    }

    private function link(User $user, string $issuer, string $subject): User
    {
        $identity = new UserIdentity;
        $identity->user_id = $user->id;
        $identity->issuer = $issuer;
        $identity->subject = $subject;
        $identity->last_used_at = now();

        try {
            $identity->save();
        } catch (QueryException) {
            throw new OidcException('The identity could not be linked.');
        }

        event(new SsoIdentityLinked($user, $issuer));

        return $user;
    }

    /** @param array<string, mixed> $claims */
    private function create(string $email, array $claims, string $issuer, string $subject): User
    {
        $name = is_string($claims['name'] ?? null) && trim($claims['name']) !== '' ? trim($claims['name']) : $email;

        return DB::transaction(function () use ($email, $name, $issuer, $subject): User {
            $user = new User;
            $user->name = mb_substr($name, 0, 255);
            $user->email = $email;
            // A random value nobody is shown: this account signs in through
            // the provider only (an admin can still set a password).
            $user->password = Hash::make(Str::random(64));
            $user->is_admin = false;
            $user->is_active = true;
            $user->save();

            event(new SsoAccountCreated($user));

            return $this->link($user, $issuer, $subject);
        });
    }
}
