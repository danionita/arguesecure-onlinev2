<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

/**
 * A thin delegate to AssessmentPolicy (schema.md §5). Comment authoring
 * uses the assessment's `comment` ability; editing or deleting a comment is
 * additionally restricted to its own author, an assessment manager/owner,
 * or an admin — nothing here is decided from the comment's own attributes
 * beyond who wrote it.
 */
class CommentPolicy
{
    public function __construct(private readonly AssessmentPolicy $assessments) {}

    public function view(?User $user, Comment $comment): bool
    {
        return $this->assessments->view($user, $comment->assessment);
    }

    public function create(?User $user, Comment $comment): bool
    {
        return $this->assessments->comment($user, $comment->assessment);
    }

    public function update(?User $user, Comment $comment): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->id === $comment->user_id || $this->assessments->manage($user, $comment->assessment);
    }

    public function delete(?User $user, Comment $comment): bool
    {
        return $this->update($user, $comment);
    }
}
