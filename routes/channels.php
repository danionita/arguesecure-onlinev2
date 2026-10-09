<?php

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * W16 (PLAN.md Phase 6, security.md §8) — the single most important
 * architectural fix this package makes. The legacy relay subscribed every
 * anonymous socket to one global channel and re-emitted every event for
 * every assessment, filtering privacy client-side. Here, authorization runs
 * server-side, through the same `AssessmentPolicy` every other route in the
 * application goes through — "no exceptions" (CLAUDE.md).
 *
 * `Broadcast::routes()` (registered in routes/web.php's W16 block) gates the
 * underlying /broadcasting/auth endpoint behind `auth`, so an anonymous
 * caller — including a share-token-only guest, who holds no real account —
 * never reaches this closure at all (security.md §8: "token-only sessions
 * do not join presence channels"). What is checked here is therefore only
 * "does this authenticated user have `view` on this assessment" — the exact
 * same question NodePolicy/AssessmentPolicy answer for every HTTP route.
 *
 * A public assessment's channel is joinable by any authenticated user
 * (AssessmentPolicy::view honours is_public), matching the read access they
 * already have over HTTP; it is not writable or lock-acquirable by that
 * fact alone (CLAUDE.md "is_public grants read only" — enforced separately
 * in LockController via the `update` ability, not this channel).
 *
 * @return array{id: int, name: string}|false
 */
Broadcast::channel('assessment.{assessmentUuid}', function (User $user, string $assessmentUuid) {
    $assessment = Assessment::query()->where('uuid', $assessmentUuid)->first();

    if ($assessment === null) {
        return false;
    }

    if (! $user->can('view', $assessment)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
