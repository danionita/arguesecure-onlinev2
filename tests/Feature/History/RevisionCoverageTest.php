<?php

use App\Domain\History\RevisionRecorder;
use App\Domain\History\TimelineReader;
use App\Domain\Tags\TagService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\NodeRevision;
use App\Models\Risk;
use App\Models\User;

/*
 * W48's audit, as executable assertions rather than a paragraph in a report.
 *
 * PLAN-2.2.md D15 specifies a "revert to here" that reconstructs the graph as
 * of a chosen moment. The brief required auditing `node_revisions` **before**
 * writing that reconstruction, and stopping if any mutation reaches the graph
 * without writing a revision — because a reconstruction with a gap produces an
 * assessment that looks restored and is quietly wrong, which is worse than no
 * revert at all.
 *
 * Several mutations did. This file pinned each gap as the behaviour that
 * existed at the time, in the shape CLAUDE.md prescribes: fixing one turns its
 * test red, which forces whoever fixes it to read the reasoning and flip the
 * assertion, so a fix can neither be forgotten nor land silently.
 *
 * **W56 closed three of them** — link strength, labels and assignees — **W60
 * closed the fourth**, the assessment's own settings, and **W61 built the
 * restore path the fifth was about**. Every block is rewritten in place as a
 * `FIXED:` regression guard rather than deleted, so the reasoning survives and
 * no fix can be quietly reverted.
 *
 * What that leaves is one honest limitation rather than a gap: a snapshot's
 * shape holds no labels, assignees or comments, so a restore cannot put them
 * back on a node it resurrects. It says so, out loud, to the person who
 * pressed the button. See docs/specs/schema.md §3.9 and §3.13.
 */
function historyAssessment(): array
{
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $attack = Attack::factory()->for($assessment)->create(['title' => 'Phishing']);
    $defence = Defence::factory()->for($assessment)->create(['title' => 'MFA']);

    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, [
        'position' => 0,
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.5,
    ]);

    return [$assessment, $risk, $attack, $defence];
}

function revisionCount(Assessment $assessment): int
{
    return NodeRevision::query()->where('assessment_id', $assessment->id)->count();
}

/**
 * One settings save, with everything the screen posts.
 *
 * W44's form submitted title and description alongside the three settings
 * fields; W86 (item 9) removed both from this screen and from
 * `UpdateAssessmentSettingsRequest`, which now `prohibited`s them — so the
 * baseline here is the three fields that remain, and $changes is what the
 * person typed.
 *
 * @param  array<string, mixed>  $changes
 */
function saveSettings(Assessment $assessment, array $changes): void
{
    test()->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment), array_merge([
            'currency' => $assessment->currency,
            'amortization_years' => (int) $assessment->amortization_years,
            'risk_tolerance' => $assessment->risk_tolerance,
        ], $changes))
        ->assertRedirect();
}

// --- what IS recorded ----------------------------------------------------

test('a node field edit writes exactly one revision carrying every changed field', function () {
    [$assessment, $risk] = historyAssessment();

    $this->actingAs($assessment->owner)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk->uuid]), [
            'title' => 'Cardholder data exfiltration',
            'description' => 'Rewritten',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertOk();

    $revisions = NodeRevision::query()->where('assessment_id', $assessment->id)->get();

    expect($revisions)->toHaveCount(1)
        ->and(array_keys($revisions[0]->changes))->toEqualCanonicalizing(['title', 'description'])
        ->and($revisions[0]->changes['title']['from'])->toBe('Cardholder data breach');
});

/*
 * FIXED (W56): a defence's strength is recorded.
 *
 * WAS: `LinkController::updateAttackDefence()` wrote `strength_fraction`,
 * `strength_mode`, `reduction_target` and `rationale` straight to the
 * `attack_defence` pivot and logged nothing. Strength is a direct multiplicand
 * in `ALE = ARO × Impact × Π(1 − strength)` — one of the three numbers the
 * whole tool computes with — so history could not reconstruct a past total,
 * and a "revert" built on it would have restored the graph's shape while
 * leaving today's strengths in place.
 *
 * THE FIX: `RevisionRecorder::recordLinkUpdate()`, written from the
 * controller, using the `{"other_uuid":…, "other_title":…, "pivot": {…}}`
 * shape schema.md §3.9 already reserved for `action = 'link_update'`.
 *
 * THE TRADE-OFF THAT WAS TAKEN: the editor saves on `@input`, so typing
 * "0.85" is four requests and would have been four rows. Edits by the same
 * user to the same link inside 120 seconds fold into one revision instead —
 * which means a revision row is written more than once, and schema.md §3.9's
 * "revisions are immutable" is now qualified to that window. The alternative
 * was a log nobody reads because every entry is a keystroke.
 *
 * Guards the fix: if the recorder call is removed, this goes red.
 */
