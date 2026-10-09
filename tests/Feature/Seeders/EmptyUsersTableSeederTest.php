<?php

use App\Models\Assessment;
use App\Models\CatalogEntry;
use App\Models\User;
use Database\Seeders\SampleContentSeeder;

/*
 * W22's single most important rule: "a seeded assessment still needs an
 * owner, so resolve that from an existing user (the first admin), and
 * skip seeding with a clear message if the users table is empty rather
 * than inventing one." No credential is ever seeded, so there is nothing
 * for SampleContentSeeder to attach the demo content to when no admin
 * exists — it must skip cleanly, not crash and not create a user.
 */

test('seeding with an empty users table does not create a user and does not crash', function () {
    expect(User::count())->toBe(0);

    (new SampleContentSeeder)->run();

    expect(User::count())->toBe(0)
        ->and(Assessment::count())->toBe(0)
        ->and(CatalogEntry::count())->toBe(0);
});

test('seeding with users but no admin also skips rather than guessing an owner', function () {
    User::factory()->count(3)->create(); // none is_admin

    (new SampleContentSeeder)->run();

    expect(Assessment::count())->toBe(0)
        ->and(CatalogEntry::count())->toBe(0);
});

test('seeding proceeds once an admin exists, even if it did not exist at first call', function () {
    (new SampleContentSeeder)->run();
    expect(Assessment::count())->toBe(0);

    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    expect(Assessment::count())->toBeGreaterThan(0);
});
