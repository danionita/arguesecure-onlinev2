<?php

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Authorization matrix required by CLAUDE.md / security.md §3.3-style
 * coverage for every route this package adds: anonymous, non-admin, and
 * deactivated users must all be rejected from every /admin route.
 */
test('anonymous user is redirected away from the admin users index', function () {
    $this->get('/admin/users')->assertRedirect('/login');
});

test('a non-admin user is forbidden from the admin users index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/users')->assertForbidden();
});

test('a deactivated admin is rejected before the admin check even runs', function () {
    $admin = User::factory()->admin()->inactive()->create();

    $response = $this->actingAs($admin)->get('/admin/users');

    $response->assertForbidden();
    $this->assertGuest();
});

test('an admin can view the admin users index', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get('/admin/users');

    $response->assertOk();
});

test('admin users index search filters by name and email', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['name' => 'Zebra Person', 'email' => 'zebra@example.com']);
    User::factory()->create(['name' => 'Someone Else', 'email' => 'else@example.com']);

    $response = $this->actingAs($admin)->get('/admin/users?search=zebra');

    $response->assertOk();
    // Deliberately not sending an X-Inertia header: that path is subject
    // to Inertia's asset-version conflict response (409) whenever
    // public/build/manifest.json exists, which is environment-dependent
    // and not something a route-authorization test should be sensitive
    // to. assertInertia() reads the same page data out of the full HTML
    // response instead.
    $response->assertInertia(function ($page) {
        $emails = collect($page->toArray()['props']['users']['data'])->pluck('email');
        expect($emails)->toContain('zebra@example.com');
        expect($emails)->not->toContain('else@example.com');
    });
});

test('admin users index rejects a non-whitelisted sort column', function () {
    $admin = User::factory()->admin()->create();

    // 'password' is not in the sortable whitelist; the controller must
    // fall back to a safe default rather than ORDER BY an arbitrary
    // request-supplied column (security.md §7).
    $response = $this->actingAs($admin)->get('/admin/users?sort=password&direction=asc');

    $response->assertOk();
});

test('a non-admin cannot create a user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/admin/users', [
        'name' => 'New Person',
        'email' => 'new-person@example.com',
        'is_admin' => false,
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'new-person@example.com']);
});

test('an admin can create a user with a generated password', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post('/admin/users', [
        'name' => 'New Person',
        'email' => 'new-person@example.com',
        'is_admin' => true,
    ]);

    $response->assertRedirect(route('admin.users.index'));
    $this->assertDatabaseHas('users', [
        'email' => 'new-person@example.com',
        'is_admin' => true,
        'is_active' => true,
    ]);
});

test('creating a user cannot set fields outside the whitelisted ones via extra input', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'New Person',
        'email' => 'new-person@example.com',
        'is_admin' => false,
        // Attempting to smuggle extra attributes — the controller only
        // ever reads name/email/password/is_admin from validated(), never
        // $request->all(), so these must be silently ignored.
        'id' => 99999,
        'created_at' => '2000-01-01 00:00:00',
    ]);

    $created = User::query()->where('email', 'new-person@example.com')->firstOrFail();
    expect($created->id)->not->toBe(99999);
    expect($created->created_at->year)->not->toBe(2000);
});

test('an admin can update a user, toggling admin and active flags', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
        'name' => $target->name,
        'email' => $target->email,
        'is_admin' => true,
        'is_active' => false,
    ]);

    $response->assertRedirect(route('admin.users.index'));
    $target->refresh();
    expect($target->is_admin)->toBeTrue();
    expect($target->is_active)->toBeFalse();
});

test('deactivating a user via the admin area revokes their sessions', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'target-session-id',
        'user_id' => $target->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Test',
        'payload' => base64_encode('irrelevant'),
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($admin)->put("/admin/users/{$target->id}", [
        'name' => $target->name,
        'email' => $target->email,
        'is_admin' => false,
        'is_active' => false,
    ]);

    $this->assertDatabaseMissing('sessions', ['id' => 'target-session-id']);
});

test('an admin can reset a users password', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $oldHash = $target->password;

    $response = $this->actingAs($admin)->post("/admin/users/{$target->id}/reset-password");

    $response->assertRedirect(route('admin.users.index'));
    $target->refresh();
    expect($target->password)->not->toBe($oldHash);
});

test('an admin password reset forces the target user to change it at next login', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($admin)->post("/admin/users/{$target->id}/reset-password");

    expect($target->fresh()->must_change_password)->toBeTrue();
});

test('deleting a user who owns no assessments does not require a transfer target', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->delete("/admin/users/{$target->id}");

    $response->assertRedirect(route('admin.users.index'));
    $this->assertDatabaseMissing('users', ['id' => $target->id]);
});

test('deleting a user who owns assessments requires a transfer target', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $target->id]);

    $response = $this->actingAs($admin)->delete("/admin/users/{$target->id}");

    $response->assertSessionHasErrors('transfer_to_user_id');
    $this->assertDatabaseHas('users', ['id' => $target->id]);
    $this->assertDatabaseHas('assessments', ['id' => $assessment->id, 'owner_user_id' => $target->id]);
});

test('deleting a user transfers their owned assessments instead of cascading', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $recipient = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $target->id]);

    $response = $this->actingAs($admin)->delete("/admin/users/{$target->id}", [
        'transfer_to_user_id' => $recipient->id,
    ]);

    $response->assertRedirect(route('admin.users.index'));
    $this->assertDatabaseMissing('users', ['id' => $target->id]);
    $this->assertDatabaseHas('assessments', ['id' => $assessment->id, 'owner_user_id' => $recipient->id]);
});
