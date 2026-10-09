<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;

/**
 * A thin delegate to AssessmentPolicy (schema.md §5). This directly fixes
 * legacy defect #4: `show` actions on risks, attacks and defences had no
 * authorize() call and no global scope at all, so a direct object reference
 * to another user's private node returned 200. Every node route must run
 * through this policy.
 */
class NodePolicy
{
    public function __construct(private readonly AssessmentPolicy $assessments) {}

    public function view(?User $user, Node $node): bool
    {
        return $this->assessments->view($user, $node->assessment);
    }

    public function comment(?User $user, Node $node): bool
    {
        return $this->assessments->comment($user, $node->assessment);
    }

    public function update(?User $user, Node $node): bool
    {
        return $this->assessments->update($user, $node->assessment);
    }

    public function delete(?User $user, Node $node): bool
    {
        // Node soft-delete follows the same ability as an assessment-level
        // structural edit; only `manage` scope actions (rename the
        // assessment, reconfigure scales) need the assessment `manage` gate.
        return $this->assessments->update($user, $node->assessment);
    }
}
