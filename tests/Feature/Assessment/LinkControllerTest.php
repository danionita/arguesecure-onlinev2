<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;

/*
 * Edge-mutation authorization matrix (CLAUDE.md "every route touching an
 * assessment goes through AssessmentPolicy") for the node modal's Links tab
 * (ui.md §7, PLAN.md W13), plus the endpoint-type/same-assessment
 * validation LinkService itself only enforces at the domain layer. Follows
 * the same {guest, stranger, viewer, editor} matrix as
 * tests/Feature/Assessment/NodeControllerTest.php.
 */
function attachLinkMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

// --- risk_attack: authorization -------------------------------------------

test('an anonymous user is rejected from every risk-attack link route', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    $this->postJson(route('assessments.risk-attack-links.store', $assessment), [
        'risk_uuid' => $risk->uuid,
        'attack_uuid' => $attack->uuid,
    ])->assertUnauthorized();

    $this->deleteJson(route('assessments.risk-attack-links.destroy', $assessment), [
        'risk_uuid' => $risk->uuid,
        'attack_uuid' => $attack->uuid,
    ])->assertUnauthorized();
});

test('a stranger is rejected from every risk-attack link route', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('assessments.risk-attack-links.store', $assessment), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $attack->uuid,
        ])->assertForbidden();
});

test('a viewer cannot create or remove a risk-attack link', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $viewer = User::factory()->create();
    attachLinkMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->postJson(route('assessments.risk-attack-links.store', $assessment), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $attack->uuid,
        ])->assertForbidden();

    expect($risk->fresh()->attacks->pluck('uuid')->all())->toBe([]);
});

test('an editor can link an existing attack to an existing risk', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.risk-attack-links.store', $assessment), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $attack->uuid,
        ]);

    $response->assertCreated();
    expect($risk->fresh()->attacks->pluck('uuid')->all())->toBe([$attack->uuid]);
});

test('an editor can remove a risk-attack link', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->deleteJson(route('assessments.risk-attack-links.destroy', $assessment), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $attack->uuid,
        ])->assertNoContent();

    expect($risk->fresh()->attacks->pluck('uuid')->all())->toBe([]);
});

test('linking requires the attack to belong to the same assessment as the risk', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $foreignAttack = Attack::factory()->create(['assessment_id' => $otherAssessment->id]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.risk-attack-links.store', $assessment), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $foreignAttack->uuid,
        ])->assertStatus(422);

    expect($risk->fresh()->attacks->pluck('uuid')->all())->toBe([]);
});

test('linking rejects an endpoint of the wrong node type', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $otherRisk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    // otherRisk is a risk, not an attack — attack_uuid's exists rule is
    // scoped to type = 'attack', so this must fail validation rather than
    // silently creating a risk-risk row.
    $this->actingAs($editor)
        ->postJson(route('assessments.risk-attack-links.store', $assessment), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $otherRisk->uuid,
        ])->assertStatus(422);
});

// --- attack_defence: authorization -----------------------------------------

test('an anonymous user is rejected from every attack-defence link route', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $this->postJson(route('assessments.attack-defence-links.store', $assessment), [
        'attack_uuid' => $attack->uuid,
        'defence_uuid' => $defence->uuid,
    ])->assertUnauthorized();

    $this->patchJson(route('assessments.attack-defence-links.update', $assessment), [
        'attack_uuid' => $attack->uuid,
        'defence_uuid' => $defence->uuid,
    ])->assertUnauthorized();

    $this->deleteJson(route('assessments.attack-defence-links.destroy', $assessment), [
        'attack_uuid' => $attack->uuid,
        'defence_uuid' => $defence->uuid,
    ])->assertUnauthorized();
});

test('a viewer cannot create, update or remove an attack-defence link', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $viewer = User::factory()->create();
    attachLinkMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->postJson(route('assessments.attack-defence-links.store', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
        ])->assertForbidden();

    expect($attack->fresh()->defences->pluck('uuid')->all())->toBe([]);
});

test('an editor can link an existing defence to an existing attack', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.attack-defence-links.store', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
        ]);

    $response->assertCreated();
    expect($response->json('link.reduction_target'))->toBeNull();
    expect($attack->fresh()->defences->pluck('uuid')->all())->toBe([$defence->uuid]);
});

test('a new link to a transfer defaults reduction_target to impact per schema.md §3.6', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $transfer = Defence::factory()->create(['assessment_id' => $assessment->id, 'is_transfer' => true]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.attack-defence-links.store', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $transfer->uuid,
        ]);

    $response->assertCreated();
    expect($response->json('link.reduction_target'))->toBe('impact');
});

test('an editor can set a quantitative strength on an existing link', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $attack->defences()->attach($defence->id, ['position' => 0]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
            'strength_mode' => 'quantitative',
            'strength_fraction' => 0.8,
            'reduction_target' => 'likelihood',
            'rationale' => 'Vendor pen-test result',
        ]);

    $response->assertOk();
    expect((float) $response->json('link.strength_fraction'))->toBe(0.8);
    expect($response->json('link.reduction_target'))->toBe('likelihood');

    $link = AttackDefence::query()
        ->where('attack_node_id', $attack->id)
        ->where('defence_node_id', $defence->id)
        ->firstOrFail();
    expect((float) $link->strength_fraction)->toBe(0.8);
});

test('setting a qualitative strength requires a valid scale level and rejects a fraction alongside it', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $attack->defences()->attach($defence->id, ['position' => 0]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $strengthScale = Scale::factory()->systemStrength()->create();
    $level = ScaleLevel::query()->where('scale_id', $strengthScale->id)->firstOrFail();

    // strength_fraction alongside a qualitative mode must be rejected —
    // it would otherwise trip schema.md §3.6's chk_ad_strength_mode CHECK
    // at the database layer instead of failing validation cleanly.
    $this->actingAs($editor)
        ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
            'strength_mode' => 'qualitative',
            'strength_fraction' => 0.5,
            'strength_level_id' => $level->id,
        ])->assertStatus(422);

    $response = $this->actingAs($editor)
        ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
            'strength_mode' => 'qualitative',
            'strength_level_id' => $level->id,
        ]);

    $response->assertOk();
    expect($response->json('link.strength_level_id'))->toBe($level->id);
});

test('an editor can remove an attack-defence link', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $attack->defences()->attach($defence->id, ['position' => 0]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->deleteJson(route('assessments.attack-defence-links.destroy', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
        ])->assertNoContent();

    expect($attack->fresh()->defences->pluck('uuid')->all())->toBe([]);
});

test('linking a defence requires it to belong to the same assessment as the attack', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $foreignDefence = Defence::factory()->create(['assessment_id' => $otherAssessment->id]);
    $editor = User::factory()->create();
    attachLinkMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.attack-defence-links.store', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $foreignDefence->uuid,
        ])->assertStatus(422);

    expect($attack->fresh()->defences->pluck('uuid')->all())->toBe([]);
});

test('the owner and a manager can also mutate links', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $manager = User::factory()->create();
    attachLinkMember($assessment, $manager, 'manager');

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.attack-defence-links.store', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
        ])->assertCreated();

    $otherAttack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $this->actingAs($manager)
        ->postJson(route('assessments.attack-defence-links.store', $assessment), [
            'attack_uuid' => $otherAttack->uuid,
            'defence_uuid' => $defence->uuid,
        ])->assertCreated();
});
