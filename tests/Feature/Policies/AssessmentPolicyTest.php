<?php

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The full ability matrix from security.md §3.1. Every ability/role
 * combination is asserted explicitly rather than looped, so a regression
 * names exactly which cell broke.
 */
function attachRole(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

it('grants the admin everything regardless of membership', function () {
    $admin = User::factory()->admin()->create();
    $assessment = Assessment::factory()->create();

    expect(Gate::forUser($admin)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('comment', $assessment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $assessment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('manage', $assessment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $assessment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('transferOwnership', $assessment))->toBeTrue();
});

it('grants the owner everything except transferOwnership is still allowed to the owner', function () {
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);

    expect(Gate::forUser($owner)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('comment', $assessment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $assessment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('manage', $assessment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $assessment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('transferOwnership', $assessment))->toBeTrue();
});

it('grants a manager view/comment/update/manage/delete but not transferOwnership', function () {
    $assessment = Assessment::factory()->create();
    $user = User::factory()->create();
    attachRole($assessment, $user, 'manager');

    expect(Gate::forUser($user)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('comment', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('manage', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('delete', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('transferOwnership', $assessment))->toBeFalse();
});

it('grants an editor view/comment/update but not manage/delete', function () {
    $assessment = Assessment::factory()->create();
    $user = User::factory()->create();
    attachRole($assessment, $user, 'editor');

    expect(Gate::forUser($user)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('comment', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('manage', $assessment))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $assessment))->toBeFalse();
});

it('grants a commenter view/comment but not update/manage/delete', function () {
    $assessment = Assessment::factory()->create();
    $user = User::factory()->create();
    attachRole($assessment, $user, 'commenter');

    expect(Gate::forUser($user)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('comment', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $assessment))->toBeFalse()
        ->and(Gate::forUser($user)->allows('manage', $assessment))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $assessment))->toBeFalse();
});

it('grants a viewer view only', function () {
    $assessment = Assessment::factory()->create();
    $user = User::factory()->create();
    attachRole($assessment, $user, 'viewer');

    expect(Gate::forUser($user)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser($user)->allows('comment', $assessment))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $assessment))->toBeFalse()
        ->and(Gate::forUser($user)->allows('manage', $assessment))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $assessment))->toBeFalse();
});

it('grants an unrelated logged-in user nothing on a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('comment', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('manage', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('delete', $assessment))->toBeFalse();
});

it('denies a guest everything on a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    expect(Gate::forUser(null)->allows('view', $assessment))->toBeFalse()
        ->and(Gate::forUser(null)->allows('comment', $assessment))->toBeFalse()
        ->and(Gate::forUser(null)->allows('update', $assessment))->toBeFalse();
});

/**
 * Legacy defect #4 (security.md §1, §3.1): is_public used to mean
 * world-writable. Any regression toward that behaviour must fail this test.
 */
it('grants is_public view only, never comment, update, manage or delete', function () {
    $assessment = Assessment::factory()->public()->create();
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('comment', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('manage', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('delete', $assessment))->toBeFalse();

    // A guest, too — is_public must not require a session to view.
    expect(Gate::forUser(null)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser(null)->allows('comment', $assessment))->toBeFalse()
        ->and(Gate::forUser(null)->allows('update', $assessment))->toBeFalse();
});

/** security.md §3.2: a valid share token grants view only, exactly like is_public. */
it('grants a valid share token holder view only', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $token = Str::random(43);
    $assessment->share_token = $token;
    $assessment->share_token_created_at = now();
    $assessment->save();
    $assessment->refresh(); // `datetime` columns truncate sub-second precision; compare against what the DB actually stored.

    session(["share_token.{$assessment->id}" => ['token' => $token, 'created_at' => $assessment->share_token_created_at->toJSON()]]);

    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $assessment))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('comment', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('manage', $assessment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('delete', $assessment))->toBeFalse();

    // Works for a guest, too — that is the whole point of a share link.
    expect(Gate::forUser(null)->allows('view', $assessment))->toBeTrue();
});

/**
 * security.md §3.2: revocation invalidates existing sessions by storing the
 * token's created_at in the session and comparing it. A regenerated token
 * (different value, or same value but a later created_at after
 * revoke-then-reissue) must reject the stale session flag.
 */
it('rejects a stale or revoked share token', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $originalToken = Str::random(43);
    $originalCreatedAt = now()->subDay();
    $assessment->share_token = $originalToken;
    $assessment->share_token_created_at = $originalCreatedAt;
    $assessment->save();

    // Session holds the old token/timestamp; the assessment has since been
    // regenerated to a new token with a new created_at.
    session(["share_token.{$assessment->id}" => [
        'token' => $originalToken,
        'created_at' => $originalCreatedAt->toJSON(),
    ]]);

    $assessment->share_token = Str::random(43);
    $assessment->share_token_created_at = now();
    $assessment->save();

    expect(Gate::forUser(null)->allows('view', $assessment))->toBeFalse();
});

it('rejects an assessment with no share token configured even if a session flag exists', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    session(["share_token.{$assessment->id}" => ['token' => 'anything', 'created_at' => now()->toJSON()]]);

    expect(Gate::forUser(null)->allows('view', $assessment))->toBeFalse();
});

/**
 * Task 2, defect investigation: AssessmentPolicy::roleFor() queries
 * `$assessment->members()->where('users.id', $user->id)->value('role')`.
 * BelongsToMany aliases the pivot's `role` column to `pivot_role` in a
 * normal select, and Eloquent's value() replaces the select entirely with
 * the bare column name — so this only works if the resulting single-column
 * query `select role from users inner join assessment_user ...` resolves
 * `role` unambiguously. It does, because `users` has no `role` column of
 * its own (see the 2025_01_01_000001 and _000009 migrations) — `role` can
 * only mean `assessment_user.role`. This test exercises exactly that branch
 * by deliberately NOT eager-loading `members`, so `roleFor()` takes the
 * query path (line ~115) instead of the in-memory `relationLoaded` path.
 */
it('resolves a role-granted ability via a fresh, non-eager-loaded assessment', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    attachRole($assessment, $editor, 'editor');

    // Refetch without eager-loading members, forcing roleFor() through the
    // members()->where(...)->value('role') query branch.
    $fresh = Assessment::query()->findOrFail($assessment->id);
    expect($fresh->relationLoaded('members'))->toBeFalse();

    expect(Gate::forUser($editor)->allows('update', $fresh))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('manage', $fresh))->toBeFalse();

    // And confirm it agrees with the eager-loaded path.
    $eager = Assessment::query()->with('members')->findOrFail($assessment->id);
    expect($eager->relationLoaded('members'))->toBeTrue();
    expect(Gate::forUser($editor)->allows('update', $eager))->toBeTrue();
});

it('grants no role to a user with no membership row, via the non-eager-loaded query branch', function () {
    $assessment = Assessment::factory()->create();
    $stranger = User::factory()->create();

    $fresh = Assessment::query()->findOrFail($assessment->id);
    expect($fresh->relationLoaded('members'))->toBeFalse();

    expect(Gate::forUser($stranger)->allows('update', $fresh))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('view', $fresh))->toBeFalse();
});
