<?php

/**
 * W24 — Inertia over-serialization.
 *
 * Inertia ships the **entire** prop bag in the page's `data-page`
 * attribute. Anything a controller puts in a prop is readable with View
 * Source by anyone who can reach the page, whether or not a single Vue
 * component ever renders it. A prop is not hidden by not being displayed.
 *
 * That is not hypothetical here: an owner-email leak of exactly this kind
 * was found and fixed in W17 (see App\Domain\Reporting\ReportAssembler's
 * own comment, "...into a harvestable email address for no rendered
 * benefit"), and again in CommentController's serializeComment(). Both
 * fixes were made without a test that would catch the next one.
 *
 * These tests are that test. They drive the pages an **unauthenticated
 * visitor** can reach through a public assessment — the widest audience in
 * the system — and assert on the raw response body, not on a parsed prop
 * name, so a leak nested anywhere in the tree is caught.
 */

use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Risk;
use App\Models\User;

/**
 * A public assessment owned by one user, commented on by a second, shared
 * with a third. Three distinct email addresses that a guest must never
 * see, plus three password hashes.
 *
 * @return array{0: Assessment, 1: Risk, 2: list<string>}
 */
function publicAssessmentWithOtherPeoplesData(): array
{
    $owner = User::factory()->create();
    $commenter = User::factory()->create();
    $collaborator = User::factory()->create();

    $assessment = Assessment::factory()->create([
        'owner_user_id' => $owner->id,
        'is_public' => true,
    ]);

    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    Comment::factory()->create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'user_id' => $commenter->id,
    ]);

    $assessment->members()->attach($collaborator->id, [
        'role' => 'editor',
        'invited_by' => $owner->id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return [
        $assessment,
        $risk,
        [$owner->email, $commenter->email, $collaborator->email],
        [$owner->password, $commenter->password, $collaborator->password],
    ];
}

it('leaks no email address to a guest reading a public assessment', function () {
    [$assessment, , $emails] = publicAssessmentWithOtherPeoplesData();

    $body = (string) $this->get(route('assessments.show', $assessment))
        ->assertOk()
        ->getContent();

    foreach ($emails as $email) {
        // Inertia HTML-escapes the data-page JSON, so an address would
        // appear with &#64; or the literal @ depending on the escaper.
        // Checking the local-part alone is strictly stronger than
        // checking the full address and immune to either encoding.
        expect($body)->not->toContain(explode('@', $email)[0]);
    }
});

it('leaks no email address on the public report, comment feed or snapshot list', function () {
    [$assessment, $risk, $emails] = publicAssessmentWithOtherPeoplesData();

    $pages = [
        route('assessments.report.show', $assessment),
        route('assessments.comments.index', [$assessment, $risk]),
        route('assessments.comments.unresolved-counts', $assessment),
        route('assessments.snapshots.index', $assessment),
        route('assessments.node-references.index', [$assessment, $risk]),
        route('assessments.report.export-json', $assessment),
    ];

    foreach ($pages as $url) {
        $body = (string) $this->get($url)->getContent();

        foreach ($emails as $email) {
            expect($body)->not->toContain(explode('@', $email)[0], $url);
        }
    }
});

it('never serializes a password hash anywhere a guest can reach', function () {
    [$assessment, $risk, , $hashes] = publicAssessmentWithOtherPeoplesData();

    $pages = [
        '/',
        route('assessments.show', $assessment),
        route('assessments.report.show', $assessment),
        route('assessments.comments.index', [$assessment, $risk]),
    ];

    foreach ($pages as $url) {
        $body = (string) $this->get($url)->getContent();

        expect($body)->not->toContain('$2y$', $url)   // bcrypt
            ->and($body)->not->toContain('$argon2', $url);

        foreach ($hashes as $hash) {
            expect($body)->not->toContain($hash, $url);
        }
    }
});

it('exposes a comment author as id and name only', function () {
    // The precise shape CLAUDE.md's W18 brief mandates: "Comment authors
    // must expose id and name only — never email, never the password
    // hash". Asserted positively so a future refactor that starts
    // serializing the whole User model fails here.
    [$assessment, $risk] = publicAssessmentWithOtherPeoplesData();

    $payload = $this->getJson(route('assessments.comments.index', [$assessment, $risk]))
        ->assertOk()
        ->json();

    $comments = $payload['comments'] ?? $payload;
    $author = data_get($comments, '0.author');

    expect($author)->not->toBeNull()
        ->and(array_keys($author))->toEqualCanonicalizing(['id', 'name']);
});

it('gives a guest no ability flags beyond view on a public assessment', function () {
    // `is_public` grants read only (security.md §3.1, a deliberate change
    // from v1 where public meant world-writable). The client renders its
    // editing affordances off these flags, so a true here is both a UI bug
    // and a signal to an attacker about what to try.
    [$assessment] = publicAssessmentWithOtherPeoplesData();

    $abilities = $this->get(route('assessments.show', $assessment))
        ->assertOk()
        ->viewData('page')['props']['abilities'];

    expect($abilities['comment'])->toBeFalse()
        ->and($abilities['update'])->toBeFalse()
        ->and($abilities['manage'])->toBeFalse();
});

it('shares the authenticated user without their password hash', function () {
    // HandleInertiaRequests::share() puts $request->user() — the whole
    // Eloquent model — into every page's props. That is the user's own
    // record, so their own email is not a leak, but the model's $hidden
    // list is the only thing keeping the password hash out of it.
    $user = User::factory()->create();

    $body = (string) $this->actingAs($user)->get('/dashboard')->getContent();

    expect($body)->toContain($user->name)
        ->and($body)->not->toContain('$2y$')
        ->and($body)->not->toContain($user->password)
        ->and($body)->not->toContain('remember_token');
});
