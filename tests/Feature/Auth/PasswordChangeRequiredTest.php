<?php

use App\Models\User;

/**
 * security.md §4: "Admin password reset ... forces a change on next
 * login." W8b closes the gap W8 reported — see App\Http\Middleware\
 * EnsurePasswordChanged and User::booted().
 */
test('a flagged user is redirected away from an ordinary page', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect(route('profile.edit'));
});

test('a flagged user can still reach the profile page hosting the password form', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)->get('/profile')->assertOk();
});

test('a flagged user can still change their password without a redirect loop', function () {
    [$user, $currentPassword] = userWithKnownPassword(['must_change_password' => true]);

    $response = $this->actingAs($user)->put('/password', [
        'current_password' => $currentPassword,
        'password' => 'a-brand-new-strong-password-1',
        'password_confirmation' => 'a-brand-new-strong-password-1',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();
});

test('a flagged user can still log out', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect('/');
    $this->assertGuest();
});

test('changing the password clears the must-change-password flag', function () {
    [$user, $currentPassword] = userWithKnownPassword(['must_change_password' => true]);

    $this->actingAs($user)->put('/password', [
        'current_password' => $currentPassword,
        'password' => 'another-brand-new-password-2',
        'password_confirmation' => 'another-brand-new-password-2',
    ]);

    expect($user->fresh()->must_change_password)->toBeFalse();

    // The flag being clear means ordinary pages are reachable again.
    $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
});

test('a user without the flag is never redirected', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($user)->get('/dashboard')->assertOk();
});
