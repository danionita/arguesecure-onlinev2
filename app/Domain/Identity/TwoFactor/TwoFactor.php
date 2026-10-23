<?php

namespace App\Domain\Identity\TwoFactor;

use App\Domain\Accounts\SessionRegistry;
use App\Domain\Identity\TwoFactor\Events\RecoveryCodesRegenerated;
use App\Domain\Identity\TwoFactor\Events\RecoveryCodeUsed;
use App\Domain\Identity\TwoFactor\Events\TwoFactorDisabled;
use App\Domain\Identity\TwoFactor\Events\TwoFactorEnabled;
use App\Domain\Identity\TwoFactor\Events\TwoFactorRemovedByAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything that changes or checks an account's second factor (D77, D78).
 * Controllers call this; none of them touch the columns.
 */
final class TwoFactor
{
    public function __construct(private readonly Totp $totp) {}

    /**
     * Turn the factor on for `$user` with a secret they have just proved they
     * hold. Returns the recovery codes in plain text — the one and only time.
     * The code that proved it is spent, so it cannot also be the first sign-in.
     *
     * @return list<string>
     */
    public function enable(User $user, string $secret, int $confirmedStep): array
    {
        $codes = RecoveryCodes::generate();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $confirmedStep,
        ])->save();

        // Turning it on is a response to doubt about the account: a
        // remember-me cookie issued before it must not skip the challenge.
        $this->endRememberedLogins($user);

        event(new TwoFactorEnabled($user));

        return $codes;
    }

    /**
     * Accept `$code` as the current code of `$user`'s authenticator app, once.
     *
     * The step is claimed with a conditional UPDATE, not read-then-write: two
     * requests carrying the same code race on the row, and exactly one wins.
     */
    public function acceptCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $step = $this->totp->matchingStep($user->two_factor_secret, $code, $user->two_factor_last_step);

        if ($step === null) {
            return false;
        }

        $claimed = User::query()
            ->whereKey($user->getKey())
            ->where(fn ($query) => $query->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]);

        return $claimed === 1;
    }

    /** Accept `$code` as one of the account's recovery codes, and burn it. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = RecoveryCodes::hash($code);

        $used = DB::transaction(function () use ($user, $hash): bool {
            $fresh = User::query()->lockForUpdate()->find($user->getKey());
            $stored = $fresh?->two_factor_recovery_codes;

            if (! $fresh?->hasTwoFactorEnabled() || ! is_array($stored)) {
                return false;
            }

            $index = null;
            foreach ($stored as $i => $candidate) {
                if (hash_equals((string) $candidate, $hash)) {
                    $index = $i;
                }
            }

            if ($index === null) {
                return false;
            }

            unset($stored[$index]);
            $fresh->forceFill(['two_factor_recovery_codes' => array_values($stored)])->save();

            return true;
        });

        if ($used) {
            $user->refresh();
            event(new RecoveryCodeUsed($user));
        }

        return $used;
    }

    /**
     * Either kind of code: what the challenge and "turn it off" both take.
     */
    public function acceptAny(User $user, string $code): bool
    {
        return $this->acceptCode($user, $code) || $this->useRecoveryCode($user, $code);
    }

    public function recoveryCodesRemaining(User $user): int
    {
        $codes = $user->two_factor_recovery_codes;

        return is_array($codes) ? count($codes) : 0;
    }

    /**
     * Replace the recovery codes; the old ones stop working at once.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = RecoveryCodes::generate();

        $user->forceFill(['two_factor_recovery_codes' => RecoveryCodes::hashAll($codes)])->save();

        event(new RecoveryCodesRegenerated($user));

        return $codes;
    }

    /**
     * The account turns its own factor off. Other sessions end (security.md
     * §4); the caller's own survives.
     */
    public function disable(User $user, ?string $keepSessionId): void
    {
        $this->clear($user);

        SessionRegistry::revokeAllExcept($user->id, $keepSessionId);

        event(new TwoFactorDisabled($user));
    }

    /** An admin removes it (a lost phone); the account enrols again. */
    public function removeByAdmin(User $user, User $admin, ?string $keepSessionId): void
    {
        $this->clear($user);

        SessionRegistry::revokeAllExcept($user->id, $keepSessionId);

        event(new TwoFactorRemovedByAdmin($user, $admin));
    }

    /** Session rows are not the only way back in: a remember-me cookie re-authenticates through the token. */
    private function endRememberedLogins(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    private function clear(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $this->endRememberedLogins($user);
    }
}