test('FIXED: changing a defence’s strength writes a link revision', function () {
    [$assessment, , $attack, $defence] = historyAssessment();

    $this->actingAs($assessment->owner)
        ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
            'strength_fraction' => 0.9,
        ])
        ->assertOk();

    $link = AttackDefence::query()
        ->where('attack_node_id', $attack->id)
        ->where('defence_node_id', $defence->id)
        ->firstOrFail();

    $revision = NodeRevision::query()->where('assessment_id', $assessment->id)->sole();

    expect((float) $link->strength_fraction)->toBe(0.9)
        ->and($revision->action)->toBe('link_update')
        // Hung off the attack, which is where the editor shows the link.
        ->and($revision->node_uuid)->toBe($attack->uuid)
        ->and($revision->changes['other_uuid'])->toBe($defence->uuid)
        ->and($revision->changes['other_title'])->toBe('MFA')
        ->and((float) $revision->changes['pivot']['strength_fraction']['from'])->toBe(0.5)
        ->and((float) $revision->changes['pivot']['strength_fraction']['to'])->toBe(0.9);
});

test('four keystrokes on one strength field read as one change', function () {
    [$assessment, , $attack, $defence] = historyAssessment();

    // What `@input` saving actually produces: 0.8, 0.85, 0.855, 0.85.
    foreach ([0.8, 0.85, 0.855, 0.85] as $value) {
        $this->actingAs($assessment->owner)
            ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
                'attack_uuid' => $attack->uuid,
                'defence_uuid' => $defence->uuid,
                'strength_fraction' => $value,
            ])
            ->assertOk();
    }

    $revision = NodeRevision::query()->where('assessment_id', $assessment->id)->sole();

    // The earliest `from` and the latest `to`: the whole of what this person
    // did to this link in one sitting, which is the thing a reader came for.
    expect((float) $revision->changes['pivot']['strength_fraction']['from'])->toBe(0.5)
        ->and((float) $revision->changes['pivot']['strength_fraction']['to'])->toBe(0.85);
});

test('a strength typed back to where it started leaves no revision behind', function () {
    [$assessment, , $attack, $defence] = historyAssessment();

    foreach ([0.9, 0.5] as $value) {
        $this->actingAs($assessment->owner)
            ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
                'attack_uuid' => $attack->uuid,
                'defence_uuid' => $defence->uuid,
                'strength_fraction' => $value,
            ])
            ->assertOk();
    }

    // A row reading "changed 0.5 to 0.5" is worse than no row: it puts a
    // change in the timeline that never happened.
    expect(revisionCount($assessment))->toBe(0);
});

test('two people editing the same link stay two entries', function () {
    [$assessment, , $attack, $defence] = historyAssessment();
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    foreach ([[$assessment->owner, 0.9], [$editor, 0.2]] as [$actor, $value]) {
        $this->actingAs($actor)
            ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
                'attack_uuid' => $attack->uuid,
                'defence_uuid' => $defence->uuid,
                'strength_fraction' => $value,
            ])
            ->assertOk();
    }

    // Coalescing is scoped to one user on purpose. Two people moving the same
    // number a minute apart is exactly the collision a history exists to
    // show, and folding them would attribute both to whoever typed last.
    expect(revisionCount($assessment))->toBe(2);
});

test('a link revision cannot be reverted through the node-field path', function () {
    [$assessment, , $attack, $defence] = historyAssessment();

    $this->actingAs($assessment->owner)
        ->patchJson(route('assessments.attack-defence-links.update', $assessment), [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
            'strength_fraction' => 0.9,
        ])
        ->assertOk();

    $revision = NodeRevision::query()->where('assessment_id', $assessment->id)->sole();

    // RevisionReverter writes a node column. `strength_fraction` is not one —
    // it lives on the pivot — and the reverter refuses any action outside
    // create/update rather than setting an attribute that does not exist.
    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $attack->uuid, $revision->id]), [
            'field' => 'strength_fraction',
        ])
        ->assertStatus(422);
});

/*
 * FIXED (W56): labels are recorded.
 *
 * WAS: `TagService` attached and detached `node_tag` rows with no revision.
 * Labels drive the editor's filter (W43), so a reconstruction that ignored
 * them would restore a graph whose saved views no longer select the same
 * nodes.
 *
 * THE FIX: a `field.update`-shaped revision with the tag name in `changes`,
 * written inside `TagService` rather than in its controller — so a console
 * command or a future bulk-label action cannot reopen the gap. Cheap, as
 * predicted: labels are not dragged, so no coalescing is involved.
 *
 * Guards the fix: if the recorder call is removed, this goes red.
 */
