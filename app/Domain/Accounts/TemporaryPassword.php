<?php

namespace App\Domain\Accounts;

use Illuminate\Support\Str;

/**
 * Generates a one-time, human-transcribable password for admin-created
 * accounts and password resets. Never persisted in plaintext anywhere —
 * the caller must hash it immediately and hand the plaintext back to the
 * admin exactly once, for on-screen display or the printable credential
 * sheet (ui.md §10, CLAUDE.md "never write a secret ... into any file").
 */
class TemporaryPassword
{
    public static function generate(): string
    {
        // 20 chars from Laravel's ambiguity-free alphabet, comfortably
        // clearing PasswordPolicy::rule()'s length/complexity floor.
        return Str::password(20, symbols: true, numbers: true, letters: true);
    }
}
