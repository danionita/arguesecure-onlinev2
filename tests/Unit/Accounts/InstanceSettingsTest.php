<?php

use App\Domain\Accounts\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// Backed by a real `instance_settings` table now (W8b) — the class no
// longer touches the cache store at all, which is exactly the gap this
// package closed: a cache flush used to silently revert the instance to
// defaults.
uses(TestCase::class, RefreshDatabase::class);

test('returns sensible defaults when nothing has been set', function () {
    $settings = InstanceSettings::all();

    expect($settings['default_currency'])->toBe('EUR');
    expect($settings['public_assessments_allowed'])->toBeTrue();
});

test('update persists only known keys and leaves the rest untouched', function () {
    InstanceSettings::update([
        'default_currency' => 'USD',
        'not_a_real_setting' => 'ignored',
    ]);

    $settings = InstanceSettings::all();

    expect($settings['default_currency'])->toBe('USD');
    expect($settings)->not->toHaveKey('not_a_real_setting');
    expect($settings)->not->toHaveKey('registration_locked');
    expect($settings['public_assessments_allowed'])->toBeTrue(); // unchanged
});

test('settings survive a cache flush', function () {
    InstanceSettings::update(['default_currency' => 'GBP', 'session_lifetime_minutes' => 120]);

    Cache::flush();

    $settings = InstanceSettings::all();
    expect($settings['default_currency'])->toBe('GBP');
    expect($settings['session_lifetime_minutes'])->toBe(120);
});

test('public assessments allowed helper reflects stored setting', function () {
    expect(InstanceSettings::publicAssessmentsAllowed())->toBeTrue();

    InstanceSettings::update(['public_assessments_allowed' => false]);

    expect(InstanceSettings::publicAssessmentsAllowed())->toBeFalse();
});
