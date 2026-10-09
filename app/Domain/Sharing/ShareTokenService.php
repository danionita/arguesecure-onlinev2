<?php

namespace App\Domain\Sharing;

use App\Models\Assessment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates and revokes the read-only share-link token (schema.md §3.3,
 * security.md §3.2). `assessments.share_token`/`share_token_created_at`
 * are deliberately absent from Assessment::$fillable — every write here
 * goes through direct property assignment, never mass assignment.
 *
 * Storage: PLAINTEXT, not hashed, and that is a deliberate reading of an
 * already-fixed contract rather than a choice made fresh here.
 * `AssessmentPolicy::hasValidShareToken()` (owned by W6, not touched by
 * this package) does
 * `hash_equals($assessment->share_token, $flag['token'])` — a direct
 * constant-time comparison against the column value. If this column held
 * a hash, that comparison would only ever match a session flag that also
 * stored the hash, which would just relocate the "compare a bearer
 * secret in constant time" problem rather than remove it, while adding a
 * second representation of the same secret to reconcile. Hashing earns
 * its cost when the *verifier* must not be able to reconstruct the
 * secret (passwords, API keys checked against a stored table row by
 * lookup) — here the verifier already holds the live token in the same
 * row it's comparing against, generated with a CSPRNG, 32 bytes of
 * entropy, unlisted, revocable by nulling the column (which the policy
 * treats as an instant "no" — see hasValidShareToken()'s null check),
 * and never logged (security.md §11 redacts share_token from all log
 * context). The realistic threat this token defends against is
 * guessing, not database exfiltration of a comparison table, so the
 * plaintext-with-CSPRNG-and-revocation design is the one that matches
 * the policy code already shipped.
 */
class ShareTokenService
{
    /** Generates (or regenerates) the token, invalidating any previously issued link. */
    public static function generate(Assessment $assessment): string
    {
        $token = self::randomToken();

        $assessment->share_token = $token;
        $assessment->share_token_created_at = Carbon::now();
        $assessment->save();

        return $token;
    }

    /**
     * Revokes the token immediately. Nulling the column is sufficient:
     * every policy check re-fetches the assessment, and
     * hasValidShareToken() treats a null share_token as "no" before it
     * even looks at the session flag — there is no separate revocation
     * list to maintain and no stale-session window.
     */
    public static function revoke(Assessment $assessment): void
    {
        $assessment->share_token = null;
        $assessment->share_token_created_at = null;
        $assessment->save();
    }

    /**
     * Resolves a presented token to its assessment. Looked up by exact
     * column match — the 32-byte CSPRNG token space makes SQL-level
     * timing variance an immaterial side channel compared to the token's
     * guess-resistance, and comparing every row in application code would
     * cost a full table scan for no real security gain.
     */
    public static function resolve(string $token): ?Assessment
    {
        if (strlen($token) !== 43) {
            return null;
        }

        return Assessment::query()->where('share_token', $token)->first();
    }

    /** 32 random bytes, base64url, no padding — 43 characters (schema.md §3.3). */
    private static function randomToken(): string
    {
        do {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } while (DB::table('assessments')->where('share_token', $token)->exists());

        return $token;
    }
}
