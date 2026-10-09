<?php

use App\Domain\Accounts\InstanceSettings;
use App\Models\User;

test('anonymous user cannot view or update instance settings', function () {
    $this->get('/admin/settings')->assertRedirect('/login');
    $this->put('/admin/settings', [])->assertRedirect('/login');
});

test('a non-admin cannot view or update instance settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/settings')->assertForbidden();
    $this->actingAs($user)->put('/admin/settings', [])->assertForbidden();
});

// `default_scale_calibration` used to read 'strict' here — an arbitrary
// string, which was the point: the field was free text nothing consulted.
// W77 made it a reference to a seeded scale set, so the only value an
// unseeded instance may send is the key its column already defaults to
// (App\Domain\Scales\ScaleSets::selectableKeys explains why that one is
// always legal). The set-level assertions live in DefaultScaleCalibrationTest.

test('an admin can view and update instance settings', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/settings')->assertOk();

    $response = $this->actingAs($admin)->put('/admin/settings', [
        'default_scale_calibration' => 'default',
        'public_assessments_allowed' => false,
        'session_lifetime_minutes' => 240,
    ]);

    $response->assertRedirect(route('admin.settings.edit'));

    $updated = InstanceSettings::all();
    expect($updated['public_assessments_allowed'])->toBeFalse();
    expect($updated['session_lifetime_minutes'])->toBe(240);
});

test('instance settings no longer writes the default currency at all', function () {
    /*
     * W87, D47. The default currency moved to the admin **Defaults** tab: it
     * is a fact about the content a new assessment starts with, not about the
     * deployment. It moved rather than being copied, because two controls
     * writing one column with different rules is how they come to disagree.
     *
     * This test used to assert that `TOOLONG` was rejected for its *length*.
     * That was the weaker of the two rules and it asked the wrong question:
     * `size:3` accepts `ZZZ`, which is not a currency, and the value reaches
     * `Intl.NumberFormat` — which throws on an unknown code and would blank
     * every figure on every page. `UpdateDefaultCurrencyRequest` validates
     * against the configured list instead.
     *
     * So what is pinned here is that this endpoint ignores the field entirely:
     * it has no rule, so it never reaches `validated()`, and
     * `InstanceSettings::update()` writes only the keys it is given.
     */
    $admin = User::factory()->admin()->create();

    InstanceSettings::update(['default_currency' => 'EUR']);

    $response = $this->actingAs($admin)->put('/admin/settings', [
        'default_currency' => 'TOOLONG',
        'default_scale_calibration' => 'default',
        'public_assessments_allowed' => true,
        'session_lifetime_minutes' => 240,
    ]);

    $response->assertSessionHasNoErrors();
    expect(InstanceSettings::all()['default_currency'])->toBe('EUR');
});
