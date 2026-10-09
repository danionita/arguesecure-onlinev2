<?php

use App\Models\User;

/*
 * security.md §4: "is_active is enforced on every request, not only at
 * login ... Add middleware on the authenticated route group that logs
 * out and rejects any user whose is_active is false, so a missed session
 * cannot keep a deactivated account working."
 */

test('an active user can reach an authenticated route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('a deactivated user is rejected and logged out on their very next request', function () {
    $user = User::factory()->create();

    // Simulate the session surviving a deactivation that happened after
    // the session was established — the middleware, not session
    // invalidation, is what must catch this.
    $response = $this->actingAs($user)->get('/dashboard');
    $response->assertOk();

    // is_active is deliberately not mass-assignable (User::$fillable —
    // see app/Models/User.php), so update([...]) would silently no-op;
    // set it directly the way the admin controller does.
    $user->is_active = false;
    $user->save();

    $response = $this->get('/dashboard');
    $response->assertForbidden();
    $this->assertGuest();
});
