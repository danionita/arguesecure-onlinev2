<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A thin delegate to AssessmentPolicy (schema.md §5). This directly fixes
 * legacy defect #4: `show` actions on risks, attacks and defences had no
 * authorize() call and no global scope at all, so a direct object reference
 * to another user's private node returned 200. Every node route must run
 * through this policy.
 */
class NodePolicy
{
    /**
     * Goes through the Gate rather than calling AssessmentPolicy's methods, so
     * its `before()` hook (the template rule, W141) applies here too: a direct
     * method call skips it, which let a stray collaborator row edit a template.
     */
    private function assessment(?User $user, string $ability, Assessment $assessment): bool
    {
        return Gate::forUser($user)->allows($ability, $assessment);
    }

    public function view(?User $user, Node $node): bool
    {
        return $this->assessment($user, 'view', $node->assessment);
    }

    public function comment(?User $user, Node $node): bool
    {
        return $this->assessment($user, 'comment', $node->assessment);
    }

    public function update(?User $user, Node $node): bool
    {
        return $this->assessment($user, 'update', $node->assessment);
    }

    public function delete(?User $user, Node $node): bool
    {
        // Node soft-delete follows the same ability as an assessment-level
        // structural edit; only `manage` scope actions (rename the
        // assessment, reconfigure scales) need the assessment `manage` gate.
        return $this->assessment($user, 'update', $node->assessment);
    }
}
