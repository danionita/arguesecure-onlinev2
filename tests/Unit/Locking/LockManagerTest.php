<?php

use App\Domain\Locking\LockManager;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

// tests/Pest.php (outside this package's ownership) only binds
// Tests\TestCase to the Feature directory; LockManager needs the app
// booted for the Redis facade and config, so this file binds it locally
// rather than editing that shared file — a real Redis connection (not a
// mock), matching the W16 brief's "Redis is also running" environment
// note, is the point of keeping this a thin wrapper in the first place.
uses(TestCase::class);

/*
 * PLAN.md Phase 6 item 3: key `lock:{assessment}:{node_uuid}:{field}`,
 * `SET NX PX 45000`, compare-and-extend refresh, compare-and-delete
 * release. Exercised directly against Redis (config/database.php's
 * `redis` connection, not `cache`) rather than through LockController, to
 * isolate the primitive from the HTTP/authorization layer covered in
 * tests/Feature/Locking/LockControllerTest.php.
 */
function lockingIds(): array
{
    return [(string) Str::uuid(), (string) Str::uuid()];
}

afterEach(function () {
    // Best-effort cleanup so repeated local runs don't accumulate keys —
    // not required for correctness (every key here carries its own TTL),
    // but tidy.
    $keys = Redis::keys('lock:*');
    if ($keys !== []) {
        Redis::del($keys);
    }
});

test('acquire on an unheld key succeeds and reports itself as the holder', function () {
    [$assessmentUuid, $nodeUuid] = lockingIds();
    $manager = new LockManager;

    $holder = $manager->acquire($assessmentUuid, $nodeUuid, 'title', 1, 'Alice');

    expect($holder)->toBe(['user_id' => 1, 'name' => 'Alice']);
});

test('a lock cannot be stolen by a second user while held', function () {
    [$assessmentUuid, $nodeUuid] = lockingIds();
    $manager = new LockManager;

    $first = $manager->acquire($assessmentUuid, $nodeUuid, 'title', 1, 'Alice');
    $second = $manager->acquire($assessmentUuid, $nodeUuid, 'title', 2, 'Bob');

    expect($first)->toBe(['user_id' => 1, 'name' => 'Alice'])
        ->and($second)->toBe(['user_id' => 1, 'name' => 'Alice']); // still Alice — Bob's acquire lost the race
});

test('a refresh by a non-holder fails and does not extend or steal the lock', function () {
    [$assessmentUuid, $nodeUuid] = lockingIds();
    $manager = new LockManager;

    $manager->acquire($assessmentUuid, $nodeUuid, 'title', 1, 'Alice');

    $refreshed = $manager->refresh($assessmentUuid, $nodeUuid, 'title', 2);

    expect($refreshed)->toBeFalse()
        ->and($manager->currentHolder($assessmentUuid, $nodeUuid, 'title'))
        ->toBe(['user_id' => 1, 'name' => 'Alice']);
});

test('a refresh by the actual holder succeeds and extends the TTL', function () {
    [$assessmentUuid, $nodeUuid] = lockingIds();
    $manager = new LockManager;
    $key = LockManager::key($assessmentUuid, $nodeUuid, 'title');

    $manager->acquire($assessmentUuid, $nodeUuid, 'title', 1, 'Alice');
    Redis::pexpire($key, 1000); // simulate the TTL having ticked down

    $refreshed = $manager->refresh($assessmentUuid, $nodeUuid, 'title', 1);

    expect($refreshed)->toBeTrue()
        ->and(Redis::pttl($key))->toBeGreaterThan(40000);
});

test('release by a non-holder is a safe no-op and the lock remains held', function () {
    [$assessmentUuid, $nodeUuid] = lockingIds();
    $manager = new LockManager;

    $manager->acquire($assessmentUuid, $nodeUuid, 'title', 1, 'Alice');
    $released = $manager->release($assessmentUuid, $nodeUuid, 'title', 2);

    expect($released)->toBeFalse()
        ->and($manager->currentHolder($assessmentUuid, $nodeUuid, 'title'))
        ->toBe(['user_id' => 1, 'name' => 'Alice']);
});

test('a released lock is immediately acquirable by someone else', function () {
    [$assessmentUuid, $nodeUuid] = lockingIds();
    $manager = new LockManager;

    $manager->acquire($assessmentUuid, $nodeUuid, 'title', 1, 'Alice');
    $released = $manager->release($assessmentUuid, $nodeUuid, 'title', 1);
    $second = $manager->acquire($assessmentUuid, $nodeUuid, 'title', 2, 'Bob');

    expect($released)->toBeTrue()
        ->and($second)->toBe(['user_id' => 2, 'name' => 'Bob']);
});

test('currentHolder is null once a lock expires', function () {
    [$assessmentUuid, $nodeUuid] = lockingIds();
    $manager = new LockManager;
    $key = LockManager::key($assessmentUuid, $nodeUuid, 'title');

    $manager->acquire($assessmentUuid, $nodeUuid, 'title', 1, 'Alice');
    Redis::del($key); // TTL expiry is equivalent to this for LockManager's purposes

    expect($manager->currentHolder($assessmentUuid, $nodeUuid, 'title'))->toBeNull();
});
