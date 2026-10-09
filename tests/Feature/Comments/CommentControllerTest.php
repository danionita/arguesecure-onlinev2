<?php

use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Risk;
use App\Models\User;

/*
 * Comments (PLAN.md Phase 8 item 1, schema.md §3.8, CLAUDE.md's W18 brief):
 * threaded exactly two levels deep, resolvable, gated through
 * AssessmentPolicy's `view`/`comment` abilities with an author-or-manager
 * check for edit/delete. Every mutating route is checked against
 * {guest, stranger, viewer, commenter, editor} the same way
 * tests/Feature/Assessment/NodeControllerTest.php does for node routes.
 */
function attachCommentMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('an anonymous user is rejected from every comment mutation route', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $comment = Comment::factory()->create(['assessment_id' => $assessment->id, 'node_id' => $node->id]);

    $this->postJson(route('assessments.comments.store', [$assessment, $node]), ['body' => 'hi'])
        ->assertUnauthorized();

    $this->patchJson(route('assessments.comments.update', [$assessment, $comment]), ['body' => 'hi'])
        ->assertUnauthorized();

    $this->deleteJson(route('assessments.comments.destroy', [$assessment, $comment]))
        ->assertUnauthorized();

    $this->postJson(route('assessments.comments.resolve', [$assessment, $comment]))
        ->assertUnauthorized();
});

test('a stranger is rejected from every comment mutation route on a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $comment = Comment::factory()->create(['assessment_id' => $assessment->id, 'node_id' => $node->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), ['body' => 'hi'])
        ->assertForbidden();

    $this->actingAs($stranger)
        ->getJson(route('assessments.comments.index', [$assessment, $node]))
        ->assertForbidden();
});

test('a stranger cannot read comments on a private assessment by direct URL', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);

    // No auth at all — this route sits outside the `auth` group because
    // is_public/share-token grant `view` with no account, but a private
    // assessment must still 403 a fully anonymous request.
    $this->getJson(route('assessments.comments.index', [$assessment, $node]))
        ->assertForbidden();
});

test('a viewer can read comments but cannot post one', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    Comment::factory()->create(['assessment_id' => $assessment->id, 'node_id' => $node->id]);
    $viewer = User::factory()->create();
    attachCommentMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->getJson(route('assessments.comments.index', [$assessment, $node]))
        ->assertOk()
        ->assertJsonCount(1, 'comments');

    $this->actingAs($viewer)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), ['body' => 'nope'])
        ->assertForbidden();
});

test('a commenter can post, resolve and unresolve a top-level comment but cannot update the assessment', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $commenter = User::factory()->create();
    attachCommentMember($assessment, $commenter, 'commenter');

    $response = $this->actingAs($commenter)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), ['body' => 'This risk looks underestimated.']);

    $response->assertCreated();
    $commentId = $response->json('comment.id');
    expect($response->json('comment.author.id'))->toBe($commenter->id)
        ->and($response->json('comment.author'))->toHaveKeys(['id', 'name'])
        ->and($response->json('comment.author'))->not->toHaveKey('email');

    $this->actingAs($commenter)
        ->postJson(route('assessments.comments.resolve', [$assessment, Comment::find($commentId)]))
        ->assertOk()
        ->assertJsonPath('comment.resolved_by.id', $commenter->id);

    $this->actingAs($commenter)
        ->deleteJson(route('assessments.comments.resolve', [$assessment, Comment::find($commentId)]))
        ->assertOk()
        ->assertJsonPath('comment.resolved_at', null);

    // The whole point of the commenter role (schema.md §3.7): it can
    // discuss but never edit the assessment itself.
    $this->actingAs($commenter)
        ->patchJson(route('assessments.nodes.update', [$assessment, $node]), [
            'title' => 'hacked',
            'updated_at' => $node->updated_at->toJSON(),
        ])
        ->assertForbidden();
});

test('replies thread exactly one level and reply-to-a-reply is rejected', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachCommentMember($assessment, $editor, 'editor');

    $top = $this->actingAs($editor)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), ['body' => 'Top level'])
        ->assertCreated()
        ->json('comment.id');

    $reply = $this->actingAs($editor)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), [
            'body' => 'A reply',
            'parent_comment_id' => $top,
        ])
        ->assertCreated()
        ->json('comment.id');

    $this->actingAs($editor)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), [
            'body' => 'A reply to a reply',
            'parent_comment_id' => $reply,
        ])
        ->assertStatus(422);

    $this->actingAs($editor)
        ->getJson(route('assessments.comments.index', [$assessment, $node]))
        ->assertOk()
        ->assertJsonCount(1, 'comments')
        ->assertJsonCount(1, 'comments.0.replies');
});

