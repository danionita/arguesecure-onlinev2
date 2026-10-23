<?php

use App\Domain\History\Commands\CommandDispatcher;
use App\Domain\History\Exceptions\UndoConflictException;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\RiskAttack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Each Applier both mutates the graph and hands back the command that
 * exactly reverses what it just did (schema.md §3.10). Exercised directly
 * against the dispatcher here — UndoControllerTest.php covers the same
 * behaviour end-to-end through the HTTP undo route.
 */
uses(TestCase::class, RefreshDatabase::class)->in(__FILE__);

/**
 * RiskAttack/AttackDefence deliberately exclude their own foreign keys from
 * $fillable (LinkService's docblock: "a mass-assigned create() here would
 * silently drop risk_node_id/attack_node_id and fail the NOT NULL
 * constraint") — identity columns are set by services, never by mass
 * assignment. Test fixtures need the same direct-assignment pattern.
 */
function linkRiskAttackDirect(Risk $risk, Attack $attack, int $position): RiskAttack
{
    $link = new RiskAttack;
    $link->risk_node_id = $risk->id;
    $link->attack_node_id = $attack->id;
    $link->position = $position;
    $link->save();

    return $link;
}

/** @param array<string, mixed> $extra */
function linkAttackDefenceDirect(Attack $attack, Defence $defence, int $position, array $extra = []): AttackDefence
{
    $link = new AttackDefence;
    $link->attack_node_id = $attack->id;
    $link->defence_node_id = $defence->id;
    $link->position = $position;
    $link->strength_mode = $extra['strength_mode'] ?? null;
    $link->strength_fraction = $extra['strength_fraction'] ?? null;
    $link->reduction_target = $extra['reduction_target'] ?? null;
    $link->save();

    return $link;
}

test('delete_node then restore_node round-trips cleanly', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $dispatcher = new CommandDispatcher;

    $reverse = $dispatcher->apply(['action' => 'delete_node', 'node_uuids' => [$risk->uuid]], $assessment->id);
    expect($risk->fresh()->trashed())->toBeTrue();
    expect($reverse)->toBe(['action' => 'restore_node', 'node_uuids' => [$risk->uuid]]);

    $dispatcher->apply($reverse, $assessment->id);
    expect($risk->fresh()->trashed())->toBeFalse();
});

test('restore_node refuses when the node was hard-deleted', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $risk->delete();
    $risk->forceDelete();

    $dispatcher = new CommandDispatcher;

    expect(fn () => $dispatcher->apply(['action' => 'restore_node', 'node_uuids' => [$risk->uuid]], $assessment->id))
        ->toThrow(UndoConflictException::class);
});

test('unlink then relink restores the exact strength and reduction target', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    linkAttackDefenceDirect($attack, $defence, 2, [
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.8,
        'reduction_target' => 'likelihood',
    ]);

    $dispatcher = new CommandDispatcher;
    $reverse = $dispatcher->apply([
        'action' => 'unlink', 'edge' => 'attack_defence',
        'parent_uuid' => $attack->uuid, 'child_uuid' => $defence->uuid,
    ], $assessment->id);

    expect(AttackDefence::query()->where('attack_node_id', $attack->id)->where('defence_node_id', $defence->id)->exists())->toBeFalse();

    $dispatcher->apply($reverse, $assessment->id);

    $restored = AttackDefence::query()->where('attack_node_id', $attack->id)->where('defence_node_id', $defence->id)->first();
    expect($restored->position)->toBe(2);
    expect($restored->strength_mode)->toBe('quantitative');
    expect((float) $restored->strength_fraction)->toBe(0.8);
    expect($restored->reduction_target)->toBe('likelihood');
});

test('relink refuses when an endpoint has since been deleted', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $dispatcher = new CommandDispatcher;
    $relink = [
        'action' => 'relink', 'edge' => 'attack_defence',
        'parent_uuid' => $attack->uuid, 'child_uuid' => $defence->uuid,
        'position' => 0, 'extra' => [],
    ];

    $defence->delete();

    expect(fn () => $dispatcher->apply($relink, $assessment->id))->toThrow(UndoConflictException::class);
});

test('reorder_positions restores exact prior positions for every sibling', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $a = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $b = Attack::factory()->create(['assessment_id' => $assessment->id]);
    linkRiskAttackDirect($risk, $a, 0);
    linkRiskAttackDirect($risk, $b, 1);

    $dispatcher = new CommandDispatcher;
    $command = [
        'action' => 'reorder_positions', 'scope' => 'risk_attack',
        'entries' => [
            ['key' => ['parent_uuid' => $risk->uuid, 'child_uuid' => $a->uuid], 'position' => 1],
            ['key' => ['parent_uuid' => $risk->uuid, 'child_uuid' => $b->uuid], 'position' => 0],
        ],
    ];

    $reverse = $dispatcher->apply($command, $assessment->id);
    expect(RiskAttack::query()->where('attack_node_id', $a->id)->value('position'))->toBe(1);
    expect(RiskAttack::query()->where('attack_node_id', $b->id)->value('position'))->toBe(0);

    $dispatcher->apply($reverse, $assessment->id);
    expect(RiskAttack::query()->where('attack_node_id', $a->id)->value('position'))->toBe(0);
    expect(RiskAttack::query()->where('attack_node_id', $b->id)->value('position'))->toBe(1);
});

test('an unrecognised action is refused, never silently ignored', function () {
    $dispatcher = new CommandDispatcher;

    expect(fn () => $dispatcher->apply(['action' => 'drop_table'], 1))
        ->toThrow(UndoConflictException::class);
});
