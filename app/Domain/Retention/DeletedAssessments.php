<?php

namespace App\Domain\Retention;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The *Deleted* list on *My assessments* (W113): every deleted assessment the
 * caller could have deleted, so could restore.
 *
 * Restoring is the `delete` ability and erasing is `forceDelete`, both from
 * AssessmentPolicy, which stays the only authority: the query narrows to
 * owned and member rows (every row, for an admin) and the Gate decides. A
 * viewer's membership reaches the filter and goes no further.
 *
 * Eager-loads `rootNode` for the title, `owner` for the name and `members`
 * for the per-row Gate checks, which would otherwise cost a query a row.
 */
final class DeletedAssessments
{
    /** @return list<array<string, mixed>> */
    public function for(User $user): array
    {
        $days = TrashPurger::retentionDays();

        return Assessment::onlyTrashed()
            ->with(['rootNode', 'owner', 'members:id'])
            ->when(! $user->is_admin, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where('owner_user_id', $user->id)
                    ->orWhereHas('members', fn (Builder $members) => $members->where('users.id', $user->id))
            ))
            ->latest('deleted_at')
            ->latest('id')
            ->get()
            ->filter(fn (Assessment $assessment): bool => Gate::forUser($user)->allows('delete', $assessment))
            ->map(fn (Assessment $assessment): array => [
                'uuid' => $assessment->uuid,
                'title' => $assessment->title ?? 'Untitled assessment',
                'is_owner' => $assessment->owner_user_id === $user->id,
                // Display name only, as on every other list (AssessmentListRows).
                'owner_name' => $assessment->owner?->name,
                'deleted_at' => $assessment->deleted_at?->toJSON(),
                // When the daily purge will erase it, give or take the hour
                // the schedule runs at.
                'erase_after' => $assessment->deleted_at?->copy()->addDays($days)->toJSON(),
                'can_erase' => Gate::forUser($user)->allows('forceDelete', $assessment),
            ])
            ->values()
            ->all();
    }
}
