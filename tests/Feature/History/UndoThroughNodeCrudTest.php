<?php

use App\Models\Assessment;
use App\Models\Node;
use App\Models\Risk;
use App\Models\User;

/*
 * W12-W15 integration: NodeController::store()/destroy() push onto the undo
 * stack, so create and delete are undoable end-to-end from the editor.
 *
 * W14 built the stack and its own reorder/move routes recorded onto it, but
 * NodeController did not — so the two most common structural changes a user
 * makes, adding and deleting a node, produced nothing to undo. Ctrl+Z after
 * deleting a risk silently did nothing, which is worse than not offering undo.
 */
function undoEditor(Assessment $assessment): User
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

test('creating a node is undoable, and undo removes it', function () {
    $assessment = Assessment::factory()->create();
    $editor = undoEditor($assessment);

    $created = $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), [
            'type' => 'risk',
            'title' => 'Data breach',
        ])
        ->assertCreated()
        ->json('node.uuid');

    $this->actingAs($editor)
        ->postJson(route('assessments.undo.store', $assessment))
        ->assertOk();

    expect(Node::query()->where('uuid', $created)->exists())->toBeFalse()
        ->and(Node::withTrashed()->where('uuid', $created)->exists())->toBeTrue();
});

test('deleting a node is undoable, and undo restores it', function () {
    $assessment = Assessment::factory()->create();
    $editor = undoEditor($assessment);
    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'title' => 'Phishing',
    ]);

    $this->actingAs($editor)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $risk]))
        ->assertNoContent();

    expect(Node::query()->whereKey($risk->id)->exists())->toBeFalse();

    $this->actingAs($editor)
        ->postJson(route('assessments.undo.store', $assessment))
        ->assertOk();

    expect(Node::query()->whereKey($risk->id)->exists())->toBeTrue();
});

test('the undo prompt names what was deleted, so a second editor knows what they are reverting', function () {
    $assessment = Assessment::factory()->create();
    $editor = undoEditor($assessment);
    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'title' => 'Phishing',
    ]);

    $this->actingAs($editor)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $risk]))
        ->assertNoContent();

    $peek = $this->actingAs($editor)
        ->getJson(route('assessments.undo.show', $assessment))
        ->assertOk();

    expect($peek->json())->toBeArray();
    expect(json_encode($peek->json()))->toContain('Phishing');
});
