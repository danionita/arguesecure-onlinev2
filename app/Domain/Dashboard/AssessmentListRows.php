<?php

namespace App\Domain\Dashboard;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The row shape of the two assessment lists — *My assessments* and
 * *Assessments shared with me* (2.5.1).
 *
 * Both pages render `Components/Dashboard/AssessmentList.vue`, so both are
 * built here: two controllers mapping the same row by hand is how one of them
 * grows a field the other lacks, and the page then renders an action on one
 * list that it cannot render on the other.
 *
 * Callers must eager-load `rootNode.tags`, `owner` and `members` — the per-row
 * Gate checks resolve roles through `members`, and without the eager load
 * every row costs a query (the N+1 CLAUDE.md names as the legacy tree
 * render's defining defect).
 */
final class AssessmentListRows
{
    /** The relations every caller must eager-load. */
    public const WITH = ['rootNode.tags:id,name,slug,color', 'owner', 'members:id'];

    /**
     * @param  Collection<int, Assessment>  $assessments
     * @return list<array<string, mixed>>
     */
    public function for(Collection $assessments, User $user): array
    {
        return $assessments->map(fn (Assessment $assessment): array => [
            'uuid' => $assessment->uuid,
            // Assessment::title/description proxy the root node, which a
            // soft-deleted root makes null.
            'title' => $assessment->title ?? 'Untitled assessment',
            'description' => $assessment->description,
            'is_public' => (bool) $assessment->is_public,
            // Names only; a per-assessment colour would make one list read as
            // fifteen unrelated palettes (W43 item 18).
            'tags' => $assessment->rootNode?->tags
                ->pluck('name')->values()->all() ?? [],
            'is_owner' => $assessment->owner_user_id === $user->id,
            // From the policy, never inferred from `is_owner`: a manager who
            // is not the owner can share, an admin who is neither can delete.
            // The routes remain the guard.
            'can_manage' => Gate::forUser($user)->allows('manage', $assessment),
            'can_delete' => Gate::forUser($user)->allows('delete', $assessment),
            // Display name only — an address on a page about other people's
            // work is exactly where one leaks (security.md, W17 report cover).
            'owner_name' => $assessment->owner?->name,
            'updated_at' => $assessment->updated_at?->toJSON(),
        ])->values()->all();
    }
}
