<?php

use App\Domain\History\UndoService;
use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\RiskAttack;
use App\Models\User;

/*
 * The single linear per-assessment undo stack (ui.md §3.6, schema.md
 * §3.10, PLAN.md W14). CLAUDE.md: "every route touching an assessment goes
 * through AssessmentPolicy" — undo is no exception, and is available to
 * any editor, not only the operation's author.
 */
function attachHistoryMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

/**
 * RiskAttack/AttackDefence exclude their own foreign keys from $fillable
 * (LinkService's docblock) — a mass-assigned create() would silently drop
 * them and fail the NOT NULL constraint, so fixtures assign directly.
 */
function wireRiskAttack(Risk $risk, Attack $attack, int $position): RiskAttack
{
    $link = new RiskAttack;
    $link->risk_node_id = $risk->id;
    $link->attack_node_id = $attack->id;
    $link->position = $position;
    $link->save();

    return $link;
}

function wireAttackDefence(Attack $attack, Defence $defence, int $position): AttackDefence
{
    $link = new AttackDefence;
    $link->attack_node_id = $attack->id;
    $link->defence_node_id = $defence->id;
    $link->position = $position;
    $link->save();

    return $link;
}

test('a guest is rejected from every undo route', function () {
    $assessment = Assessment::factory()->create();

    $this->getJson(route('assessments.undo.show', $assessment))->assertUnauthorized();
    $this->postJson(route('assessments.undo.store', $assessment))->assertUnauthorized();
    $this->postJson(route('assessments.redo.store', $assessment))->assertUnauthorized();
});

test('a viewer cannot peek or undo', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    attachHistoryMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)->getJson(route('assessments.undo.show', $assessment))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('assessments.undo.store', $assessment))->assertForbidden();
});

test('a commenter cannot undo', function () {
    $assessment = Assessment::factory()->create();
    $commenter = User::factory()->create();
    attachHistoryMember($assessment, $commenter, 'commenter');

    $this->actingAs($commenter)->postJson(route('assessments.undo.store', $assessment))->assertForbidden();
});

test('undo restores a deleted node with its links intact', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Data breach']);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    $link = wireRiskAttack($risk, $attack, 0);

    $author = User::factory()->create(['name' => 'Dana']);
    $editor = User::factory()->create(['name' => 'Chris']);
    attachHistoryMember($assessment, $author, 'editor');
    attachHistoryMember($assessment, $editor, 'editor');

    // Simulate what NodeController::destroy() + a caller of
    // UndoService::record() would do together: delete the node, then push
    // the operation that undoes it.
    $attack->delete();
    app(UndoService::class)->record($assessment, $author, 'node.delete', "Deleted attack 'Phishing'", [
        'action' => 'restore_node',
        'node_uuids' => [$attack->uuid],
    ]);

    expect(Attack::withTrashed()->whereKey($attack->id)->first()->trashed())->toBeTrue();

    $peek = $this->actingAs($editor)->getJson(route('assessments.undo.show', $assessment));
    $peek->assertOk();
    expect($peek->json('operation.author'))->toBe('Dana');
    expect($peek->json('operation.summary'))->toBe("Deleted attack 'Phishing'");

    // An editor who did not author the deletion can still undo it.
    $response = $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment));
    $response->assertOk();

    $attack->refresh();
    expect($attack->trashed())->toBeFalse();
    expect(RiskAttack::query()->where('risk_node_id', $risk->id)->where('attack_node_id', $attack->id)->exists())->toBeTrue();
    expect(RiskAttack::query()->find($link->id)->position)->toBe(0);
});

test('undo of a move restores the original edge positions', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attackA = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $attackB = Attack::factory()->create(['assessment_id' => $assessment->id]);
    wireRiskAttack($risk, $attackA, 0);
    wireRiskAttack($risk, $attackB, 1);

    $editor = User::factory()->create();
    attachHistoryMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)->postJson(route('assessments.reorder', $assessment), [
        'scope' => 'risk_attack',
        'parent_uuid' => $risk->uuid,
        'order' => [$attackB->uuid, $attackA->uuid],
    ]);
    $response->assertOk();

    expect(RiskAttack::query()->where('attack_node_id', $attackB->id)->value('position'))->toBe(0);
    expect(RiskAttack::query()->where('attack_node_id', $attackA->id)->value('position'))->toBe(1);

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();

    expect(RiskAttack::query()->where('attack_node_id', $attackA->id)->value('position'))->toBe(0);
    expect(RiskAttack::query()->where('attack_node_id', $attackB->id)->value('position'))->toBe(1);
});