test('FIXED: adding and removing a label each write a revision', function () {
    [$assessment, $risk] = historyAssessment();

    $tag = app(TagService::class)->attach($risk, 'PCI DSS');

    $added = NodeRevision::query()->where('assessment_id', $assessment->id)->sole();
    expect($added->changes['label'])->toBe(['from' => null, 'to' => 'PCI DSS']);

    // Attaching the same label twice is something a user does by accident —
    // TagService tolerates it, and it should not read as two events.
    app(TagService::class)->attach($risk, 'PCI DSS');
    expect(revisionCount($assessment))->toBe(1);

    app(TagService::class)->detach($risk, $tag);
    expect(revisionCount($assessment))->toBe(2);

    expect(NodeRevision::query()->latest('id')->first()->changes['label'])
        ->toBe(['from' => 'PCI DSS', 'to' => null]);
});

/*
 * FIXED (W56): assignees are recorded.
 *
 * W46's `node_assignees` writes carried no revision. Lower stakes than
 * strength — an assignee changes no figure — but a timeline that claims to be
 * the record of what was done to an assessment omitted "Dana was made
 * responsible for this", which is exactly the kind of thing a reader goes to
 * history for.
 *
 * THE FIX: the same `field.update` shape as labels, written on the genuine-
 * change branch `AssignmentController` already had for W47's notification —
 * a no-op assign should no more write a row than send a message.
 *
 * Guards the fix: if the recorder call is removed, this goes red.
 */
test('FIXED: assigning and unassigning each write a revision', function () {
    [$assessment, $risk] = historyAssessment();
    $person = User::factory()->create();
    $assessment->members()->attach($person->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $person->id,
        ])
        ->assertCreated();

    expect(NodeRevision::query()->sole()->changes['assignee'])
        ->toBe(['from' => null, 'to' => $person->name]);

    $this->actingAs($assessment->owner)
        ->deleteJson(route('assessments.nodes.assignees.destroy', [$assessment, $risk->uuid, $person->id]))
        ->assertOk();

    expect(revisionCount($assessment))->toBe(2)
        ->and(NodeRevision::query()->latest('id')->first()->changes['assignee'])
        ->toBe(['from' => $person->name, 'to' => null]);
});

/*
 * FIXED (W60): the assessment's own settings are recorded.
 *
 * WAS: W44's amortisation period changes every annual cost, cost-benefit ratio
 * and return in the report — it is the one setting that moves a figure, and it
 * bumps `calc_version` for exactly that reason. Neither log recorded it, so a
 * reconstruction restored the nodes and left the divisor at today's value.
 *
 * THE FIX: a revision hung off the **root node** — the row that already
 * carries the assessment's title and description — marked `scope:
 * 'assessment'` and keeping its fields one level down under `fields`. No
 * migration: `node_revisions.node_uuid` is NOT NULL and every reader keys on
 * it, so a nullable-uuid convention would have touched the schema, the model
 * and four readers to hold three columns from one screen.
 *
 * THE TRADE-OFF THAT WAS TAKEN (as it stood through 2.4): a settings save
 * that also renamed the assessment wrote **two** rows, because the title was
 * a node column and the observer recorded it separately. Folding them would
 * have needed an `operation_id`, which means an `assessment_operations` row,
 * which means putting a settings edit on the undo stack — and it is not a
 * structural change (schema.md §3.10). Two honest entries beat one that lies
 * about which log it came from.
 *
 * **W86 (item 9) removed the other half of that trade-off, not this fix.**
 * Title and description are gone from this screen and from
 * `UpdateAssessmentSettingsRequest` entirely (`prohibited`, not just
 * unbound) — they live on the root node and are edited there, and the write
 * that produced the two-rows case above was going through the ordinary
 * `NodeObserver`/`RevisionRecorder` path on `$root->save()` the whole time,
 * so the history page was recording that edit from a second place. A
 * settings save can no longer rename the assessment at all, so the scenario
 * this docblock describes cannot happen through this screen any more — see
 * "FIXED: a settings save cannot carry a title or description any more"
 * below, which replaced the test that used to exercise it.
 *
 * Guards the fix: if the recorder call is removed, this goes red.
 */
