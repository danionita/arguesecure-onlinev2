<?php

use App\Domain\Snapshots\SnapshotDiffer;
use App\Domain\Snapshots\SnapshotGraphCodec;
use App\Domain\Snapshots\SnapshotService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;

/*
 * SnapshotDiffer (PLAN.md Phase 8 item 3): "Diff two snapshots — match
 * nodes on uuid (not id, not title), show added, removed and changed nodes
 * and edges, plus the movement in each headline figure (inherent / current
 * / target)." Everything here reads only the two snapshots' already-frozen
 * columns — never the live assessment, never RiskCalculator again.
 */
test('diffing two snapshots reports added nodes, changed values and the movement in each headline total', function () {
    $assessment = Assessment::factory()->create(['currency' => 'EUR']);
    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'title' => 'Customer data breach',
        'impact_mode' => 'quantitative',
        'impact_amount' => 100000,
    ]);
    $attack = Attack::factory()->create([
        'assessment_id' => $assessment->id,
        'title' => 'Phishing',
        'likelihood_mode' => 'quantitative',
        'likelihood_aro' => 1.0,
    ]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $service = app(SnapshotService::class);
    $before = $service->create($assessment->fresh(), 'Before', null, $assessment->owner_user_id);

    // Change the risk's impact and add a new defence — the second snapshot
    // reflects both.
    $risk->update(['impact_amount' => 50000]);
    $defence = Defence::factory()->create([
        'assessment_id' => $assessment->id,
        'title' => 'MFA',
        'status' => 'implemented',
    ]);
    $attack->defences()->attach($defence->id, [
        'position' => 0,
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.5,
        'reduction_target' => 'likelihood',
    ]);

    $after = $service->create($assessment->fresh(), 'After', null, $assessment->owner_user_id);

    $diff = app(SnapshotDiffer::class)->diff($before, $after);

    // Node-level: the risk's impact_amount changed; the defence is new.
    $riskChange = collect($diff['nodes']['changed'])->firstWhere('uuid', $risk->uuid);
    expect($riskChange)->not->toBeNull();
    $impactFieldChange = collect($riskChange['fields'])->firstWhere('field', 'impact_amount');
    expect($impactFieldChange)->not->toBeNull();
    expect((float) $impactFieldChange['from'])->toEqual(100000.0);
    expect((float) $impactFieldChange['to'])->toEqual(50000.0);

    $addedUuids = collect($diff['nodes']['added'])->pluck('uuid');
    expect($addedUuids)->toContain($defence->uuid);
    expect($diff['nodes']['removed'])->toBe([]);

    // Edge-level: the new attack_defence link is added.
    $addedLinks = collect($diff['attackDefenceLinks']['added']);
    expect($addedLinks)->toHaveCount(1);
    expect($addedLinks->first()['defence_uuid'])->toBe($defence->uuid);

    // Headline totals: before = 1.0 * 100,000 = 100,000; after = 1.0 * 0.5
    // (MFA on likelihood) * 50,000 = 25,000.
    expect((float) $diff['totals']['current']['from'])->toEqual(100000.0);
    expect((float) $diff['totals']['current']['to'])->toEqual(25000.0);
    expect((float) $diff['totals']['current']['delta'])->toEqual(-75000.0);
});

test('diffing two identical snapshots reports no node or edge changes and zero movement', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'impact_mode' => 'quantitative', 'impact_amount' => 10000]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 1.0]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $service = app(SnapshotService::class);
    $s1 = $service->create($assessment->fresh(), 'S1', null, $assessment->owner_user_id);
    $s2 = $service->create($assessment->fresh(), 'S2', null, $assessment->owner_user_id);

    $diff = app(SnapshotDiffer::class)->diff($s1, $s2);

    expect($diff['nodes']['added'])->toBe([]);
    expect($diff['nodes']['removed'])->toBe([]);
    expect($diff['nodes']['changed'])->toBe([]);
    expect($diff['riskAttackLinks']['added'])->toBe([]);
    expect($diff['riskAttackLinks']['removed'])->toBe([]);
    expect((float) $diff['totals']['current']['delta'])->toEqual(0.0);
});

test('diffing detects a removed node', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $service = app(SnapshotService::class);
    $before = $service->create($assessment->fresh(), 'Before', null, $assessment->owner_user_id);

    $attackUuid = $attack->uuid;
    // Detach the edge before soft-deleting the node: AssessmentGraphLoader
    // (app/Domain/Graph/**, outside this package's ownership) does not
    // currently tolerate a live risk_attack row whose attack side has been
    // soft-deleted while the risk side survives — see this package's final
    // report. Detaching first keeps this test scoped to what it actually
    // exercises: SnapshotDiffer's node-removal detection, not that loader.
    $risk->attacks()->detach($attack->id);
    $attack->delete();

    $after = $service->create($assessment->fresh(), 'After', null, $assessment->owner_user_id);

    $diff = app(SnapshotDiffer::class)->diff($before, $after);

    $removedUuids = collect($diff['nodes']['removed'])->pluck('uuid');
    expect($removedUuids)->toContain($attackUuid);
});

test('a snapshot taken before W30 still decodes and diffs, with its notes reported as a field that went away', function () {
    // The point of a snapshot is that it is readable years later
    // (SnapshotGraphCodec's docblock: "never partially decodes or upgrades
    // old data in place"). Dropping `nodes.notes` in W30 left every
    // pre-existing snapshot carrying a key nothing writes any more, so the
    // thing worth pinning is that a legacy key is *data*, not a schema
    // expectation: it decodes, it diffs, and it surfaces honestly as a
    // field whose value went to null rather than throwing or being
    // silently dropped.
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Customer data breach']);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $service = app(SnapshotService::class);
    $codec = app(SnapshotGraphCodec::class);

    $legacy = $service->create($assessment->fresh(), 'Taken in 2.0', null, $assessment->owner_user_id);

    // Re-write the frozen blob exactly as a 2.0 instance would have written
    // it: same graph, plus the `notes` key that version carried.
    $graph = $codec->decode($legacy->graph);
    $graph['nodes'][$risk->uuid]['notes'] = 'Rationale written before the field was removed.';
    $legacy->update(['graph' => $codec->encode($graph)]);

    $current = $service->create($assessment->fresh(), 'Taken in 2.1', null, $assessment->owner_user_id);

    $diff = app(SnapshotDiffer::class)->diff($legacy->fresh(), $current);

    $riskChange = collect($diff['nodes']['changed'])->firstWhere('uuid', $risk->uuid);
    expect($riskChange)->not->toBeNull();

    $notesChange = collect($riskChange['fields'])->firstWhere('field', 'notes');
    expect($notesChange)->not->toBeNull();
    expect($notesChange['from'])->toBe('Rationale written before the field was removed.');
    expect($notesChange['to'])->toBeNull();
});
