<?php

use App\Domain\History\Commands\CommandDispatcher;
use App\Domain\History\Exceptions\UndoConflictException;
use App\Domain\Snapshots\SnapshotService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RiskAttack;

/*
 * W121 (D82). The `inverse` of an operation is written only by server code,
 * so no request reaches an applier with a foreign uuid today. These tests
 * hand each applier the command a corrupted or restored row could hold, with
 * every uuid belonging to *another* assessment, and assert that the applier
 * answers "does not exist" and leaves that assessment as it was.
 */
function scopeLinked(Assessment $assessment): array
{
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $ra = new RiskAttack;
    $ra->risk_node_id = $risk->id;
    $ra->attack_node_id = $attack->id;
    $ra->position = 3;
    $ra->save();

    $ad = new AttackDefence;
    $ad->attack_node_id = $attack->id;
    $ad->defence_node_id = $defence->id;
    $ad->position = 4;
    $ad->save();

    return [$risk, $attack, $defence];
}

function scopeState(): array
{
    return [
        Node::withTrashed()->orderBy('id')->get(['id', 'deleted_at', 'position'])->toArray(),
        RiskAttack::query()->orderBy('risk_node_id')->get()->toArray(),
        AttackDefence::query()->orderBy('attack_node_id')->get()->toArray(),
    ];
}

test('every applier treats another assessment\'s uuids as nodes that do not exist', function (array $command, bool $deleteFirst) {
    $mine = Assessment::factory()->create();
    $theirs = Assessment::factory()->create();
    [$risk, $attack, $defence] = scopeLinked($theirs);

    $uuids = ['risk' => $risk->uuid, 'attack' => $attack->uuid, 'defence' => $defence->uuid];
    $command = json_decode(strtr(json_encode($command), [
        '{risk}' => $uuids['risk'], '{attack}' => $uuids['attack'], '{defence}' => $uuids['defence'],
    ]), true);

    if ($deleteFirst) {
        $risk->delete();
    }
    $before = scopeState();

    expect(fn () => (new CommandDispatcher)->apply($command, $mine->id))
        ->toThrow(UndoConflictException::class);

    expect(scopeState())->toBe($before);
})->with([
    'delete_node' => [['action' => 'delete_node', 'node_uuids' => ['{risk}']], false],
    'restore_node' => [['action' => 'restore_node', 'node_uuids' => ['{risk}']], true],
    'unlink' => [['action' => 'unlink', 'edge' => 'risk_attack', 'parent_uuid' => '{risk}', 'child_uuid' => '{attack}'], false],
    'relink' => [['action' => 'relink', 'edge' => 'attack_defence', 'parent_uuid' => '{attack}', 'child_uuid' => '{defence}', 'position' => 0, 'extra' => []], false],
    'reorder nodes' => [['action' => 'reorder_positions', 'scope' => 'nodes', 'entries' => [['key' => ['node_uuid' => '{risk}'], 'position' => 9]]], false],
    'reorder edges' => [['action' => 'reorder_positions', 'scope' => 'risk_attack', 'entries' => [['key' => ['parent_uuid' => '{risk}', 'child_uuid' => '{attack}'], 'position' => 9]]], false],
    'reparent_edge' => [['action' => 'reparent_edge', 'edge' => 'risk_attack', 'parent_uuid' => '{risk}', 'current_parent_uuid' => '{risk}', 'child_uuid' => '{attack}', 'position' => 0, 'extra' => []], false],
]);

test('a restore_snapshot command for another assessment is refused', function () {
    $mine = Assessment::factory()->create();
    $theirs = Assessment::factory()->create();
    $snapshot = app(SnapshotService::class)->create($theirs, 'theirs', null, null);

    expect(fn () => (new CommandDispatcher)->apply([
        'action' => 'restore_snapshot',
        'assessment_id' => $theirs->id,
        'snapshot_id' => $snapshot->id,
    ], $mine->id))->toThrow(UndoConflictException::class);
});

test('a restore_snapshot command naming another assessment is refused even for this assessment\'s own snapshot', function () {
    // The snapshot is ours and would restore cleanly; only the command's
    // assessment_id disagrees with the operation's. Without the explicit
    // check nothing else refuses it.
    $mine = Assessment::factory()->create();
    $theirs = Assessment::factory()->create();
    $snapshot = app(SnapshotService::class)->create($mine, 'mine', null, null);

    expect(fn () => (new CommandDispatcher)->apply([
        'action' => 'restore_snapshot',
        'assessment_id' => $theirs->id,
        'snapshot_id' => $snapshot->id,
    ], $mine->id))->toThrow(UndoConflictException::class, 'belongs to another assessment');
});

test('the same commands inside their own assessment still apply', function () {
    $mine = Assessment::factory()->create();
    [$risk] = scopeLinked($mine);

    (new CommandDispatcher)->apply(['action' => 'delete_node', 'node_uuids' => [$risk->uuid]], $mine->id);

    expect(Node::withTrashed()->whereKey($risk->id)->first()->trashed())->toBeTrue();
});
