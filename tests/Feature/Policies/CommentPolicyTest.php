<?php

use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * CommentPolicy delegates create/view to AssessmentPolicy, and update/delete
 * additionally allows the comment's own author (security.md §3.1). Nothing
 * here decides from the comment's own attributes beyond "who wrote it" —
 * every other signal comes from the owning assessment.
 */
function makeComment(Assessment $assessment, User $author): Comment
{
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);

    return Comment::factory()->create([
        'assessment_id' => $assessment->id,
        'node_id' => $node->id,
        'user_id' => $author->id,
    ]);
}

it('grants comment creation via the assessment comment ability', function () {
    $assessment = Assessment::factory()->create();
    $commenter = User::factory()->create();
    $assessment->members()->attach($commenter->id, [
        'role' => 'commenter',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $comment = makeComment($assessment, $assessment->owner);

    expect(Gate::forUser($commenter)->allows('create', $comment))->toBeTrue()
        ->and(Gate::forUser($commenter)->allows('view', $comment))->toBeTrue();
});

it('denies comment creation to a stranger on a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();
    $comment = makeComment($assessment, $assessment->owner);

    expect(Gate::forUser($stranger)->allows('create', $comment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('view', $comment))->toBeFalse();
});

it('lets the comment author update and delete their own comment even as a plain commenter', function () {
    $assessment = Assessment::factory()->create();
    $author = User::factory()->create();
    $assessment->members()->attach($author->id, [
        'role' => 'commenter',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $comment = makeComment($assessment, $author);

    expect(Gate::forUser($author)->allows('update', $comment))->toBeTrue()
        ->and(Gate::forUser($author)->allows('delete', $comment))->toBeTrue();
});

it('denies another commenter from editing someone else comment without manage rights', function () {
    $assessment = Assessment::factory()->create();
    $author = User::factory()->create();
    $otherCommenter = User::factory()->create();

    foreach ([$author, $otherCommenter] as $user) {
        $assessment->members()->attach($user->id, [
            'role' => 'commenter',
            'invited_by' => $assessment->owner_user_id,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    $comment = makeComment($assessment, $author);

    expect(Gate::forUser($otherCommenter)->allows('update', $comment))->toBeFalse()
        ->and(Gate::forUser($otherCommenter)->allows('delete', $comment))->toBeFalse();
});

it('lets a manager delete anyone comment via assessment manage rights', function () {
    $assessment = Assessment::factory()->create();
    $author = User::factory()->create();
    $manager = User::factory()->create();

    $assessment->members()->attach($author->id, [
        'role' => 'commenter',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $assessment->members()->attach($manager->id, [
        'role' => 'manager',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $comment = makeComment($assessment, $author);

    expect(Gate::forUser($manager)->allows('update', $comment))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('delete', $comment))->toBeTrue();
});
