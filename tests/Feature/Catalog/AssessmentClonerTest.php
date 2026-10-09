<?php

use App\Domain\Structure\AssessmentCloner;
use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RiskAttack;
use App\Models\Tag;
use App\Models\User;

/*
 * app/Domain/Structure/AssessmentCloner.php was built in W6 but shipped
 * with no dedicated test (PLAN.md's W6 row: "AssessmentCloner has no
 * dedicated test — W20 (templates/catalogue) must add one, it is the
 * service that package leans on hardest"). This package uses it unmodified
 * for TemplateController::store() (PLAN.md Phase 8 item 4), so its own
 * correctness is load-bearing here even though this package does not own
 * the class itself (CLAUDE.md: "you own NEITHER [catalog tables nor
 * AssessmentCloner] — read them and conform. If either genuinely cannot
 * support what the spec requires, STOP and report; do not alter them").
 *
 * Writing this test found three genuine, independently-reproducible
 * defects in AssessmentCloner against a real MariaDB with the project's
 * actual strict SQL mode (STRICT_TRANS_TABLES). W20 pinned each as a
 * "currently throws" test rather than patching a class it did not own, so
 * that fixing the class would turn them red and force this flip. All three
 * are now fixed, and these are ordinary success assertions.
 *
 * The middle one is the reason this test mattered: cloneAssessment() could
 * not clone anything at all, so "create a new assessment from a template" —
 * the whole point of W20 — was dead on arrival, and no other test in the
 * suite exercised the class.
 *
 * 1. cloneTags() mass-assigns `Tag::create(['assessment_id' => ..., ...])`,
 *    but Tag::$fillable (app/Models/Tag.php) deliberately excludes
 *    assessment_id (an identity column, per that model's own docblock) —
 *    so the value is silently dropped and the insert fails NOT NULL.
 * 2. cloneNodes() calls `$node->replicate([...])` without excluding the
 *    generated `root_marker` virtual column (schema.md §3.4's
 *    "exactly one root" invariant), so every cloned row carries an
 *    explicit value for a GENERATED ALWAYS column — MariaDB error 1906,
 *    a warning under a lenient sql_mode but a hard error under this
 *    project's own STRICT_TRANS_TABLES (confirmed via
 *    `SELECT @@sql_mode` against the project's own docker-compose db
 *    service). This is not test-only: it fires on the very first node
 *    (nodes are cloned in `position` order and every root node is
 *    created first, at position 0) and would fire against the real
 *    deployment's own MariaDB the same way.
 * 3. cloneSubtree()'s Risk branch mass-assigns
 *    `RiskAttack::query()->create(['risk_node_id' => ..., 'attack_node_id' => ..., ...])`,
 *    but RiskAttack::$fillable (app/Models/RiskAttack.php) is `['position']`
 *    only — LinkService's own docblock explains why: "RiskAttack's
 *    $fillable deliberately excludes its own foreign keys ... a
 *    mass-assigned create() here would silently drop
 *    risk_node_id/attack_node_id and fail the NOT NULL constraint" — the
 *    exact bug LinkService was written to avoid, present in
 *    AssessmentCloner's own copy of the same operation.
 *    cloneSubtree()'s Attack branch (cloneDefencesOfAttack) does not
 *    share this bug: it links via direct property assignment on
 *    AttackDefence, matching LinkService's own pattern, and the last
 *    test below proves that path genuinely works today.
 */
test('cloneAssessment carries the tags across to the copy', function () {
    $source = Assessment::factory()->create();

    $tag = new Tag(['name' => 'PCI', 'slug' => 'pci', 'color' => '#3B82F6']);
    $tag->assessment_id = $source->id;
    $tag->save();

    $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

    expect(Tag::query()->where('assessment_id', $clone->id)->pluck('name')->all())->toBe(['PCI']);
});

test('cloneAssessment clones the root node, so creating from a template works at all', function () {
    $source = Assessment::factory()->create();
    $newOwner = User::factory()->create();

    $clone = app(AssessmentCloner::class)->cloneAssessment($source, $newOwner);

    expect($clone->id)->not->toBe($source->id)
        ->and($clone->owner_user_id)->toBe($newOwner->id)
        // The copy is independent: never public, never itself a template,
        // and a fresh identity rather than a reference back to the source.
        ->and($clone->is_public)->toBeFalse()
        ->and($clone->is_template)->toBeFalse()
        ->and($clone->uuid)->not->toBe($source->uuid);

    $sourceRoot = Node::withoutGlobalScopes()->where('assessment_id', $source->id)->where('type', 'root')->firstOrFail();
    $cloneRoot = Node::withoutGlobalScopes()->where('assessment_id', $clone->id)->where('type', 'root')->firstOrFail();

    expect($cloneRoot->title)->toBe($sourceRoot->title)
        ->and($cloneRoot->uuid)->not->toBe($sourceRoot->uuid);
});

test('cloneSubtree of a risk carries its attacks and the edges between them', function () {
    $source = Assessment::factory()->create();
    $target = Assessment::factory()->create();

    $risk = Risk::factory()->for($source)->create(['title' => 'Ransomware exposure']);
    $attack = Attack::factory()->for($source)->create(['title' => 'Phishing']);
    app(LinkService::class)->linkRiskToAttack($risk, $attack);

    $clonedRisk = app(AssessmentCloner::class)->cloneSubtree($risk, $target);

    expect($clonedRisk->assessment_id)->toBe($target->id)
        ->and($clonedRisk->uuid)->not->toBe($risk->uuid);

    $clonedAttacks = RiskAttack::query()->where('risk_node_id', $clonedRisk->id)->get();
    expect($clonedAttacks)->toHaveCount(1);

    $clonedAttack = Node::withoutGlobalScopes()->findOrFail($clonedAttacks->first()->attack_node_id);
    expect($clonedAttack->title)->toBe('Phishing')
        ->and($clonedAttack->assessment_id)->toBe($target->id)
        // A copy, not a reference: editing the original must not reach it.
        ->and($clonedAttack->id)->not->toBe($attack->id);
});

test('cloneSubtree of a lone attack duplicates only its own defences, not sibling attacks — this path genuinely works today', function () {
    $source = Assessment::factory()->create();
    $target = Assessment::factory()->create();

    $risk = Risk::factory()->for($source)->create();
    $attack = Attack::factory()->for($source)->create(['title' => 'SQL injection']);
    $otherAttack = Attack::factory()->for($source)->create(['title' => 'Unrelated attack']);
    $defence = Defence::factory()->for($source)->create(['title' => 'WAF']);

    $links = app(LinkService::class);
    $links->linkRiskToAttack($risk, $attack);
    $links->linkRiskToAttack($risk, $otherAttack);
    $links->linkAttackToDefence($attack, $defence);

    $clonedAttack = app(AssessmentCloner::class)->cloneSubtree($attack, $target);

    expect($clonedAttack)->toBeInstanceOf(Attack::class)
        ->and($clonedAttack->title)->toBe('SQL injection')
        ->and($clonedAttack->assessment_id)->toBe($target->id)
        ->and($clonedAttack->uuid)->not->toBe($attack->uuid);

    expect(Attack::query()->where('assessment_id', $target->id)->count())->toBe(1);
    expect(Defence::query()->where('assessment_id', $target->id)->pluck('title')->all())->toBe(['WAF']);

    // The source's own nodes are untouched by the clone (root + risk +
    // both attacks + defence = 5; AssessmentFactory always creates a root
    // node alongside the ones built explicitly above).
    expect(Node::withoutGlobalScopes()->where('assessment_id', $source->id)->count())->toBe(5);
});
