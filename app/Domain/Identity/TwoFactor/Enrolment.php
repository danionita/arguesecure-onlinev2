<?php

namespace App\Domain\Identity\TwoFactor;

use App\Domain\Accounts\InstanceSettings;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

/**
 * An enrolment in progress, and the profile panel's props (W116, D77).
 *
 * Until a code from the new secret is confirmed the secret exists only in the
 * session, encrypted — sessions are database rows — and the account is
 * untouched, so an abandoned enrolment leaves two-factor authentication off.
 */
final class Enrolment
{
    private const SESSION_KEY = 'two_factor.enrolment_secret';

    public function __construct(private readonly Totp $totp, private readonly TwoFactor $twoFactor) {}

    public function begin(Session $session): void
    {
        $session->put(self::SESSION_KEY, Crypt::encryptString($this->totp->newSecret()));
    }

    public function secret(Session $session): ?string
    {
        $stored = $session->get(self::SESSION_KEY);

        if (! is_string($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * The panel's props. The *stored* secret and codes are never among them;
     * the only secret the page ever carries is the one an enrolment is still
     * waiting to confirm, shown to its owner so they can type it into an app
     * that cannot scan.
     *
     * @return array{enabled: bool, recovery_codes_remaining: int, required: bool, pending: array{secret: string, qr: string}|null}
     */
    public function panel(User $user, Session $session): array
    {
        $secret = $this->secret($session);
        $pending = null;

        if ($secret !== null && ! $user->hasTwoFactorEnabled()) {
            $pending = [
                'secret' => $secret,
                'qr' => $this->totp->qrDataUri($this->totp->provisioningUri($secret, $user->email)),
            ];
        }

        return [
            'enabled' => $user->hasTwoFactorEnabled(),
            'recovery_codes_remaining' => $this->twoFactor->recoveryCodesRemaining($user),
            'required' => InstanceSettings::requireTwoFactor(),
            'pending' => $pending,
        ];
    }
}
