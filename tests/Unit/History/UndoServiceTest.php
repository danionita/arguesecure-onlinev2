<?php

use App\Domain\History\UndoService;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in(__FILE__);

/*
 * `record()`'s kind whitelist (schema.md §3.10's enum) — never a free-form
 * string, matching CLAUDE.md's "no dynamic class instantiation or dynamic
 * column names from request input" discipline applied to this table too.
 */
test('record rejects a kind outside the schema enum', function () {
    $assessment = Assessment::factory()->create();
    $user = User::factory()->create();

    expect(fn () => app(UndoService::class)->record($assessment, $user, 'node.rename', 'x', []))
        ->toThrow(InvalidArgumentException::class);
});

test('peek returns null when the stack is empty', function () {
    $assessment = Assessment::factory()->create();

    expect(app(UndoService::class)->peek($assessment))->toBeNull();
});

test('operations on one assessment are never undoable from another', function () {
    $assessmentA = Assessment::factory()->create();
    $assessmentB = Assessment::factory()->create();
    $user = User::factory()->create();

    app(UndoService::class)->record($assessmentA, $user, 'node.delete', 'Deleted something', [
        'action' => 'restore_node', 'node_uuids' => [],
    ]);

    expect(app(UndoService::class)->peek($assessmentB))->toBeNull();
});
