<?php

/**
 * W115: a password the admin did not type reaches the admin, once, and is
 * good for one sign-in.
 *
 * Until W115 `UserController` wrote `->with('generatedPassword', …)` and
 * nothing passed the session's flash bag to the page, so leaving the password
 * blank created an account nobody knew the password of. The table's banner
 * existed and never had anything to show.
 *
 * "Once" is asserted as two requests, not one: the page after the redirect
 * carries the password, and the next one does not. Inertia's flash data is
 * also stripped from browser history on the client, which no feature test can
 * see; `tests/js/admin/generatedPassword.spec.ts` covers the page's half.
 */

use App\Domain\Accounts\CsvUserImporter;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** The users table as the admin's browser loads it after a redirect. */
function usersTablePage(User $admin): array
{
    return test()->actingAs($admin)->get(route('admin.users.index'))->viewData('page');
}

test('creating a user without a password shows the generated password exactly once', function () {
    $admin = User::factory()->admin()->create();

    test()->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'New Person',
        'email' => 'new-person@example.com',
        'is_admin' => false,
    ])->assertRedirect(route('admin.users.index'));

    $shown = usersTablePage($admin)['flash']['generatedPassword'] ?? null;
    $created = User::query()->where('email', 'new-person@example.com')->firstOrFail();

    // The specified value: the password that account actually has, not
    // merely a string where one should be.
    expect($shown)->toBeString()
        ->and(Hash::check($shown, $created->password))->toBeTrue();

    expect(usersTablePage($admin))->not->toHaveKey('flash');
});

test('a user created with a generated password must change it at their first sign-in', function () {
    $admin = User::factory()->admin()->create();

    test()->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'New Person',
        'email' => 'new-person@example.com',
        'is_admin' => false,
    ]);

    $created = User::query()->where('email', 'new-person@example.com')->firstOrFail();

    expect($created->must_change_password)->toBeTrue();

    // And the flag does what it says: the account's first page after
    // signing in is the change-password screen, not the dashboard.
    test()->actingAs($created)->get(route('dashboard'))
        ->assertRedirect(route('profile.edit'));
});

test('a user created with a typed password is not forced to change it, and nothing is shown', function () {
    $admin = User::factory()->admin()->create();
    $password = Str::password(20);

    test()->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'New Person',
        'email' => 'new-person@example.com',
        'is_admin' => false,
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertRedirect(route('admin.users.index'));

    $created = User::query()->where('email', 'new-person@example.com')->firstOrFail();

    expect($created->must_change_password)->toBeFalse()
        ->and(Hash::check($password, $created->password))->toBeTrue();

    // The admin typed it; echoing it back onto a page would only put it in
    // one more place.
    expect(usersTablePage($admin))->not->toHaveKey('flash');
});

test('a password reset without a password shows the generated one exactly once', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    test()->actingAs($admin)
        ->post(route('admin.users.reset-password', $target))
        ->assertRedirect(route('admin.users.index'));

    $shown = usersTablePage($admin)['flash']['generatedPassword'] ?? null;

    expect($shown)->toBeString()
        ->and(Hash::check($shown, $target->fresh()->password))->toBeTrue()
        ->and($target->fresh()->must_change_password)->toBeTrue();

    expect(usersTablePage($admin))->not->toHaveKey('flash');
});

test('a CSV import sets every created account to change its password at first sign-in', function () {
    $admin = User::factory()->admin()->create();

    $csv = implode("\n", [
        'name,email,admin',
        'Alice Example,alice@example.com,false',
        'Bob Example,bob@example.com,true',
    ]);

    test()->actingAs($admin)
        ->post(route('admin.users.import.commit'), ['csv_text' => $csv], ['X-Inertia' => 'true'])
        ->assertOk();

    $flags = User::query()
        ->whereIn('email', ['alice@example.com', 'bob@example.com'])
        ->pluck('must_change_password', 'email')
        ->all();

    expect($flags)->toBe(['alice@example.com' => true, 'bob@example.com' => true]);
});

test('the importer itself sets the flag, whichever controller calls it', function () {
    $importer = new CsvUserImporter;

    $created = $importer->commit($importer->parse("name,email\nCarol Example,carol@example.com"));

    expect($created)->toHaveCount(1)
        ->and($created[0]['user']->fresh()->must_change_password)->toBeTrue();
});

test('a success message another admin screen flashes reaches the next page as flash data', function () {
    $admin = User::factory()->admin()->create();

    // DefaultsController::updateCurrency writes `->with('success', …)` and
    // nothing else. The general path, not the password's own.
    test()->actingAs($admin)
        ->put(route('admin.defaults.currency'), ['default_currency' => 'USD'])
        ->assertRedirect();

    $page = test()->actingAs($admin)->get(route('admin.defaults.edit'))->viewData('page');

    expect($page['flash'] ?? null)->toBe(['success' => 'Default currency saved.']);
});