test('undo of a reparent move restores the original parent without disturbing other occurrences', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $otherAttack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    // The defence is shared: it is linked under both $attack and $otherAttack.
    wireAttackDefence($attack, $defence, 0);
    $otherLink = wireAttackDefence($otherAttack, $defence, 0);
    $targetAttack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    $editor = User::factory()->create();
    attachHistoryMember($assessment, $editor, 'editor');

    $this->actingAs($editor)->postJson(route('assessments.move', $assessment), [
        'edge' => 'attack_defence',
        'from_parent_uuid' => $attack->uuid,
        'to_parent_uuid' => $targetAttack->uuid,
        'child_uuid' => $defence->uuid,
    ])->assertOk();

    expect(AttackDefence::query()->where('attack_node_id', $attack->id)->where('defence_node_id', $defence->id)->exists())->toBeFalse();
    expect(AttackDefence::query()->where('attack_node_id', $targetAttack->id)->where('defence_node_id', $defence->id)->exists())->toBeTrue();

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();

    expect(AttackDefence::query()->where('attack_node_id', $attack->id)->where('defence_node_id', $defence->id)->exists())->toBeTrue();
    expect(AttackDefence::query()->where('attack_node_id', $targetAttack->id)->where('defence_node_id', $defence->id)->exists())->toBeFalse();
    // The shared defence's other occurrence was never touched.
    expect(AttackDefence::query()->whereKey($otherLink->id)->exists())->toBeTrue();
});

test('undo is refused with an explicit message when the inverse cannot apply cleanly', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    $editor = User::factory()->create();
    attachHistoryMember($assessment, $editor, 'editor');

    $attack->delete();
    app(UndoService::class)->record($assessment, $editor, 'node.delete', "Deleted attack 'Phishing'", [
        'action' => 'restore_node',
        'node_uuids' => [$attack->uuid],
    ]);

    // Deliberately construct the unclean case: the node is hard-deleted
    // (e.g. a purge job) before anyone undoes the soft-delete above.
    $attack->forceDelete();

    $response = $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('no longer exists');

    // Never half-applied: the operation must still show as not undone.
    $operation = AssessmentOperation::query()->where('assessment_id', $assessment->id)->first();
    expect($operation->undone_at)->toBeNull();
});

test('only the newest operation is undoable, and only structural changes are ever on the stack', function () {
    $assessment = Assessment::factory()->create();
    $riskA = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $riskB = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachHistoryMember($assessment, $editor, 'editor');

    $riskA->delete();
    app(UndoService::class)->record($assessment, $editor, 'node.delete', 'Deleted risk A', [
        'action' => 'restore_node', 'node_uuids' => [$riskA->uuid],
    ]);
    $riskB->delete();
    app(UndoService::class)->record($assessment, $editor, 'node.delete', 'Deleted risk B', [
        'action' => 'restore_node', 'node_uuids' => [$riskB->uuid],
    ]);

    // The newest (risk B's deletion) is undone first.
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    expect($riskB->fresh()->trashed())->toBeFalse();
    expect($riskA->fresh()->trashed())->toBeTrue();

    // Then risk A's.
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    expect($riskA->fresh()->trashed())->toBeFalse();
});

test('a second undo of the same operation is refused', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachHistoryMember($assessment, $editor, 'editor');

    $risk->delete();
    app(UndoService::class)->record($assessment, $editor, 'node.delete', 'Deleted risk', [
        'action' => 'restore_node', 'node_uuids' => [$risk->uuid],
    ]);

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();

    $second = $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment));
    $second->assertStatus(404);
    expect($second->json('message'))->toBe('Nothing to undo.');
});

test('redo re-applies the most recently undone operation as a new stack entry', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachHistoryMember($assessment, $editor, 'editor');

    $risk->delete();
    app(UndoService::class)->record($assessment, $editor, 'node.delete', 'Deleted risk', [
        'action' => 'restore_node', 'node_uuids' => [$risk->uuid],
    ]);

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    expect($risk->fresh()->trashed())->toBeFalse();

    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))->assertOk();
    expect($risk->fresh()->trashed())->toBeTrue();

    // The redo is itself now undoable (schema.md §3.10: "pushed as a new operation").
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    expect($risk->fresh()->trashed())->toBeFalse();
});

test('undoing an empty stack is refused with Nothing to undo', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    attachHistoryMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment));
    $response->assertStatus(404);
    expect($response->json('message'))->toBe('Nothing to undo.');
});
