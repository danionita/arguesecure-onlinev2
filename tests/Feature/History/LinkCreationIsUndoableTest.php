<?php

use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\User;

/*
 * W38: creating an edge is on the undo stack.
 *
 * It was not before. W14 recorded reorder and move from its own route
 * closures, and W13 built LinkController without touching UndoService, so a
 * link created from the node modal's Links tab left no trace on the stack —
 * pressing Ctrl+Z after it would undo whatever unrelated thing came before,
 * which is worse than doing nothing.
 *
 * W38 makes linking a *drag gesture*: one drop, in front of a room, on a
 * projector. That turns a quiet inconsistency into a hazard, which is why the
 * gap is closed here rather than noted.
 *
 * No new applier was needed. `unlink` already exists as the inverse of a link
 * creation and captures the row's own fields before deleting, so a redo
 * restores them exactly — this was a missing caller, not a missing mechanism.
 */

function linkTestEditor(Assessment $assessment): User
{
    $user = User::factory()->create();
    $assessment->members()->attach($user->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $user;
}

test('linking an attack to a second risk records an undoable operation', function () {
    $assessment = Assessment::factory()->create();
    $user = linkTestEditor($assessment);

    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Fraud']);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);

    $this->actingAs($user)
        ->postJson(route('assessments.risk-attack-links.store', $assessment->uuid), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $attack->uuid,
        ])
        ->assertCreated();

    $operation = AssessmentOperation::query()
        ->where('assessment_id', $assessment->id)
        ->latest('id')
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->kind)->toBe('link.create')
        // The stack is shown to a reader who may not have performed the
        // action ("Undo Dana's…"), so the summary has to say where it went.
        ->and($operation->summary)->toBe("Linked 'Phishing' to 'Fraud'")
        ->and($operation->inverse['action'])->toBe('unlink')
        ->and($operation->inverse['edge'])->toBe('risk_attack')
        ->and($operation->inverse['parent_uuid'])->toBe($risk->uuid)
        ->and($operation->inverse['child_uuid'])->toBe($attack->uuid);
});

test('linking a defence to an attack records an undoable operation', function () {
    $assessment = Assessment::factory()->create();
    $user = linkTestEditor($assessment);

    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'MFA']);

    $this->actingAs($user)
        ->postJson(route('assessments.attack-defence-links.store', $assessment->uuid), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
        ])
        ->assertCreated();

    $operation = AssessmentOperation::query()
        ->where('assessment_id', $assessment->id)
        ->latest('id')
        ->first();

    expect($operation->kind)->toBe('link.create')
        ->and($operation->summary)->toBe("Linked 'MFA' to 'Phishing'")
        ->and($operation->inverse['edge'])->toBe('attack_defence');
});

test('undoing a link creation removes the edge and leaves the nodes alone', function () {
    $assessment = Assessment::factory()->create();
    $user = linkTestEditor($assessment);

    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Fraud']);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);

    $this->actingAs($user)
        ->postJson(route('assessments.risk-attack-links.store', $assessment->uuid), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $attack->uuid,
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson(route('assessments.undo.store', $assessment->uuid))
        ->assertOk();

    expect($risk->attacks()->count())->toBe(0)
        // Unlinking is not deleting. The round trip that matters: the attack
        // still exists and can be linked somewhere else.
        ->and(Attack::query()->whereKey($attack->id)->exists())->toBeTrue();
});
