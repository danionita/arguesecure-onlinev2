<?php

use App\Models\User;

require_once __DIR__.'/helpers.php';

/*
 * Claims shaped like the ones Google sends: a boolean `email_verified`, an
 * `azp` equal to the client, and an `hd` claim that the application ignores.
 * The tests run against the fake provider in helpers.php, so they show that
 * these shapes are accepted; they are not a check against Google itself.
 */
function googleClaims(array $overrides = []): array
{
    return array_merge([
        'azp' => CLIENT_ID,
        'email' => 'person@gmail.com',
        'email_verified' => true,
        'hd' => 'example.org',
        'name' => 'A Person',
    ], $overrides);
}

test('a Google-shaped token creates a non-admin account for a listed domain', function () {
    $idp = fakeIdp(['allowed_domains' => 'gmail.com']);

    signInThroughIdp($this, $idp, googleClaims())->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'person@gmail.com')->firstOrFail();
    expect($user->is_admin)->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

test('the hd claim does not let an unlisted domain create an account', function () {
    $idp = fakeIdp(['allowed_domains' => 'gmail.com']);

    signInThroughIdp($this, $idp, googleClaims(['email' => 'person@other.example', 'hd' => 'gmail.com']))->assertRedirect(route('login'));
    $this->assertGuest();

    expect(User::query()->where('email', 'person@other.example')->exists())->toBeFalse();
});

test('an unverified Google email creates nothing', function () {
    $idp = fakeIdp(['allowed_domains' => 'gmail.com']);

    signInThroughIdp($this, $idp, googleClaims(['email_verified' => false]))->assertSessionHasErrors(['email' => GENERIC_REFUSAL]);
    $this->assertGuest();

    expect(User::query()->where('email', 'person@gmail.com')->exists())->toBeFalse();
});
