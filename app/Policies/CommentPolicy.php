<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A thin delegate to AssessmentPolicy (schema.md §5). Comment authoring
 * uses the assessment's `comment` ability; editing or deleting a comment is
 * additionally restricted to its own author, an assessment manager/owner,
 * or an admin — nothing here is decided from the comment's own attributes
 * beyond who wrote it.
 */
class CommentPolicy
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

    public function view(?User $user, Comment $comment): bool
    {
        return $this->assessment($user, 'view', $comment->assessment);
    }

    public function create(?User $user, Comment $comment): bool
    {
        return $this->assessment($user, 'comment', $comment->assessment);
    }

    public function update(?User $user, Comment $comment): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->id === $comment->user_id || $this->assessment($user, 'manage', $comment->assessment);
    }

    public function delete(?User $user, Comment $comment): bool
    {
        return $this->update($user, $comment);
    }
}
