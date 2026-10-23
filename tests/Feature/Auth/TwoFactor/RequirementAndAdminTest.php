<?php

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Identity\TwoFactor\Events\TwoFactorRemovedByAdmin;
use App\Domain\Identity\TwoFactor\TwoFactor;
use App\Http\Controllers\Admin\UserTwoFactorController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/helpers.php';

function requireTwoFactor(bool $on = true): void
{
    InstanceSettings::update(['require_two_factor' => $on]);
}

test('with the requirement off, which it is by default, nothing changes for anyone', function () {
    expect(InstanceSettings::requireTwoFactor())->toBeFalse();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->actingAs($user)->get('/profile')->assertOk();
});

test('with the requirement on, an account without a second factor is sent to enrolment', function (string $uri) {
    requireTwoFactor();
    $user = User::factory()->create();

    $this->actingAs($user)->get($uri)->assertRedirect(route('profile.edit'));
})->with(['/dashboard', '/assessments/shared', '/admin/users', '/help']);

test('with the requirement on, enrolment itself and logout stay reachable', function () {
    requireTwoFactor();
    [$user, $password] = userWithKnownPassword();

    $this->actingAs($user)->get('/profile')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('twoFactor.required', true));
    $this->actingAs($user)->post('/profile/two-factor', ['password' => $password])->assertSessionHasNoErrors();
    $secret = $this->actingAs($user)->get('/profile')->inertiaProps('twoFactor.pending.secret');
    $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => totpCode($secret)])->assertSessionHasNoErrors();

    // Enrolled: the rest of the application opens.
    $this->actingAs($user->refresh())->get('/dashboard')->assertOk();
    $this->actingAs($user)->post('/logout')->assertRedirect('/');
});

test('with the requirement on, logout works without enrolling', function () {
    requireTwoFactor();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

test('with the requirement on, an account that has a second factor is not sent anywhere', function () {
    requireTwoFactor();
    [$user] = userWithTwoFactor();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('the requirement is not a loop for an account that must also change its password', function () {
    requireTwoFactor();
    [$user, $password] = userWithKnownPassword(['must_change_password' => true]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile.edit'));
    $this->actingAs($user)->get('/profile')->assertOk();
    $this->actingAs($user)->put('/password', [
        'current_password' => $password,
        'password' => 'a-brand-new-strong-password-1',
        'password_confirmation' => 'a-brand-new-strong-password-1',
    ])->assertSessionHasNoErrors();
});

test('an admin turns the requirement on and off from the Authentication tab', function () {
    [$admin] = userWithTwoFactor(['is_admin' => true]);

    $this->actingAs($admin)->put('/admin/authentication', ['require_two_factor' => true, 'sso_only' => false])->assertSessionHasNoErrors();
    expect(InstanceSettings::requireTwoFactor())->toBeTrue();

    $this->actingAs($admin)->get('/admin/authentication')->assertInertia(fn (Assert $page) => $page
        ->where('settings.require_two_factor', true));

    $this->actingAs($admin)->put('/admin/authentication', ['require_two_factor' => false, 'sso_only' => false]);
    expect(InstanceSettings::requireTwoFactor())->toBeFalse();
});

test('Instance settings no longer carries the requirement', function () {
    [$admin] = userWithTwoFactor(['is_admin' => true]);

    $this->actingAs($admin)->put('/admin/settings', [
        'public_assessments_allowed' => true, 'session_lifetime_minutes' => 480, 'require_two_factor' => true,
    ])->assertSessionHasNoErrors();
    expect(InstanceSettings::requireTwoFactor())->toBeFalse();

    $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn (Assert $page) => $page
        ->missing('settings.require_two_factor'));
});

test('a non-admin cannot change the requirement', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/admin/authentication', ['require_two_factor' => true, 'sso_only' => false])->assertForbidden();

    expect(InstanceSettings::requireTwoFactor())->toBeFalse();
});

test('the admin user page says whether an account has a second factor, and nothing more', function () {
    [$admin] = userWithKnownPassword(['is_admin' => true]);
    [$with] = userWithTwoFactor();
    $without = User::factory()->create();

    $this->actingAs($admin)->get("/admin/users/{$with->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('targetUser.two_factor_enabled', true));
    $this->actingAs($admin)->get("/admin/users/{$without->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('targetUser.two_factor_enabled', false));
});

test('an admin can remove another account\'s second factor, ending its sessions', function () {
    Event::fake([TwoFactorRemovedByAdmin::class]);
    [$admin] = userWithKnownPassword(['is_admin' => true]);
    [$user, $password] = userWithTwoFactor();
    DB::table('sessions')->insert([
        'id' => 'lost-phone-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.9',
        'user_agent' => 'phone', 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($admin)->delete("/admin/users/{$user->id}/two-factor")->assertRedirect();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
    expect(DB::table('sessions')->where('id', 'lost-phone-session')->exists())->toBeFalse();
    Event::assertDispatched(TwoFactorRemovedByAdmin::class, fn ($e) => $e->user->is($user) && $e->admin->is($admin));

    // The account signs in on its password again (the admin signs out first).
    $this->post('/logout');
    passwordStep($this, $user, $password)->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('a non-admin cannot remove anyone\'s second factor, their own through this route included', function () {
    [$other] = userWithKnownPassword();
    [$victim] = userWithTwoFactor();
    [$self] = userWithTwoFactor();

    $this->actingAs($other)->delete("/admin/users/{$victim->id}/two-factor")->assertForbidden();
    $this->actingAs($self)->delete("/admin/users/{$self->id}/two-factor")->assertForbidden();

    expect($victim->refresh()->hasTwoFactorEnabled())->toBeTrue();
    expect($self->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('a guest cannot reach the admin route', function () {
    [$victim] = userWithTwoFactor();

    $this->delete("/admin/users/{$victim->id}/two-factor")->assertRedirect(route('login'));
    expect($victim->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('the controller refuses a non-admin on its own, not only behind the route group', function () {
    [$other] = userWithKnownPassword();
    [$victim] = userWithTwoFactor();

    $request = Request::create("/admin/users/{$victim->id}/two-factor", 'DELETE');
    $request->setUserResolver(fn () => $other);
    $request->setLaravelSession(app('session.store'));

    expect(fn () => app(UserTwoFactorController::class)
        ->destroy($request, $victim, app(TwoFactor::class)))
        ->toThrow(HttpException::class);

    expect($victim->refresh()->hasTwoFactorEnabled())->toBeTrue();
});
