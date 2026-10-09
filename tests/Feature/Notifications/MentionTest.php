<?php

use App\Domain\Comments\CommentService;
use App\Domain\Notifications\MentionedInComment;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;

/*
 * Mentions (W47, item 15).
 *
 * The security content of this package is the candidate set: mentions match
 * against **collaborators on that assessment only**. Matching against `users`
 * would turn any comment box into an account-existence oracle — type `@dana`,
 * see whether anything happens, and you have learned that a Dana has an
 * account, from inside an assessment she has nothing to do with. That gets its
 * own test rather than a comment.
 */
function mentionSetup(string $name = 'Dana Okonkwo'): array
{
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $author = User::factory()->create(['name' => 'Sam Author']);
    $mentioned = User::factory()->create(['name' => $name]);

    foreach ([$author, $mentioned] as $member) {
        $assessment->members()->attach($member->id, [
            'role' => 'editor',
            'invited_by' => $assessment->owner_user_id,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    return [$assessment, $node, $author, $mentioned];
}

function comment(Assessment $assessment, $node, ?User $author, string $body)
{
    return app(CommentService::class)->create($assessment, $node, $author, $body);
}

// --- the rule ------------------------------------------------------------

test('@dana notifies Dana when Dana is a collaborator', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();

    comment($assessment, $node, $author, 'Can you look at this @Dana?');

    expect($dana->fresh()->unreadNotifications()->count())->toBe(1);
});

test('@dana notifies nobody when Dana is not a collaborator', function () {
    [$assessment, $node, $author] = mentionSetup();
    $outsider = User::factory()->create(['name' => 'Dana Stranger']);

    comment($assessment, $node, $author, 'Hello @Dana Stranger');

    // The assertion that matters: not merely that she was not notified, but
    // that nothing anywhere was written about her.
    expect($outsider->fresh()->notifications()->count())->toBe(0);
});

test('a name that exists only on another assessment is never matched', function () {
    [$assessment, $node, $author] = mentionSetup();

    $other = Assessment::factory()->create();
    $elsewhere = User::factory()->create(['name' => 'Kim Elsewhere']);
    $other->members()->attach($elsewhere->id, [
        'role' => 'editor',
        'invited_by' => $other->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    comment($assessment, $node, $author, 'What do you think @Kim?');

    expect($elsewhere->fresh()->notifications()->count())->toBe(0);
});

test('the owner can be mentioned even though they are not a collaborator row', function () {
    [$assessment, $node, $author] = mentionSetup();
    $assessment->owner->update(['name' => 'Priya Owner']);

    comment($assessment, $node, $author, 'Signing off? @Priya');

    expect($assessment->owner->fresh()->unreadNotifications()->count())->toBe(1);
});

test('a deactivated collaborator is never notified', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();
    // Property assignment, not update(['is_active' => …]): `is_active` is
    // deliberately absent from User::$fillable, so a mass assignment would be
    // dropped in silence and this test would pass by never deactivating her.
    $dana->is_active = false;
    $dana->save();

    comment($assessment, $node, $author, '@Dana please review');

    expect($dana->fresh()->notifications()->count())->toBe(0);
});

// --- matching forms ------------------------------------------------------

test('the full name, the spaceless name and a unique first name all match', function () {
    foreach (['@Dana Okonkwo', '@DanaOkonkwo', '@dana'] as $form) {
        [$assessment, $node, $author, $dana] = mentionSetup();

        comment($assessment, $node, $author, "Please check {$form} thanks");

        expect($dana->fresh()->unreadNotifications()->count())
            ->toBe(1, "form {$form} should have matched");
    }
});

test('a prefix of a name does not match it', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();

    comment($assessment, $node, $author, 'Ask @Dan about it');

    expect($dana->fresh()->notifications()->count())->toBe(0);
});

/*
 * Two Danas make `@dana` unresolvable. Guessing sends the message to the wrong
 * person and notifying both sends half of them something that was not for
 * them; both failures are invisible to the author. The full name still works,
 * which is what to type when there are two Danas in the room.
 */
test('an ambiguous first name notifies nobody', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();
    $second = User::factory()->create(['name' => 'Dana Petrov']);
    $assessment->members()->attach($second->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    comment($assessment, $node, $author, 'Over to you @Dana');

    expect($dana->fresh()->notifications()->count())->toBe(0)
        ->and($second->fresh()->notifications()->count())->toBe(0);
});

test('the full name still resolves when the first name is ambiguous', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();
    $second = User::factory()->create(['name' => 'Dana Petrov']);
    $assessment->members()->attach($second->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    comment($assessment, $node, $author, 'Over to you @Dana Okonkwo');

    expect($dana->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($second->fresh()->notifications()->count())->toBe(0);
});

test('a name containing regex metacharacters is matched literally', function () {
    [$assessment, $node, $author] = mentionSetup();
    $odd = User::factory()->create(['name' => 'A.C Reeves']);
    $assessment->members()->attach($odd->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    // An unescaped `.` would make `@AXC` match too. It must not.
    comment($assessment, $node, $author, 'Ping @AXC Reeves');
    expect($odd->fresh()->notifications()->count())->toBe(0);

    comment($assessment, $node, $author, 'Ping @A.C Reeves');
    expect($odd->fresh()->unreadNotifications()->count())->toBe(1);
});

// --- never twice, and never yourself -------------------------------------

test('editing a comment does not notify the same person again', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();

    $created = comment($assessment, $node, $author, 'Please review @Dana');
    expect($dana->fresh()->notifications()->count())->toBe(1);

    app(CommentService::class)->updateBody($created, 'Please review this @Dana — typo fixed');

    expect($dana->fresh()->notifications()->count())->toBe(1);
});

test('an edit that names someone new does notify them', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();
    $kim = User::factory()->create(['name' => 'Kim Novak']);
    $assessment->members()->attach($kim->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $created = comment($assessment, $node, $author, 'Please review @Dana');
    app(CommentService::class)->updateBody($created, 'Please review @Dana and @Kim');

    expect($dana->fresh()->notifications()->count())->toBe(1)
        ->and($kim->fresh()->unreadNotifications()->count())->toBe(1);
});

test('naming yourself notifies nobody', function () {
    [$assessment, $node, $author] = mentionSetup();

    comment($assessment, $node, $author, 'Note to self: @Sam Author');

    expect($author->fresh()->notifications()->count())->toBe(0);
});

test('the same person named twice in one comment is one notification', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();

    comment($assessment, $node, $author, '@Dana — and again, @Dana Okonkwo');

    expect($dana->fresh()->notifications()->count())->toBe(1);
});

// --- what the payload carries --------------------------------------------

test('the notification carries the node deep link and no email address', function () {
    [$assessment, $node, $author, $dana] = mentionSetup();

    comment($assessment, $node, $author, 'Please review @Dana');

    $notification = $dana->fresh()->notifications()->firstOrFail();

    expect($notification->type)->toBe(MentionedInComment::class)
        ->and($notification->data['kind'])->toBe('mention')
        ->and($notification->data['assessment_uuid'])->toBe($assessment->uuid)
        ->and($notification->data['node_uuid'])->toBe($node->uuid)
        ->and($notification->data['author'])->toBe('Sam Author')
        ->and(json_encode($notification->data))->not->toContain($author->email);
});