test('FIXED: changing the amortisation period writes a revision on the root node', function () {
    [$assessment] = historyAssessment();

    saveSettings($assessment, ['amortization_years' => 7]);

    $revision = NodeRevision::query()->where('assessment_id', $assessment->id)->sole();

    expect((int) $assessment->fresh()->amortization_years)->toBe(7)
        ->and($revision->node_uuid)->toBe($assessment->rootNode->uuid)
        ->and($revision->changes['scope'])->toBe('assessment')
        // Beside `fields`, never inside it: a setting and a node column may
        // one day share a name, and the marker must not be readable as one.
        ->and(array_keys($revision->changes))->toEqualCanonicalizing(['scope', 'fields'])
        ->and((int) $revision->changes['fields']['amortization_years']['from'])->toBe(3)
        ->and((int) $revision->changes['fields']['amortization_years']['to'])->toBe(7);
});

test('three settings changed in one save read as one entry', function () {
    [$assessment] = historyAssessment();

    saveSettings($assessment, [
        'currency' => 'USD',
        'amortization_years' => 6,
        'risk_tolerance' => 25000,
    ]);

    $revision = NodeRevision::query()->where('assessment_id', $assessment->id)->sole();

    expect(array_keys($revision->changes['fields']))
        ->toEqualCanonicalizing(['currency', 'amortization_years', 'risk_tolerance']);
});

test('a settings save that changes nothing writes no revision', function () {
    [$assessment] = historyAssessment();

    saveSettings($assessment, []);

    // The screen posts every field on every save, so "nothing changed" is the
    // ordinary case — a row per visit would bury the saves that meant
    // something.
    expect(revisionCount($assessment))->toBe(0);
});

/**
 * FIXED (W86): the settings form can no longer rename the assessment.
 *
 * WAS: this scenario — a settings save that also renamed the assessment via
 * this screen's own title/description inputs wrote **two** revisions,
 * because the title lived on the root node and `NodeObserver` recorded it
 * separately from the settings-scoped revision above. That was the
 * documented trade-off of W60's fix (see the docblock above), not a defect
 * in it — but item 9's discovery was that the screen should never have had
 * its own title/description inputs in the first place: they live on the
 * root node and are edited there, in the tree, and a second editor for the
 * same field is how the two diverge.
 *
 * THE FIX: `UpdateAssessmentSettingsRequest` now `prohibited`s both fields
 * rather than merely dropping the Vue inputs — a removed input is not a
 * removed endpoint, and this is the guarantee that a scripted request
 * carrying `title` or `description` cannot resurrect the second write path.
 *
 * Guards the fix: if `prohibited` is loosened back to `nullable`/`sometimes`,
 * this goes red.
 */
test('FIXED: a settings save cannot carry a title or description any more', function () {
    [$assessment] = historyAssessment();
    $originalTitle = $assessment->rootNode->title;

    // The exact scenario "renaming the assessment while changing a setting
    // writes one entry each" used to exercise: a title alongside a genuine
    // setting change, in one save.
    test()->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment), [
            'title' => 'Payment platform, second pass',
            'description' => 'Attempted rewrite via settings',
            'currency' => $assessment->currency,
            'amortization_years' => 2,
            'risk_tolerance' => $assessment->risk_tolerance,
        ])
        ->assertSessionHasErrors(['title', 'description']);

    // Nothing reached the root node, and no revision was written at all —
    // the whole request was refused, not partially applied. `prohibited`
    // fails validation before the controller runs, so this is not "the
    // settings write happened and the title write did not" — neither did.
    expect($assessment->rootNode->fresh()->title)->toBe($originalTitle);
    expect((int) $assessment->fresh()->amortization_years)->toBe(3);
    expect(revisionCount($assessment))->toBe(0);

    // The more specific half of the guard: a *legitimate* settings save —
    // no title in the payload at all, exactly what the Vue form now sends —
    // still writes exactly **one** revision, the assessment-scoped one from
    // recordSettingsUpdate(). Before W86, the same save with a title
    // alongside it produced this row *plus* a second, unscoped root-node
    // revision shaped like an ordinary title edit
    // (`{"title": {"from": …, "to": …}}`, no `scope` key) — the row
    // "renaming…" pinned. That shape can no longer appear from this screen
    // under any input, which is what is asserted here, not merely a low
    // revision count.
    saveSettings($assessment, ['amortization_years' => 8]);

    $revisions = NodeRevision::query()->where('assessment_id', $assessment->id)->get();

    expect($revisions)->toHaveCount(1);
    expect($revisions->first()->node_uuid)->toBe($assessment->rootNode->uuid);
    expect($revisions->first()->changes['scope'] ?? null)->toBe(RevisionRecorder::ASSESSMENT_SCOPE);
    // The specific shape the old second row had: a top-level `title` key
    // with no `scope` marker. None of the (exactly one) revisions above has
    // it — the absence this guard is really about.
    expect(
        $revisions->contains(fn (NodeRevision $r): bool => array_key_exists('title', $r->changes) && ! array_key_exists('scope', $r->changes))
    )->toBeFalse();
});