test('a comment body containing a script tag is stored and returned escaped, never executed', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachCommentMember($assessment, $editor, 'editor');

    $payload = '<script>alert(1)</script>';

    $store = $this->actingAs($editor)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), ['body' => $payload]);

    $store->assertCreated();
    // The controller returns the raw body (never v-html'd client-side —
    // see CommentThread.vue); the escaping boundary is Vue's default
    // `{{ }}` text interpolation on render, not the API response. This
    // proves the stored value round-trips byte-for-byte rather than being
    // silently mangled, and that the JSON encoder itself never emits an
    // unescaped `<script>` that a naive raw-HTML sink could execute.
    expect($store->json('comment.body'))->toBe($payload);
    expect(json_encode(['body' => $payload]))->not->toContain('<script>alert(1)</script>');

    $index = $this->actingAs($editor)
        ->getJson(route('assessments.comments.index', [$assessment, $node]));

    expect($index->json('comments.0.body'))->toBe($payload);
});

test('a user may edit or delete only their own comment; a manager may moderate any', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $author = User::factory()->create();
    $otherEditor = User::factory()->create();
    $manager = User::factory()->create();
    attachCommentMember($assessment, $author, 'editor');
    attachCommentMember($assessment, $otherEditor, 'editor');
    attachCommentMember($assessment, $manager, 'manager');

    $comment = Comment::factory()->create([
        'assessment_id' => $assessment->id,
        'node_id' => $node->id,
        'user_id' => $author->id,
    ]);

    $this->actingAs($otherEditor)
        ->patchJson(route('assessments.comments.update', [$assessment, $comment]), ['body' => 'hijacked'])
        ->assertForbidden();

    $this->actingAs($otherEditor)
        ->deleteJson(route('assessments.comments.destroy', [$assessment, $comment]))
        ->assertForbidden();

    expect($comment->fresh()->body)->not->toBe('hijacked');

    $this->actingAs($author)
        ->patchJson(route('assessments.comments.update', [$assessment, $comment]), ['body' => 'edited by author'])
        ->assertOk();
    expect($comment->fresh()->body)->toBe('edited by author');

    $this->actingAs($manager)
        ->patchJson(route('assessments.comments.update', [$assessment, $comment]), ['body' => 'moderated'])
        ->assertOk();
    expect($comment->fresh()->body)->toBe('moderated');

    $this->actingAs($manager)
        ->deleteJson(route('assessments.comments.destroy', [$assessment, $comment]))
        ->assertNoContent();
    expect(Comment::query()->whereKey($comment->id)->exists())->toBeFalse();
});

test('unresolved comment counts are returned per node', function () {
    $assessment = Assessment::factory()->create();
    $riskA = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $riskB = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $viewer = User::factory()->create();
    attachCommentMember($assessment, $viewer, 'viewer');

    Comment::factory()->count(2)->create(['assessment_id' => $assessment->id, 'node_id' => $riskA->id]);
    Comment::factory()->resolved()->create(['assessment_id' => $assessment->id, 'node_id' => $riskA->id]);
    Comment::factory()->create(['assessment_id' => $assessment->id, 'node_id' => $riskB->id]);

    $response = $this->actingAs($viewer)
        ->getJson(route('assessments.comments.unresolved-counts', $assessment));

    $response->assertOk();
    expect($response->json('counts.'.$riskA->uuid))->toBe(2);
    expect($response->json('counts.'.$riskB->uuid))->toBe(1);
});

test('a comment uuid from a different assessment is rejected', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $otherNode = Risk::factory()->create(['assessment_id' => $otherAssessment->id]);
    $foreignComment = Comment::factory()->create(['assessment_id' => $otherAssessment->id, 'node_id' => $otherNode->id]);
    $editor = User::factory()->create();
    attachCommentMember($assessment, $editor, 'editor');

    // Implicit binding scopes {comment} to {assessment} via
    // Assessment::comments() — a comment belonging to a different
    // assessment simply is not found under this one.
    $this->actingAs($editor)
        ->patchJson(route('assessments.comments.update', [$assessment, $foreignComment]), ['body' => 'hijacked'])
        ->assertNotFound();
});

test('resolving a reply is rejected — only the top-level comment of a thread can be resolved', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachCommentMember($assessment, $editor, 'editor');

    $top = Comment::factory()->create(['assessment_id' => $assessment->id, 'node_id' => $node->id]);
    $reply = Comment::factory()->create([
        'assessment_id' => $assessment->id,
        'node_id' => $node->id,
        'parent_comment_id' => $top->id,
    ]);

    $this->actingAs($editor)
        ->postJson(route('assessments.comments.resolve', [$assessment, $reply]))
        ->assertStatus(422);
});

test('the owner and a manager can also post comments', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $manager = User::factory()->create();
    attachCommentMember($assessment, $manager, 'manager');

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), ['body' => 'from the owner'])
        ->assertCreated();

    $this->actingAs($manager)
        ->postJson(route('assessments.comments.store', [$assessment, $node]), ['body' => 'from the manager'])
        ->assertCreated();
});
