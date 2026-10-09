<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('password can be updated', function () {
    [$user, $password] = userWithKnownPassword();
    $replacement = Str::random(24);

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => $password,
            'password' => $replacement,
            'password_confirmation' => $replacement,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertTrue(Hash::check($replacement, $user->refresh()->password));
});

test('correct password must be provided to update password', function () {
    [$user] = userWithKnownPassword();
    $replacement = Str::random(24);

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => Str::random(24),
            'password' => $replacement,
            'password_confirmation' => $replacement,
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect('/profile');
});
