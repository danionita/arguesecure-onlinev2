<?php

use App\Models\Assessment;
use App\Models\CatalogEntry;
use App\Models\User;
use Database\Seeders\SampleContentSeeder;

/*
 * W22's single most important rule survives W141: no credential is ever
 * seeded, so seeding must never create or guess a user. What changed is that
 * the library no longer needs one — templates have no owner — so an empty
 * users table, or users but no admin, no longer means nothing is seeded.
 */

test('seeding with an empty users table seeds the library and does not create a user', function () {
    expect(User::count())->toBe(0);

    (new SampleContentSeeder)->run();

    expect(User::count())->toBe(0)
        ->and(Assessment::count())->toBeGreaterThan(0)
        ->and(Assessment::query()->whereNotNull('owner_user_id')->count())->toBe(0)
        ->and(CatalogEntry::count())->toBeGreaterThan(0);
});

test('seeding with users but no admin attaches nothing to any of them', function () {
    User::factory()->count(3)->create(); // none is_admin

    (new SampleContentSeeder)->run();

    expect(User::count())->toBe(3)
        ->and(Assessment::count())->toBeGreaterThan(0)
        ->and(Assessment::query()->whereNotNull('owner_user_id')->count())->toBe(0);
});

test('seeding an instance that gets an admin later seeds nothing twice', function () {
    (new SampleContentSeeder)->run();
    $count = Assessment::count();

    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    expect(Assessment::count())->toBe($count);
});
