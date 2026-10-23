<?php

use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\RiskAttack;
use App\Models\User;

/*
 * W104 — the reorder and move routes authorize `update` on the assessment
 * in the URL, so every node uuid in the body must be resolved through that
 * assessment and no other. The caller below owns assessment A (so holds
 * `update` there) and can only read assessment B; each request names A in
 * the URL and B's nodes in the body.
 *
 * Every test asserts the refusal *and* that B is untouched — positions,
 * edges, and both operations logs — because a 422 answered after the
 * mutation would satisfy a status assertion alone.
 */

/**
 * @return array{caller: User, own: Assessment, other: Assessment}
 */
function scopeFixture(): array
{
    $caller = User::factory()->create();
    $own = Assessment::factory()->create(['owner_user_id' => $caller->id]);
    $other = Assessment::factory()->create();

    // A viewer of B: it knows B's uuids, and may not change B.
    $other->members()->attach($caller->id, [
        'role' => 'viewer',
        'invited_by' => $other->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return ['caller' => $caller, 'own' => $own, 'other' => $other];
}

function scopeRiskAttack(Risk $risk, Attack $attack, int $position): RiskAttack
{
    $link = new RiskAttack;
    $link->risk_node_id = $risk->id;
    $link->attack_node_id = $attack->id;
    $link->position = $position;
    $link->save();

    return $link;
}

function scopeAttackDefence(Attack $attack, Defence $defence, int $position): AttackDefence
{
    $link = new AttackDefence;
    $link->attack_node_id = $attack->id;
    $link->defence_node_id = $defence->id;
    $link->position = $position;
    $link->save();

    return $link;
}

/**
 * Every edge row and node position touching the assessment, so one
 * comparison catches a reorder, an unlink and a relink alike.
 *
 * @return array<string, mixed>
 */
function scopeSnapshot(Assessment $assessment): array
{
    $nodeIds = $assessment->nodes()->withTrashed()->pluck('id');

    return [
        'nodes' => $assessment->nodes()->withTrashed()->orderBy('id')->get(['id', 'position', 'deleted_at'])->toArray(),
        'risk_attack' => RiskAttack::query()->whereIn('risk_node_id', $nodeIds)->orderBy('id')
            ->get(['id', 'risk_node_id', 'attack_node_id', 'position'])->toArray(),
        'attack_defence' => AttackDefence::query()->whereIn('attack_node_id', $nodeIds)->orderBy('id')
            ->get(['id', 'attack_node_id', 'defence_node_id', 'position', 'strength_fraction'])->toArray(),
        'operations' => AssessmentOperation::query()->where('assessment_id', $assessment->id)->count(),
    ];
}

test('reorder of another assessment\'s risks is refused and changes nothing', function () {
    ['caller' => $caller, 'own' => $own, 'other' => $other] = scopeFixture();
    $first = Risk::factory()->create(['assessment_id' => $other->id, 'position' => 0]);
    $second = Risk::factory()->create(['assessment_id' => $other->id, 'position' => 1]);
    $before = scopeSnapshot($other);
    $ownBefore = scopeSnapshot($own);

    $response = $this->actingAs($caller)->postJson(route('assessments.reorder', $own), [
        'scope' => 'nodes',
        'order' => [$second->uuid, $first->uuid],
    ]);

    expect($response->getStatusCode())->toBeIn([404, 422]);
    expect(scopeSnapshot($other))->toBe($before);
    expect(scopeSnapshot($own))->toBe($ownBefore);
});

test('reorder of attacks under another assessment\'s risk is refused and changes nothing', function () {
    ['caller' => $caller, 'own' => $own, 'other' => $other] = scopeFixture();
    $risk = Risk::factory()->create(['assessment_id' => $other->id]);
    $a = Attack::factory()->create(['assessment_id' => $other->id]);
    $b = Attack::factory()->create(['assessment_id' => $other->id]);
    scopeRiskAttack($risk, $a, 0);
    scopeRiskAttack($risk, $b, 1);
    $before = scopeSnapshot($other);
    $ownBefore = scopeSnapshot($own);

    $response = $this->actingAs($caller)->postJson(route('assessments.reorder', $own), [
        'scope' => 'risk_attack',
        'parent_uuid' => $risk->uuid,
        'order' => [$b->uuid, $a->uuid],
    ]);

    expect($response->getStatusCode())->toBeIn([404, 422]);
    expect(scopeSnapshot($other))->toBe($before);
    expect(scopeSnapshot($own))->toBe($ownBefore);
});

test('reorder of defences under another assessment\'s attack is refused and changes nothing', function () {
    ['caller' => $caller, 'own' => $own, 'other' => $other] = scopeFixture();
    $attack = Attack::factory()->create(['assessment_id' => $other->id]);
    $a = Defence::factory()->create(['assessment_id' => $other->id]);
    $b = Defence::factory()->create(['assessment_id' => $other->id]);
    scopeAttackDefence($attack, $a, 0);
    scopeAttackDefence($attack, $b, 1);
    $before = scopeSnapshot($other);
    $ownBefore = scopeSnapshot($own);

    $response = $this->actingAs($caller)->postJson(route('assessments.reorder', $own), [
        'scope' => 'attack_defence',
        'parent_uuid' => $attack->uuid,
        'order' => [$b->uuid, $a->uuid],
    ]);

    expect($response->getStatusCode())->toBeIn([404, 422]);
    expect(scopeSnapshot($other))->toBe($before);
    expect(scopeSnapshot($own))->toBe($ownBefore);
});

test('a move of an attack between another assessment\'s risks is refused and changes nothing', function () {
    ['caller' => $caller, 'own' => $own, 'other' => $other] = scopeFixture();
    $from = Risk::factory()->create(['assessment_id' => $other->id]);
    $to = Risk::factory()->create(['assessment_id' => $other->id]);
    $attack = Attack::factory()->create(['assessment_id' => $other->id]);
    scopeRiskAttack($from, $attack, 0);
    $before = scopeSnapshot($other);
    $ownBefore = scopeSnapshot($own);

    $response = $this->actingAs($caller)->postJson(route('assessments.move', $own), [
        'edge' => 'risk_attack',
        'from_parent_uuid' => $from->uuid,
        'to_parent_uuid' => $to->uuid,
        'child_uuid' => $attack->uuid,
    ]);

    expect($response->getStatusCode())->toBeIn([404, 422]);
    expect(scopeSnapshot($other))->toBe($before);
    expect(scopeSnapshot($own))->toBe($ownBefore);
});

test('a move of a defence between another assessment\'s attacks is refused and changes nothing', function () {
    ['caller' => $caller, 'own' => $own, 'other' => $other] = scopeFixture();
    $from = Attack::factory()->create(['assessment_id' => $other->id]);
    $to = Attack::factory()->create(['assessment_id' => $other->id]);
    $defence = Defence::factory()->create(['assessment_id' => $other->id]);
    $link = scopeAttackDefence($from, $defence, 0);
    $link->forceFill(['strength_mode' => 'quantitative', 'strength_fraction' => 0.5])->save();
    $before = scopeSnapshot($other);
    $ownBefore = scopeSnapshot($own);

    $response = $this->actingAs($caller)->postJson(route('assessments.move', $own), [
        'edge' => 'attack_defence',
        'from_parent_uuid' => $from->uuid,
        'to_parent_uuid' => $to->uuid,
        'child_uuid' => $defence->uuid,
    ]);

    expect($response->getStatusCode())->toBeIn([404, 422]);
    expect(scopeSnapshot($other))->toBe($before);
    expect(scopeSnapshot($own))->toBe($ownBefore);
});

test('a move of one\'s own attack under another assessment\'s risk is refused and leaves the attack where it was', function () {
    // Only the target is foreign: the unlink half would act on the
    // caller's own assessment, so the refusal must come before it.
    ['caller' => $caller, 'own' => $own, 'other' => $other] = scopeFixture();
    $from = Risk::factory()->create(['assessment_id' => $own->id]);
    $attack = Attack::factory()->create(['assessment_id' => $own->id]);
    scopeRiskAttack($from, $attack, 0);
    $to = Risk::factory()->create(['assessment_id' => $other->id]);
    $before = scopeSnapshot($other);
    $ownBefore = scopeSnapshot($own);

    $response = $this->actingAs($caller)->postJson(route('assessments.move', $own), [
        'edge' => 'risk_attack',
        'from_parent_uuid' => $from->uuid,
        'to_parent_uuid' => $to->uuid,
        'child_uuid' => $attack->uuid,
    ]);

    expect($response->getStatusCode())->toBeIn([404, 422]);
    expect(scopeSnapshot($other))->toBe($before);
    expect(scopeSnapshot($own))->toBe($ownBefore);
});

test('reorder and move still work on the route\'s own assessment', function () {
    // The other half of the guard: scoping must not refuse the caller's
    // own nodes.
    ['caller' => $caller, 'own' => $own] = scopeFixture();
    $risk = Risk::factory()->create(['assessment_id' => $own->id]);
    $to = Risk::factory()->create(['assessment_id' => $own->id]);
    $a = Attack::factory()->create(['assessment_id' => $own->id]);
    $b = Attack::factory()->create(['assessment_id' => $own->id]);
    scopeRiskAttack($risk, $a, 0);
    scopeRiskAttack($risk, $b, 1);

    $this->actingAs($caller)->postJson(route('assessments.reorder', $own), [
        'scope' => 'risk_attack',
        'parent_uuid' => $risk->uuid,
        'order' => [$b->uuid, $a->uuid],
    ])->assertOk();

    expect(RiskAttack::query()->where('risk_node_id', $risk->id)->where('attack_node_id', $b->id)->value('position'))->toBe(0);

    $this->actingAs($caller)->postJson(route('assessments.move', $own), [
        'edge' => 'risk_attack',
        'from_parent_uuid' => $risk->uuid,
        'to_parent_uuid' => $to->uuid,
        'child_uuid' => $a->uuid,
    ])->assertOk();

    expect(RiskAttack::query()->where('risk_node_id', $to->id)->where('attack_node_id', $a->id)->exists())->toBeTrue();
    expect(AssessmentOperation::query()->where('assessment_id', $own->id)->count())->toBe(2);
});

test('W121: a move touching a soft-deleted endpoint is refused, and links and records nothing', function (string $trashed) {
    ['caller' => $caller, 'own' => $own] = scopeFixture();
    $from = Risk::factory()->create(['assessment_id' => $own->id]);
    $to = Risk::factory()->create(['assessment_id' => $own->id]);
    $attack = Attack::factory()->create(['assessment_id' => $own->id]);
    scopeRiskAttack($from, $attack, 0);
    ['from' => $from, 'to' => $to, 'child' => $attack][$trashed]->delete();

    $this->actingAs($caller)->postJson(route('assessments.move', $own), [
        'edge' => 'risk_attack',
        'from_parent_uuid' => $from->uuid,
        'to_parent_uuid' => $to->uuid,
        'child_uuid' => $attack->uuid,
    ])->assertStatus(422);

    expect(RiskAttack::query()->where('risk_node_id', $to->id)->exists())->toBeFalse()
        ->and(AssessmentOperation::query()->where('assessment_id', $own->id)->count())->toBe(0);
})->with(['from', 'to', 'child']);
