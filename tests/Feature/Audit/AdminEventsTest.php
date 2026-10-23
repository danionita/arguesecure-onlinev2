<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/Capture.php';

test('creating an account is logged with the admin as actor and the new account as target', function () {
    $admin = User::factory()->admin()->create();
    $log = captureSecurityLog();

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'New Person', 'email' => 'new.person@example.com', 'is_admin' => false,
    ])->assertRedirect();

    $created = User::query()->where('email', 'new.person@example.com')->firstOrFail();
    $line = securityLine($log, 'account.created');
    expect($line['actor'])->toBe(['id' => $admin->id, 'email' => $admin->email])
        ->and($line['target'])->toBe(['type' => 'user', 'id' => $created->id, 'email' => $created->email])
        ->and($line['details'])->toBe(['is_admin' => false])
        ->and($line['ip'])->toBe('127.0.0.1');
});

test('the first-run setup logs the first admin with no actor', function () {
    $log = captureSecurityLog();
    $password = 'First-Run-Passphrase-'.bin2hex(random_bytes(4));

    $this->post('/setup', [
        'name' => 'First Admin', 'email' => 'first@example.com',
        'password' => $password, 'password_confirmation' => $password,
    ]);

    $line = securityLine($log, 'account.created');
    expect($line['actor'])->toBeNull()
        ->and($line['target']['email'])->toBe('first@example.com')
        ->and($line['details'])->toBe(['is_admin' => true]);
});

test('granting admin and deactivating an account are logged as separate events', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($admin)->put("/admin/users/{$target->id}", [
        'name' => $target->name, 'email' => $target->email, 'is_admin' => true, 'is_active' => false,
    ])->assertRedirect();

    foreach (['account.admin_granted', 'account.deactivated'] as $event) {
        $line = securityLine($log, $event);
        expect($line['actor']['id'])->toBe($admin->id)
            ->and($line['target']['id'])->toBe($target->id)
            ->and($line['ip'])->toBe('127.0.0.1');
    }
});

test('revoking admin and reactivating an account are logged', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->admin()->inactive()->create();
    $log = captureSecurityLog();

    $this->actingAs($admin)->put("/admin/users/{$target->id}", [
        'name' => $target->name, 'email' => $target->email, 'is_admin' => false, 'is_active' => true,
    ])->assertRedirect();

    expect(securityLine($log, 'account.admin_revoked')['target']['id'])->toBe($target->id)
        ->and(securityLine($log, 'account.reactivated')['target']['id'])->toBe($target->id);
});

test('renaming an account or changing its email is logged with the fields, and the old email', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'before@example.com']);
    $log = captureSecurityLog();

    $this->actingAs($admin)->put("/admin/users/{$target->id}", [
        'name' => $target->name, 'email' => 'after@example.com', 'is_admin' => false, 'is_active' => true,
    ])->assertRedirect();

    $line = securityLine($log, 'account.updated');
    expect($line['details'])->toBe(['fields' => ['email'], 'previous_email' => 'before@example.com'])
        ->and($line['target']['email'])->toBe('after@example.com')
        ->and($line['actor']['id'])->toBe($admin->id);
});

test('an admin password reset is logged as a reset by the admin, without the password', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($admin)->post("/admin/users/{$target->id}/reset-password")->assertRedirect();

    $line = securityLine($log, 'account.password_reset');
    expect($line['actor']['id'])->toBe($admin->id)
        ->and($line['target']['id'])->toBe($target->id);
    expect(array_column(securityLines($log), 'event'))->not->toContain('auth.password_changed');

    // The generated password is shown to the admin once: in the session flash
    // until W115, in Inertia's flash on the next page load after it. Take it
    // from wherever it is, and prove it is the account's real password, so the
    // absence check below is not an empty-string check.
    $password = session('generatedPassword')
        ?? $this->actingAs($admin)->get(route('admin.users.index'))->viewData('page')['flash']['generatedPassword']
        ?? null;
    expect($password)->toBeString()->not->toBe('')
        ->and(Hash::check($password, $target->fresh()->password))->toBeTrue();
    expect(json_encode(securityLines($log)))->not->toContain($password);
});

test('deleting an account is logged', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($admin)->delete("/admin/users/{$target->id}")->assertRedirect();

    $line = securityLine($log, 'account.deleted');
    expect($line['actor']['id'])->toBe($admin->id)
        ->and($line['target']['id'])->toBe($target->id);
});

test('a bulk CSV import logs each account it creates', function () {
    $admin = User::factory()->admin()->create();
    $log = captureSecurityLog();
    $csv = "name,email\nAda Lovelace,ada@example.com\nAlan Turing,alan@example.com\n";

    $this->actingAs($admin)->post('/admin/users/import/commit', ['csv_text' => $csv])->assertOk();

    $emails = collect(securityLines($log))->where('event', 'account.created')->pluck('target.email')->all();
    expect($emails)->toBe(['ada@example.com', 'alan@example.com']);
});

test('saving instance settings is logged with the values', function () {
    $admin = User::factory()->admin()->create();
    $log = captureSecurityLog();

    $this->actingAs($admin)->put('/admin/settings', [
        'public_assessments_allowed' => false, 'session_lifetime_minutes' => 120,
    ])->assertRedirect();

    $line = securityLine($log, 'admin.settings_updated');
    expect($line['actor']['id'])->toBe($admin->id)
        ->and($line['details'])->toBe(['settings' => ['public_assessments_allowed' => false, 'session_lifetime_minutes' => 120]]);
});

test('a non-admin reaching the admin area is logged as an access denial', function () {
    $user = User::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($user)->get('/admin/users')->assertForbidden();

    $line = securityLine($log, 'authz.denied');
    expect($line['actor']['id'])->toBe($user->id)
        ->and($line['details'])->toBe(['route' => 'admin.users.index', 'method' => 'GET', 'path' => '/admin/users'])
        ->and($line['level'])->toBe('WARNING')
        ->and($line['ip'])->toBe('127.0.0.1');
});

test('a password hash never reaches the log', function () {
    $admin = User::factory()->admin()->create();
    $log = captureSecurityLog();

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'X', 'email' => 'x@example.com', 'is_admin' => false,
    ]);

    $hash = User::query()->where('email', 'x@example.com')->value('password');
    expect(Hash::info($hash)['algoName'])->not->toBe('unknown')
        ->and(json_encode(securityLines($log)))->not->toContain($hash);
});
