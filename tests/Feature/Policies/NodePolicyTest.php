<?php

use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * NodePolicy is a thin delegate to AssessmentPolicy (security.md §3.1,
 * legacy defect #5): it must decide purely from the owning assessment,
 * never from the node's own attributes. These tests prove the delegation by
 * constructing a node whose own attributes would grant nothing (an
 * unrelated stranger has no relationship to the *node*), and showing that
 * access tracks the *assessment's* role/is_public/owner state exactly.
 */
it('grants node view/comment/update by delegating to the owning assessment role', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    expect(Gate::forUser($editor)->allows('view', $risk))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('comment', $risk))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $risk))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('delete', $risk))->toBeTrue();
});

it('denies node access to a stranger on a private assessment regardless of the node itself', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $risk))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('comment', $risk))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $risk))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('delete', $risk))->toBeFalse();
});

it('grants node view only when the assessment is public, never update or delete', function () {
    $assessment = Assessment::factory()->public()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $risk))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('comment', $risk))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $risk))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('delete', $risk))->toBeFalse();
});

/** IDOR: a stranger requesting another user's private node by direct id must never see it. */
it('rejects a direct-object-reference to a node in someone else private assessment', function () {
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id, 'is_public' => false]);
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $risk))->toBeFalse();
});
