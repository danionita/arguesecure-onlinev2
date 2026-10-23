<?php

use App\Domain\History\TimelineReader;
use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\Node;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * W108. Undo, redo, undo, undo used to re-apply the change instead of
 * undoing the operation before it. undo() replaces an operation's `inverse`
 * with the command that would redo it; redo() then cleared that row's
 * `undone_at` and pushed a new row, so the old row was back on the undo
 * stack holding the *redo* command. The fix keeps `undone_at` and sets
 * `redone_at`, which takes the consumed row off both stacks.
 *
 * Every step goes through the routes, and the deletes through
 * NodeController, so the inverses are the ones the editor really records.
 */
function redoEditor(Assessment $assessment): User
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

/**
 * Two deletions, so there is an operation *before* the one that gets
 * undone and redone: the bug only shows on the undo that should reach it.
 *
 * @return array{0: Assessment, 1: User, 2: Risk, 3: Risk}
 */
function redoTwoDeletions(): array
{
    $assessment = Assessment::factory()->create();
    $editor = redoEditor($assessment);
    $first = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    $second = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Ransomware']);

    test()->actingAs($editor)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $first]))
        ->assertNoContent();
    test()->actingAs($editor)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $second]))
        ->assertNoContent();

    return [$assessment, $editor, $first, $second];
}

function redoRiskExists(Risk $risk): bool
{
    return Node::query()->whereKey($risk->id)->exists();
}

test('undo, redo, undo, undo undoes the operation before the redone one', function () {
    [$assessment, $editor, $first, $second] = redoTwoDeletions();

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    expect(redoRiskExists($second))->toBeTrue();

    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))->assertOk();
    expect(redoRiskExists($second))->toBeFalse();

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    expect(redoRiskExists($second))->toBeTrue();

    // Before W108 this re-deleted 'Ransomware' and left 'Phishing' deleted.
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))
        ->assertOk()
        ->assertJsonPath('operation.summary', "deletion of 'Phishing'");
    expect(redoRiskExists($second))->toBeTrue()
        ->and(redoRiskExists($first))->toBeTrue();

    // Both deletions are undone, so the stack is empty.
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))
        ->assertStatus(404)
        ->assertJsonPath('message', 'Nothing to undo.');
});

/**
 * Runs each step a tenth of a second after the last, all inside one second:
 * a quick run of Ctrl+Z and Shift+Ctrl+Z, where `undone_at` at one-second
 * precision tied and an `id` tiebreak picked the wrong row for one of the
 * two orders below.
 *
 * @param  list<string>  $steps
 */
function redoQuickSteps(Assessment $assessment, User $editor, array $steps): void
{
    $base = now()->startOfSecond()->addSecond();

    foreach ($steps as $i => $step) {
        test()->travelTo($base->copy()->addMilliseconds(100 * ($i + 1)));
        test()->actingAs($editor)->postJson(route("assessments.{$step}.store", $assessment))->assertOk();
    }
}

test('redo after that redoes the most recently undone operation, then the one before it', function () {
    [$assessment, $editor, $first, $second] = redoTwoDeletions();

    // The last two undos are the lower id, then the higher one.
    redoQuickSteps($assessment, $editor, ['undo', 'redo', 'undo', 'undo', 'redo']);
    expect(redoRiskExists($first))->toBeFalse()
        ->and(redoRiskExists($second))->toBeTrue();

    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))->assertOk();
    expect(redoRiskExists($first))->toBeFalse()
        ->and(redoRiskExists($second))->toBeFalse();

    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))
        ->assertStatus(404)
        ->assertJsonPath('message', 'Nothing to redo.');
});

test('redo picks the row undone last when a redo pushed a higher id in between', function () {
    [$assessment, $editor, $first, $second] = redoTwoDeletions();

    // Undo 'Ransomware', undo 'Phishing', redo 'Phishing' (a new row), undo
    // that row: of the two undone rows, the one undone last is the higher id.
    redoQuickSteps($assessment, $editor, ['undo', 'undo', 'redo', 'undo', 'redo']);

    expect(redoRiskExists($first))->toBeFalse()
        ->and(redoRiskExists($second))->toBeTrue();
});

test('the consumed row stays undone and is marked redone', function () {
    [$assessment, $editor] = redoTwoDeletions();

    $this->travelTo(Carbon::parse('2026-10-14 12:00:00.250000'));
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    $this->travelTo(Carbon::parse('2026-10-14 12:00:01'));
    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))->assertOk();

    $rows = AssessmentOperation::query()
        ->where('assessment_id', $assessment->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(3);

    [$deleteFirst, $consumed, $pushed] = $rows->all();

    expect($deleteFirst->undone_at)->toBeNull()
        ->and($deleteFirst->redone_at)->toBeNull()
        // The microseconds survive the round trip: redo orders by them.
        ->and($consumed->undone_at?->format('Y-m-d H:i:s.u'))->toBe('2026-10-14 12:00:00.250000')
        ->and($consumed->undone_by)->toBe($editor->id)
        ->and($consumed->redone_at?->format('Y-m-d H:i:s'))->toBe('2026-10-14 12:00:01')
        ->and($pushed->created_at?->format('Y-m-d H:i:s'))->toBe('2026-10-14 12:00:01')
        ->and($pushed->undone_at)->toBeNull()
        ->and($pushed->redone_at)->toBeNull()
        // The pushed row's inverse undoes the redone deletion.
        ->and($pushed->inverse['action'])->toBe('restore_node');
});

test('the History page shows the redone deletion once as live and the original as undone', function () {
    [$assessment, $editor] = redoTwoDeletions();

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))->assertOk();

    $entries = collect(app(TimelineReader::class)->read($assessment)['entries'])
        ->where('source', 'operation')
        ->where('summary', "deletion of 'Ransomware'");

    // Before W108 both rows read as live: one deletion shown twice.
    expect($entries->pluck('undone')->sort()->values()->all())->toBe([false, true]);
});

/*
 * W121 (D83). A new operation ends the redo stack: undo, make a different
 * change, redo used to replay the old inverse over the newer state.
 */
test('W121: a new change after an undo leaves nothing to redo, and the undone rows stay undone', function () {
    [$assessment, $editor, $first, $second] = redoTwoDeletions();
    $third = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Insider']);

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();

    $this->actingAs($editor)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $third]))
        ->assertNoContent();

    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))
        ->assertStatus(404)
        ->assertJsonPath('message', 'Nothing to redo.');

    // The old deletions stay restored; only the new one is deleted.
    expect(redoRiskExists($first))->toBeTrue()
        ->and(redoRiskExists($second))->toBeTrue()
        ->and(redoRiskExists($third))->toBeFalse();

    // History still says they were undone, and Ctrl+Z undoes the new change.
    expect(AssessmentOperation::query()->where('assessment_id', $assessment->id)->whereNotNull('undone_at')->whereNotNull('superseded_at')->count())->toBe(2);
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))
        ->assertOk()
        ->assertJsonPath('operation.summary', "deletion of 'Insider'");
});

test('W121: redo\'s own pushed row does not end the rest of the redo stack', function () {
    [$assessment, $editor, $first, $second] = redoTwoDeletions();

    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    $this->actingAs($editor)->postJson(route('assessments.undo.store', $assessment))->assertOk();
    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))->assertOk();
    $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment))->assertOk();

    expect(redoRiskExists($first))->toBeFalse()
        ->and(redoRiskExists($second))->toBeFalse()
        ->and(AssessmentOperation::query()->whereNotNull('superseded_at')->count())->toBe(0);
});
