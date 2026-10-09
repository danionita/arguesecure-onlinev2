<?php

use App\Events\LinkChanged;
use App\Events\NodeCreated;
use App\Events\NodeDeleted;
use App\Events\NodeUpdated;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/*
 * PLAN.md Phase 6 item 4 / ui.md §6: every node and link mutation
 * broadcasts the changed data itself, not merely an id — the explicit
 * "do not repeat" of the legacy id-then-refetch design. These events are
 * dispatched from App\Observers\NodeObserver/RiskAttackObserver/
 * AttackDefenceObserver (registered in bootstrap/app.php), which fire on
 * every path that mutates a node or an edge through Eloquent, not just
 * NodeController/LinkController specifically.
 */
function w16Editor(Assessment $assessment): User
{
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $editor;
}

test('creating a node broadcasts NodeCreated carrying the full node, not just an id', function () {
    Event::fake([NodeCreated::class]);
    $assessment = Assessment::factory()->create();
    $editor = w16Editor($assessment);

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk', 'title' => 'New risk'])
        ->assertCreated();

    Event::assertDispatched(NodeCreated::class, function (NodeCreated $event) use ($assessment, $editor) {
        return $event->assessmentUuid === $assessment->uuid
            && $event->node['title'] === 'New risk'
            && $event->node['type'] === 'risk'
            && $event->actorId === $editor->id;
    });
});

test('updating a node broadcasts NodeUpdated carrying the updated row', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old title']);
    $editor = w16Editor($assessment);

    Event::fake([NodeUpdated::class]);

    $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'New title',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertOk();

    Event::assertDispatched(NodeUpdated::class, fn (NodeUpdated $event) => $event->assessmentUuid === $assessment->uuid
        && $event->node['uuid'] === $risk->uuid
        && $event->node['title'] === 'New title');
});

test('deleting a node broadcasts NodeDeleted carrying only its identity', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = w16Editor($assessment);

    Event::fake([NodeDeleted::class]);

    $this->actingAs($editor)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $risk]))
        ->assertNoContent();

    Event::assertDispatched(NodeDeleted::class, fn (NodeDeleted $event) => $event->assessmentUuid === $assessment->uuid
        && $event->nodeUuid === $risk->uuid);
});

test('creating a risk-attack link broadcasts LinkChanged', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $editor = w16Editor($assessment);

    Event::fake([LinkChanged::class]);

    $this->actingAs($editor)
        ->postJson(route('assessments.risk-attack-links.store', $assessment), [
            'risk_uuid' => $risk->uuid,
            'attack_uuid' => $attack->uuid,
        ])
        ->assertCreated();

    Event::assertDispatched(LinkChanged::class, fn (LinkChanged $event) => $event->edge === 'risk_attack'
        && $event->action === 'created'
        && $event->link['risk_uuid'] === $risk->uuid
        && $event->link['attack_uuid'] === $attack->uuid);
});

test('editing an attack-defence link field broadcasts LinkChanged as updated', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $editor = w16Editor($assessment);

    $this->actingAs($editor)
        ->postJson(route('assessments.attack-defence-links.store', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
        ])
        ->assertCreated();

    Event::fake([LinkChanged::class]);

    $this->actingAs($editor)
        ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
            'strength_mode' => 'quantitative',
            'strength_fraction' => 0.5,
        ])
        ->assertOk();

    Event::assertDispatched(LinkChanged::class, fn (LinkChanged $event) => $event->edge === 'attack_defence'
        && $event->action === 'updated'
        && $event->link['strength_fraction'] === 0.5);
});