test('an assessment setting cannot be reverted through the node-field path', function () {
    [$assessment] = historyAssessment();

    saveSettings($assessment, ['amortization_years' => 9]);

    $revision = NodeRevision::query()->where('assessment_id', $assessment->id)->sole();

    // `amortization_years` is a column on `assessments`, not on `nodes`.
    // RevisionReverter refuses the scope explicitly rather than relying on the
    // $fillable check, which rejects this today only because no node column
    // happens to share the name.
    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.revisions.revert', [
            $assessment, $assessment->rootNode->uuid, $revision->id,
        ]), ['field' => 'amortization_years'])
        ->assertStatus(422);

    expect((int) $assessment->fresh()->amortization_years)->toBe(9);
});

test('the scope refusal holds even when a setting shares a node column’s name', function () {
    [$assessment] = historyAssessment();
    $root = $assessment->rootNode;

    // The shape the recorder does not write today, and the reason the refusal
    // is explicit. `title` is both a node column and a thing the settings
    // screen owns; if a later writer ever put one at the top level of an
    // assessment-scoped revision, the $fillable allowlist would wave it
    // through and RevisionReverter would write an assessment setting onto the
    // root node. Neuter the scope check in RevisionReverter and this goes red;
    // the test above it does not, because it passes for the wrong reason.
    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $root->id,
        'node_uuid' => $root->uuid,
        'user_id' => $assessment->owner_user_id,
        'action' => 'update',
        'changes' => ['scope' => 'assessment', 'title' => ['from' => 'Before', 'to' => 'After']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $root->uuid, $revision->id]), [
            'field' => 'title',
        ])
        ->assertStatus(422);

    expect($root->fresh()->title)->not->toBe('Before');
});

test('the timeline says the assessment changed, not the root node', function () {
    [$assessment] = historyAssessment();

    saveSettings($assessment, ['currency' => 'USD']);

    $entry = app(TimelineReader::class)->read($assessment)['entries'][0];

    // "Changed currency on Payment platform" reads as an edit to a node, and
    // someone hunting for why every figure is denominated differently needs to
    // see that it was the assessment.
    expect($entry['summary'])->toBe('Changed currency on the assessment')
        ->and($entry['changes'])->toHaveCount(1)
        ->and($entry['changes'][0]['field'])->toBe('currency');
});

/*
 * Not a defect — a deliberate split, asserted so nobody "fixes" it by
 * duplicating creates into `node_revisions`. Structural changes live in
 * `assessment_operations` (schema.md §3.10) and the timeline merges the two
 * logs rather than expecting either to be complete on its own.
 */
test('creating a node records a structural operation and no revision', function () {
    [$assessment] = historyAssessment();

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.store', $assessment), [
            'type' => 'risk',
            'title' => 'Service outage',
        ])
        ->assertCreated();

    expect(revisionCount($assessment))->toBe(0)
        ->and($assessment->operations()->where('kind', 'node.create')->count())->toBe(1);
});

/*
 * FIXED (W61): there is a snapshot restore route.
 *
 * WAS: the reason the brief's own fallback ("offer revert only back to the
 * nearest snapshot") could not be taken either. `SnapshotService` created;
 * `SnapshotController` listed, showed and diffed. Nothing applied one back, so
 * D15's "revert to here" had nothing to go through even once the coverage gaps
 * above were closed.
 *
 * THE FIX: `SnapshotRestorer` and `assessments.snapshots.restore`, restoring
 * **in place** so every shared link, notification and delivered report keeps
 * pointing at the assessment people are working on. A safety snapshot of the
 * current state is taken first, in the same transaction, and the operation's
 * inverse points at it — so the inverse of a restore is another restore and
 * undo works.
 *
 * THE TRADE-OFF THAT WAS TAKEN: `snapshots.graph` holds no labels, assignees
 * or comments (schema.md §3.13). Nodes matched by uuid keep the ones they
 * have; a node a restore brings back from the dead comes back bare. That is
 * counted and reported to the user rather than absorbed — see
 * tests/Feature/Snapshots/SnapshotRestoreTest.php, which is where the
 * behaviour itself is asserted.
 *
 * Guards the fix: if the route is removed, this goes red.
 */
test('FIXED: there is a snapshot restore route for a revert to go through', function () {
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->filter(fn (string $name): bool => str_contains($name, 'snapshot'))
        ->values()
        ->all();

    expect($names)->toContain('assessments.snapshots.restore');
});
