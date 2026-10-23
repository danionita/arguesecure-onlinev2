<?php

namespace App\Domain\Identity\TwoFactor;

/**
 * The ten single-use codes that stand in for the authenticator app (D77).
 *
 * Only a keyed hash of each code is stored (on top of the column being
 * encrypted), so a database dump yields neither the codes nor, with the app
 * key, anything to type. A fast HMAC is enough: a code is 50 bits drawn from
 * a CSPRNG, not a chosen password, and the challenge is rate-limited.
 */
final class RecoveryCodes
{
    public const COUNT = 10;

    /** No 0/o/1/l/i: a code is read off a printout and typed. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** @return list<string> ten new codes, `xxxxx-xxxxx` */
    public static function generate(): array
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $codes[self::block().'-'.self::block()] = true;
        }

        return array_keys($codes);
    }

    /** What is stored for `$code`. */
    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalise($code), (string) config('app.key'));
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function hashAll(array $codes): array
    {
        return array_map(self::hash(...), $codes);
    }

    /** Case, spaces and a missing hyphen are the typist's, not the code's. */
    public static function normalise(string $code): string
    {
        return strtolower(str_replace([' ', '-'], '', trim($code)));
    }

    private static function block(): string
    {
        $block = '';

        for ($i = 0; $i < 5; $i++) {
            $block .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $block;
    }
}
