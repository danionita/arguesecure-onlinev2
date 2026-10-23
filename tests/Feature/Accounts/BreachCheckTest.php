<?php

use App\Domain\Accounts\PasswordPolicy;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

// W120 / D84: the breach lookup is bounded and fails open (security.md §4).
// PasswordPolicy::rule() skips the lookup under the testing environment, so
// these tests build the rule the production policy builds.

// The production policy: PasswordPolicy::rule() leaves the lookup out only
// under the testing environment.
function breachRule(string $password): bool
{
    app()->detectEnvironment(fn () => 'production');

    return Validator::make(
        ['password' => $password],
        ['password' => PasswordPolicy::rule()],
    )->passes();
}

it('waits at most the bounded timeout for the breach service', function () {
    $verifier = app(UncompromisedVerifier::class);

    $timeout = (new ReflectionProperty($verifier, 'timeout'))->getValue($verifier);

    expect($timeout)->toBe(PasswordPolicy::BREACH_CHECK_TIMEOUT)
        ->and($timeout)->toBeLessThan(30);
});

it('accepts the password when the breach service times out', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(breachRule('a-long-unlisted-passphrase'))->toBeTrue();
});

it('accepts the password when the breach service answers with an error', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

    expect(breachRule('a-long-unlisted-passphrase'))->toBeTrue();
});

it('still rejects a password the breach service lists', function () {
    $password = 'correct-horse-battery';
    $hash = strtoupper(sha1($password));
    Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':42')]);

    expect(breachRule($password))->toBeFalse();
});
