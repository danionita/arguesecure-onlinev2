<?php

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Real-time authorization (security.md §8) — the single most important
 * architectural fix of the collaboration work. The legacy relay subscribed
 * every anonymous socket to one global channel and re-emitted every event for
 * every assessment, filtering privacy client-side. Here, authorization runs
 * server-side, through the same `AssessmentPolicy` every other route in the
 * application goes through — "no exceptions" (security.md §3).
 *
 * `Broadcast::routes()` (registered in routes/web.php's real-time block) gates the
 * underlying /broadcasting/auth endpoint behind `auth`, so an anonymous
 * caller — including a share-token-only guest, who holds no real account —
 * never reaches this closure at all (security.md §8: "token-only sessions
 * do not join presence channels"). What is checked here is therefore only
 * "is this assessment shared with this authenticated user" —
 * AssessmentPolicy::collaborate: an admin, the owner or a collaborator of any
 * role.
 *
 * A public assessment's channel is NOT joinable on that account alone, and
 * neither is one the account holds a share-link session for: the channel
 * carries live updates and a presence list that names who is working, and
 * those readers can reload to see changes. They keep the read access they
 * have over HTTP (AssessmentPolicy::view).
 *
 * @return array{id: int, name: string}|false
 */
Broadcast::channel('assessment.{assessmentUuid}', function (User $user, string $assessmentUuid) {
    $assessment = Assessment::query()->where('uuid', $assessmentUuid)->first();

    if ($assessment === null) {
        return false;
    }

    if (! $user->can('collaborate', $assessment)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
