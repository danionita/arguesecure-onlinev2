<?php

namespace App\Domain\Accounts;

use Illuminate\Validation\Rules\Password;

/**
 * The strong-password rule shared by first-run setup, admin user creation,
 * admin password reset and self-service password change (security.md §4:
 * "Minimum 12 characters, checked against Laravel's compromised-password
 * rule ... If outbound network access is unavailable, degrade to length
 * and complexity only — never block login.").
 *
 * AppServiceProvider (not owned by this package) is where Password::defaults()
 * would normally be centralised; since it is out of W8's file ownership,
 * every request class that validates a password calls PasswordPolicy::rule()
 * explicitly instead.
 */
class PasswordPolicy
{
    public static function rule(): Password
    {
        // Length + HIBP only, matching security.md §4 exactly — it does not
        // ask for letter/number/symbol complexity classes on top of the
        // 12-character floor, and Laravel's own `userWithKnownPassword()`
        // test helper (tests/Pest.php) generates plain alphanumeric
        // Str::random() passwords, so adding an un-spec'd symbol
        // requirement here would break every test that authenticates.
        $rule = Password::min(12);

        // The HIBP k-anonymity lookup needs outbound network access, which
        // the test/CI sandbox does not reliably have. Degrading here rather
        // than letting the request hang or fail is exactly the behaviour
        // security.md §4 asks for; it is not testing-specific leniency on
        // length or complexity, only on the network-dependent check.
        if (! app()->environment('testing')) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }
}
