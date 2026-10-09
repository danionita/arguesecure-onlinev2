<?php

use App\Domain\Catalog\CatalogInserter;
use App\Domain\Structure\AssessmentCloner;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\CatalogEntry;
use App\Models\Node;
use App\Models\User;
use Database\Seeders\SampleContentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * W76 — the template content audit, steps 1-3.
 *
 * PLAN-2.4 item 2 reported seeded templates arriving structurally present
 * but empty of children. The fixtures were audited twice (once writing the
 * plan, once by the author) and found structurally complete, so this file
 * is the reproduce-first half of the package: it compares what actually
 * lands in the database against the fixture that produced it, node for
 * node and edge for edge, at the three points the emptiness could enter.
 *
 *   step 1  the seeder      — fixture json  vs  seeded rows
 *   step 2  the cloner      — seeded rows   vs  the assessment made from
 *                             the template through TemplateController's
 *                             AssessmentCloner path
 *   step 3  the inserter    — catalog fixture bundle vs the subtree
 *                             CatalogInserter builds in a real assessment
 *
 * These are equality assertions on counts derived from the fixture files
 * themselves, not on literals: a fixture edited later re-derives its own
 * expectation, so the test keeps testing the seeder rather than freezing
 * today's content. What it must never do is pass while a tree is empty —
 * that is precisely the failure the plan says the existing validators did
 * not catch, so every count is compared to a fixture-derived number and
 * separately asserted to be non-zero.
 */

/**
 * Every assessment-kind fixture, decoded, keyed by its `key`.
 *
 * @return array<string, array<string, mixed>>
 */
function w76AssessmentFixtures(): array
{
    $out = [];
    foreach (glob(database_path('seeders/templates').'/*.json') ?: [] as $path) {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        if (($decoded['kind'] ?? null) === 'assessment') {
            $out[$decoded['key']] = $decoded;
        }
    }

    return $out;
}

/**
 * Every catalog-kind fixture, decoded, keyed by its `key`.
 *
 * @return array<string, array<string, mixed>>
 */
function w76CatalogFixtures(): array
{
    $out = [];
    foreach (glob(database_path('seeders/templates').'/*.json') ?: [] as $path) {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        if (($decoded['kind'] ?? null) === 'catalog') {
            $out[$decoded['key']] = $decoded;
        }
    }

    return $out;
}

/**
 * Node/edge counts of one seeded assessment, as they exist in the database.
 *
 * `withoutGlobalScopes()` throughout: Node's default scope filters to the
 * assessment in the current request context, which does not exist in a
 * seeder-driven test.
 *
 * @return array{risks: int, attacks: int, defences: int, roots: int, risk_attack: int, attack_defence: int, tags: int, references: int}
 */
function w76CountRows(Assessment $assessment): array
{
    $nodeIds = Node::withoutGlobalScopes()->where('assessment_id', $assessment->id)->pluck('id');

    $countType = fn (string $type): int => Node::withoutGlobalScopes()
        ->where('assessment_id', $assessment->id)
        ->where('type', $type)
        ->count();

    return [
        'roots' => $countType('root'),
        'risks' => $countType('risk'),
        'attacks' => $countType('attack'),
        'defences' => $countType('defence'),
        'risk_attack' => DB::table('risk_attack')->whereIn('risk_node_id', $nodeIds)->count(),
        'attack_defence' => DB::table('attack_defence')->whereIn('attack_node_id', $nodeIds)->count(),
        'tags' => DB::table('tags')->where('assessment_id', $assessment->id)->count(),
        'references' => DB::table('node_references')->whereIn('node_id', $nodeIds)->count(),
    ];
}

/**
 * The same counts, derived from the fixture rather than the database.
 *
 * @param  array<string, mixed>  $fixture
 * @return array{risks: int, attacks: int, defences: int, roots: int, risk_attack: int, attack_defence: int, tags: int, references: int}
 */
function w76CountFixture(array $fixture): array
{
    $riskAttack = 0;
    foreach ($fixture['risks'] as $risk) {
        $riskAttack += count($risk['attack_refs']);
    }

    $attackDefence = 0;
    $references = 0;
    foreach ($fixture['attacks'] as $attack) {
        $attackDefence += count($attack['defence_refs']);
        $references += count($attack['references'] ?? []);
    }
    // W102: defences carry their control citations (PLAN-2.7 D64).
    foreach ($fixture['defences'] as $defence) {
        $references += count($defence['references'] ?? []);
    }

    return [
        'roots' => 1,
        'risks' => count($fixture['risks']),
        'attacks' => count($fixture['attacks']),
        'defences' => count($fixture['defences']),
        'risk_attack' => $riskAttack,
        'attack_defence' => $attackDefence,
        'tags' => count($fixture['tags'] ?? []),
        'references' => $references,
    ];
}

// --- step 1: the seeder ---------------------------------------------------

test('step 1: every fixture seeds exactly the nodes and edges it declares', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    foreach (w76AssessmentFixtures() as $key => $fixture) {
        $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->first();
        expect($root)->not->toBeNull("fixture '{$key}' seeded no root node at all");

        $assessment = Assessment::findOrFail($root->assessment_id);

        $expected = w76CountFixture($fixture);
        expect(w76CountRows($assessment))->toBe($expected, "fixture '{$key}' seeded a different shape than it declares");

        // The emptiness guard proper. A tree can only be "structurally
        // present but empty of children" if one of these is zero, and a
        // count comparison alone would not say so out loud if the fixture
        // itself were ever emptied.
        expect($expected['risks'])->toBeGreaterThan(0, "fixture '{$key}' has no risks")
            ->and($expected['attacks'])->toBeGreaterThan(0, "fixture '{$key}' has no attacks")
            ->and($expected['defences'])->toBeGreaterThan(0, "fixture '{$key}' has no defences")
            ->and($expected['risk_attack'])->toBeGreaterThan(0, "fixture '{$key}' has no risk->attack edges")
            ->and($expected['attack_defence'])->toBeGreaterThan(0, "fixture '{$key}' has no attack->defence edges");
    }
});

test('step 1: every seeded node carries the fixture title, description and value it was given', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    foreach (w76AssessmentFixtures() as $key => $fixture) {
        foreach (['risks', 'attacks', 'defences'] as $bucket) {
            foreach ($fixture[$bucket] as $spec) {
                $node = Node::withoutGlobalScopes()->where('uuid', $spec['uuid'])->first();
                expect($node)->not->toBeNull("{$key}: {$bucket} '{$spec['ref']}' is missing from the database");
                expect($node->title)->toBe($spec['title'], "{$key}: {$spec['ref']} title");
                expect($node->description)->toBe($spec['description'] ?? null, "{$key}: {$spec['ref']} description");
            }
        }

        // Values, the half a count comparison cannot see: a node present
        // but unvalued renders as an empty card and excludes its pair from
        // every total (CLAUDE.md — a missing impact or likelihood excludes
        // the pair, it is never coerced to zero).
        foreach ($fixture['risks'] as $spec) {
            $risk = Node::withoutGlobalScopes()->where('uuid', $spec['uuid'])->firstOrFail();
            expect($risk->impact_mode)->toBe($spec['impact']['mode'], "{$key}: {$spec['ref']} impact_mode");
            if ($spec['impact']['mode'] === 'quantitative') {
                expect((float) $risk->impact_amount)->toBe((float) $spec['impact']['amount']);
            } else {
                expect($risk->impact_level_id)->not->toBeNull("{$key}: {$spec['ref']} qualitative impact resolved to no level");
            }
        }

        foreach ($fixture['attacks'] as $spec) {
            $attack = Node::withoutGlobalScopes()->where('uuid', $spec['uuid'])->firstOrFail();
            expect($attack->likelihood_mode)->toBe($spec['likelihood']['mode'], "{$key}: {$spec['ref']} likelihood_mode");
            if ($spec['likelihood']['mode'] === 'quantitative') {
                expect((float) $attack->likelihood_aro)->toBe((float) $spec['likelihood']['amount']);
            } else {
                expect($attack->likelihood_level_id)->not->toBeNull("{$key}: {$spec['ref']} qualitative likelihood resolved to no level");
            }
        }
    }
});

test('step 1: every seeded attack_defence edge carries the strength the fixture declares', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    foreach (w76AssessmentFixtures() as $key => $fixture) {
        $uuidToId = Node::withoutGlobalScopes()
            ->whereIn('uuid', collect([...$fixture['attacks'], ...$fixture['defences']])->pluck('uuid'))
            ->pluck('id', 'uuid');

        foreach ($fixture['defences'] as $defenceSpec) {
            foreach ($defenceSpec['links'] as $attackRef => $linkSpec) {
                $attackSpec = collect($fixture['attacks'])->firstWhere('ref', $attackRef);

                $pivot = DB::table('attack_defence')
                    ->where('attack_node_id', $uuidToId[$attackSpec['uuid']])
                    ->where('defence_node_id', $uuidToId[$defenceSpec['uuid']])
                    ->first();

                expect($pivot)->not->toBeNull("{$key}: edge {$attackRef} -> {$defenceSpec['ref']} was not created");
                expect($pivot->strength_mode)->toBe($linkSpec['strength']['mode']);

                // Strength is the one value CLAUDE.md says defaults the
                // *unsafe* way round when absent (a missing strength is
                // read as zero reduction), so a silently-dropped pivot
                // column here would overstate residual risk rather than
                // fail — it has to be asserted, not inferred.
                if ($linkSpec['strength']['mode'] === 'quantitative') {
                    expect((float) $pivot->strength_fraction)->toBe((float) $linkSpec['strength']['fraction']);
                } else {
                    expect($pivot->strength_level_id)->not->toBeNull("{$key}: edge {$attackRef} -> {$defenceSpec['ref']} resolved to no strength level");
                }
            }
        }
    }
});

/*
 * The other half of the diagnosis, and the reason TemplateAssessmentSeeder
 * now documents a reseed procedure.
 *
 * `root_uuid` is an existence check, so a fixture that has seeded once is
 * skipped forever afterwards — every later edit to its content is
 * invisible to that instance, and `db:seed` reports success. That is the
 * right default (a seeded template is a real assessment somebody may have
 * started editing, and overwriting it on deploy would be worse), but it
 * means "the fixture is correct" and "the running instance is correct" are
 * two different claims, and a deployment can hold the first without the
 * second.
 *
 * Pinned here so the trade-off cannot be changed by accident: if a future
 * package makes the seeder update in place, this test goes red and
 * whoever did it has to read the reasoning first.
 */
test('a fixture already seeded is skipped whole, so later content edits never reach the instance', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $fixture = w76AssessmentFixtures()['demo-webshop'];
    $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();

    // Stand in for an edited fixture by editing the seeded row instead:
    // the fixture files are read-only to a test, and the seeder cannot
    // tell the two cases apart — both are "the row differs from the file".
    $root->title = 'Edited in the running instance';
    $root->saveQuietly();

    (new SampleContentSeeder)->run();

    expect($root->fresh()->title)->toBe('Edited in the running instance');
    expect(Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->count())->toBe(1);
});

// --- step 2: the cloner ---------------------------------------------------

test('step 2: instantiating a seeded template reproduces the whole tree, not just its root', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $user = User::factory()->create();
    $cloner = app(AssessmentCloner::class);

    foreach (w76AssessmentFixtures() as $key => $fixture) {
        $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();
        $source = Assessment::findOrFail($root->assessment_id);

        $clone = $cloner->cloneAssessment($source, $user);

        // `references` was excluded here while W76's pin below held, because
        // it was the one count cloneAssessment() did not reproduce. W78 fixed
        // that, so the whole shape is compared — references included.
        expect(w76CountRows($clone))->toBe(w76CountRows($source), "cloning '{$key}' did not reproduce its shape");
        expect($clone->id)->not->toBe($source->id);

        $clonedUuids = Node::withoutGlobalScopes()->where('assessment_id', $clone->id)->pluck('uuid');
        $sourceUuids = Node::withoutGlobalScopes()->where('assessment_id', $source->id)->pluck('uuid');
        expect($clonedUuids->intersect($sourceUuids))->toBeEmpty("cloning '{$key}' reused a source uuid");
    }
});

/**
 * FIXED (W78): Structure\AssessmentCloner::cloneAssessment() silently
 * discarded every `node_references` row. Found and pinned by W76 step 2,
 * fixed by W78; this block is the pin rewritten in place as a regression
 * guard rather than deleted, so the reasoning survives and the fix cannot be
 * quietly reverted (CLAUDE.md, as W24's security pins and W68's observer pin
 * were handled).
 *
 * ## What happens
 *
 * cloneAssessment() cloned scales, tags, nodes, `node_tag`, `risk_attack`
 * and `attack_defence`. `node_references` (schema.md §3.12 — CVE, CWE,
 * CAPEC, ATT&CK technique and free URLs) was simply not in the list, and
 * nothing errored, because the table is a plain child of `nodes` with no
 * NOT NULL pointing back at the clone. Measured across the five seeded
 * assessment fixtures: source 5/7/8/6/3 references, clone 0 in every case.
 *
 * ## Why it mattered
 *
 * This is the template-instantiation path — `TemplateController::store()`
 * calls cloneAssessment() unmodified. So every citation a template author
 * attached to an attack disappeared the moment somebody started an
 * assessment from that template, leaving the attack card's reference list
 * empty while the rest of the node looked intact. That is content loss that
 * shows up as "the structure is there but part of it is not", and it is
 * the closest thing W76's investigation found to the emptiness PLAN-2.4
 * item 2 reports.
 *
 * `cloneSubtree()` dropped them too — the same omission, reached from the
 * editor's duplicate affordance — and is fixed and guarded here as well.
 * W76's pin also named `insertCatalogEntry()`; that turned out to be wrong,
 * and deliberately no assertion was invented for it. Its source is a
 * `CatalogEntry`, and catalogue entries carry no references at all
 * (schema.md §3.14 — kind, title, description, `defaults`, and no relation
 * to `node_references`), so there was nothing on that path to drop.
 *
 * ## The fix, and its trade-off
 *
 * In AssessmentCloner, alongside cloneNodeTags(): read the source nodes'
 * `node_references` rows and re-insert them against the mapped clone node
 * ids, carrying `kind`, `identifier`, `url`, `title` and `position`. One
 * SELECT and one INSERT for the whole clone, **not** `replicate()` and not
 * an Eloquent `create()` — `node_id` is an identity column deliberately
 * absent from NodeReference's `$fillable` (CLAUDE.md), and the query builder
 * never consults `$fillable` at all. The N+1 guard below is the other half
 * of that choice.
 *
 * The trade-off W76 named is that `node_references_unique (node_id, kind,
 * identifier)` turns a duplicated reference from a silent no-op into a hard
 * insert failure that rolls the clone back. W78 took that direction
 * deliberately — a faithful copy is the contract, and a source holding a
 * pair the index forbids means something wrote around the index, which is
 * worth stopping for rather than papering over with insertOrIgnore().
 *
 * It is also unreachable through this path, and the last two guards below
 * are why: every clone node is new so nothing pre-exists to collide with,
 * two batch rows can only collide if one *source* node held that pair, and
 * the index forbids exactly that. The one case that looks like a collision
 * and is not — several `url` references on one node, which have no
 * identifier, and NULL never collides in a unique index — is exercised
 * end-to-end rather than reasoned about.
 */
test('FIXED (W78): cloning an assessment carries every external reference on its nodes', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $referenceCount = function (Assessment $a): int {
        $ids = Node::withoutGlobalScopes()->where('assessment_id', $a->id)->pluck('id');

        return DB::table('node_references')->whereIn('node_id', $ids)->count();
    };

    // All five fixtures, not the one the pin measured: the defect was that
    // the clone held zero, and zero is equally wrong at every size.
    foreach (w76AssessmentFixtures() as $key => $fixture) {
        $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();
        $source = Assessment::findOrFail($root->assessment_id);

        $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

        $expected = w76CountFixture($fixture)['references'];

        // The source genuinely has references — otherwise this test would
        // pass for the wrong reason the day someone empties the fixture.
        expect($referenceCount($source))->toBe($expected, "fixture '{$key}': source references")
            ->and($expected)->toBeGreaterThan(0, "fixture '{$key}' declares no references to lose");

        // The specified value, not merely "more than zero": a clone that
        // copied one row of five would satisfy a presence assertion.
        expect($referenceCount($clone))->toBe($expected, "cloning '{$key}' did not carry its references");
    }
});

test('FIXED (W78): a cloned reference keeps its payload and its node, not just its count', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    // Keyed by the owning node's *title*, because the ids differ by
    // construction and the uuids are remapped: a copy that landed every
    // reference on the wrong node would still count correctly.
    $byNode = function (Assessment $a): array {
        $nodes = Node::withoutGlobalScopes()->where('assessment_id', $a->id)->get()->keyBy('id');

        return DB::table('node_references')
            ->whereIn('node_id', $nodes->keys())
            ->orderBy('position')
            ->get()
            ->map(fn ($r): array => [
                'node' => (string) $nodes[$r->node_id]->title,
                'kind' => $r->kind,
                'identifier' => $r->identifier,
                'url' => $r->url,
                'title' => $r->title,
                'position' => (int) $r->position,
            ])
            ->sortBy(fn (array $row): string => (string) json_encode($row))
            ->values()
            ->all();
    };

    // Every fixture, not one: `archetype-public-web-app` happens to carry
    // exactly one reference per attack, so every `position` in it is 0 and
    // a clone that hardcoded 0 would satisfy this test on that fixture
    // alone. W78 found that by mutation-testing it and watching the
    // mutation survive. `archetype-iot-embedded` and
    // `archetype-internal-network` have nodes carrying two, so the
    // precondition below is what keeps the ordering genuinely asserted.
    $multiReference = 0;

    foreach (w76AssessmentFixtures() as $key => $fixture) {
        $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();
        $source = Assessment::findOrFail($root->assessment_id);

        $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

        expect($byNode($clone))->toBe($byNode($source), "cloning '{$key}' altered a reference's payload or its node");
        expect($byNode($source))->not->toBeEmpty("fixture '{$key}' declares no references");

        foreach ($fixture['attacks'] as $attack) {
            if (count($attack['references'] ?? []) > 1) {
                $multiReference++;
            }
        }
    }

    // Without at least one node holding two references, every position is
    // 0 and the comparison above cannot tell a preserved position from a
    // hardcoded one.
    expect($multiReference)->toBeGreaterThan(0);
});

test('FIXED (W78): duplicating an attack carries its references too', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $fixture = w76AssessmentFixtures()['archetype-public-web-app'];
    $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();
    $source = Assessment::findOrFail($root->assessment_id);

    // The other path into the same omission: the editor's duplicate
    // affordance, which goes through cloneSubtree() rather than through
    // TemplateController. Pick an attack that actually carries references,
    // so the assertion cannot pass on an empty node.
    $attack = Attack::withoutGlobalScopes()
        ->where('assessment_id', $source->id)
        ->whereIn('id', DB::table('node_references')->select('node_id'))
        ->firstOrFail();

    $expected = DB::table('node_references')->where('node_id', $attack->id)->count();
    expect($expected)->toBeGreaterThan(0);

    $duplicate = app(AssessmentCloner::class)->cloneSubtree($attack, $source);

    expect(DB::table('node_references')->where('node_id', $duplicate->id)->count())->toBe($expected);
    expect($duplicate->id)->not->toBe($attack->id);
});

test('FIXED (W78): copying references costs one query, not one per node', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $fixture = w76AssessmentFixtures()['demo-webshop'];
    $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();
    $source = Assessment::findOrFail($root->assessment_id);
    $owner = User::factory()->create();

    $referencedNodes = DB::table('node_references')
        ->whereIn('node_id', Node::withoutGlobalScopes()->where('assessment_id', $source->id)->pluck('id'))
        ->distinct()
        ->count('node_id');

    // The guard only means something if a per-node implementation would
    // actually have cost more than the two queries allowed below.
    expect($referencedNodes)->toBeGreaterThan(2);

    $touched = 0;
    DB::listen(function ($query) use (&$touched): void {
        if (str_contains($query->sql, 'node_references')) {
            $touched++;
        }
    });

    app(AssessmentCloner::class)->cloneAssessment($source, $owner);

    // Exactly the SELECT and the bulk INSERT — the shape cloneNodeTags()
    // has used since W43. A per-node read or a per-row save() would scale
    // this with the assessment, which is the N+1 CLAUDE.md forbids and W68
    // spent a package removing from the observer layer.
    expect($touched)->toBe(2);
});

test('FIXED (W78): several url references on one node survive the clone, NULL identifiers and all', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $fixture = w76AssessmentFixtures()['demo-webshop'];
    $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();
    $source = Assessment::findOrFail($root->assessment_id);

    $attack = Node::withoutGlobalScopes()
        ->where('assessment_id', $source->id)
        ->where('type', 'attack')
        ->firstOrFail();

    // `url` references carry no identifier, so `node_references_unique`
    // does not constrain them — NULL never collides in a unique index.
    // Three of them on one node is the case that *looks* like the collision
    // the fix's trade-off describes and provably is not.
    $now = now();
    DB::table('node_references')->insert([
        ['node_id' => $attack->id, 'kind' => 'url', 'identifier' => null, 'url' => 'https://example.test/a', 'title' => 'A', 'position' => 90, 'created_at' => $now, 'updated_at' => $now],
        ['node_id' => $attack->id, 'kind' => 'url', 'identifier' => null, 'url' => 'https://example.test/b', 'title' => 'B', 'position' => 91, 'created_at' => $now, 'updated_at' => $now],
        ['node_id' => $attack->id, 'kind' => 'url', 'identifier' => null, 'url' => 'https://example.test/c', 'title' => 'C', 'position' => 92, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

    $clonedUrls = DB::table('node_references')
        ->whereIn('node_id', Node::withoutGlobalScopes()->where('assessment_id', $clone->id)->pluck('id'))
        ->where('kind', 'url')
        ->orderBy('position')
        ->pluck('url')
        ->all();

    expect($clonedUrls)->toBe(['https://example.test/a', 'https://example.test/b', 'https://example.test/c']);
});

test('FIXED (W78): the unique index is what makes the clone-time collision unreachable', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $fixture = w76AssessmentFixtures()['demo-webshop'];
    $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();
    $source = Assessment::findOrFail($root->assessment_id);

    $attack = Node::withoutGlobalScopes()
        ->where('assessment_id', $source->id)
        ->where('type', 'attack')
        ->firstOrFail();

    $now = now();
    $row = ['node_id' => $attack->id, 'kind' => 'cve', 'identifier' => 'CVE-2024-3094', 'url' => null, 'title' => null, 'position' => 80, 'created_at' => $now, 'updated_at' => $now];

    DB::table('node_references')->insert($row);

    // This is the whole argument for keeping a plain insert() in the cloner
    // rather than insertOrIgnore(): a source node cannot hold two rows with
    // the same (kind, identifier), so the batch built from it cannot either.
    // If this index is ever relaxed, this test goes red and whoever relaxed
    // it has to decide what a colliding clone should do.
    expect(fn () => DB::table('node_references')->insert([...$row, 'title' => 'a second copy']))
        ->toThrow(QueryException::class);
});

test('step 2: the cloned tree keeps its edge payload, not only its edges', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $fixture = w76AssessmentFixtures()['demo-webshop'];
    $root = Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->firstOrFail();
    $source = Assessment::findOrFail($root->assessment_id);

    $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

    $edgePayload = function (Assessment $assessment): array {
        $ids = Node::withoutGlobalScopes()->where('assessment_id', $assessment->id)->pluck('id');

        return DB::table('attack_defence')
            ->whereIn('attack_node_id', $ids)
            ->orderBy('strength_mode')
            ->orderBy('strength_fraction')
            ->orderBy('position')
            ->get(['strength_mode', 'strength_fraction', 'reduction_target', 'rationale'])
            ->map(fn ($row): array => (array) $row)
            ->sortBy(fn (array $row): string => json_encode($row))
            ->values()
            ->all();
    };

    expect($edgePayload($clone))->toBe($edgePayload($source));
    expect($edgePayload($source))->not->toBeEmpty();
});

// --- step 3: the catalogue inserter --------------------------------------

test('step 3: inserting a seeded catalogue bundle builds the subtree the fixture declares', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $owner = User::factory()->create();
    $inserter = app(CatalogInserter::class);

    foreach (w76CatalogFixtures() as $key => $fixture) {
        $entriesByRef = collect($fixture['entries'])->keyBy('ref');

        // Every risk-kind entry is a bundle root; inserting one must bring
        // its attacks and their defences with it. The expectation is the
        // fixture's own transitive closure counted by **distinct ref** for
        // nodes and by **occurrence** for edges — which is what a DAG means:
        // an entry reachable from two parents inside one bundle is one node
        // with two edges into it. Until W78 the inserter made a fresh node
        // per edge it walked, and this walk had to count nodes by occurrence
        // to match; the guard for that is directly below.
        foreach ($fixture['entries'] as $entrySpec) {
            if ($entrySpec['kind'] !== 'risk') {
                continue;
            }

            $expectedNodes = ['risk' => 0, 'attack' => 0, 'defence' => 0];
            $edges = ['risk_attack' => 0, 'attack_defence' => 0];

            $seen = [];

            $walk = function (string $ref) use (&$walk, $entriesByRef, &$expectedNodes, &$edges, &$seen): void {
                // The edge into this ref was counted by the caller before it
                // recursed, so returning early here drops the duplicate
                // *node* and its already-counted subtree, not the edge.
                if (isset($seen[$ref])) {
                    return;
                }
                $seen[$ref] = true;

                $spec = $entriesByRef[$ref];
                $expectedNodes[$spec['kind']]++;

                foreach ($spec['children'] ?? [] as $child) {
                    $edges[$spec['kind'] === 'risk' ? 'risk_attack' : 'attack_defence']++;
                    $walk($child['ref']);
                }
            };
            $walk($entrySpec['ref']);

            $assessment = Assessment::factory()->for($owner, 'owner')->create();
            $entry = CatalogEntry::query()
                ->whereNull('owner_user_id')
                ->where('kind', 'risk')
                ->where('title', $entrySpec['title'])
                ->firstOrFail();

            $inserter->insert($entry, $assessment, null, $owner->id);

            $counts = w76CountRows($assessment);

            expect($counts['risks'])->toBe($expectedNodes['risk'], "{$key}/{$entrySpec['ref']}: risks");
            expect($counts['attacks'])->toBe($expectedNodes['attack'], "{$key}/{$entrySpec['ref']}: attacks");
            expect($counts['defences'])->toBe($expectedNodes['defence'], "{$key}/{$entrySpec['ref']}: defences");
            expect($counts['risk_attack'])->toBe($edges['risk_attack'], "{$key}/{$entrySpec['ref']}: risk->attack edges");
            expect($counts['attack_defence'])->toBe($edges['attack_defence'], "{$key}/{$entrySpec['ref']}: attack->defence edges");

            // The same emptiness guard as step 1: a bundle that inserts a
            // lone risk and no children is exactly the reported symptom.
            expect($counts['attacks'])->toBeGreaterThan(0, "{$key}/{$entrySpec['ref']} inserted a risk with no attacks")
                ->and($counts['defences'])->toBeGreaterThan(0, "{$key}/{$entrySpec['ref']} inserted attacks with no defences");
        }
    }
});

/**
 * FIXED (W78): Catalog\CatalogInserter::insertRecursive() instantiated a
 * fresh node for every edge it walked, so a catalogue entry reachable from
 * two parents inside one bundle landed as two separate nodes instead of one
 * node linked from both. Found and pinned by W76 step 3, fixed by W78; the
 * pin is rewritten in place as a regression guard rather than deleted.
 *
 * ## What happened
 *
 * `catalog_entry_links` is a DAG — `loadBundle()` even guards against
 * revisiting an already-expanded parent — but the recursion below it kept
 * no map from CatalogEntry id to the Node it had already made in this run.
 * The shipped ISO/IEC 27001+27002 fixture has exactly one such case:
 * `defence-access-control-policy` is a child of both
 * `attack-credential-compromise` and `attack-privilege-escalation` under
 * the risk `risk-unauthorized-access-info`. Inserting that bundle yielded
 * ten defence nodes for nine distinct controls; it now yields nine.
 *
 * ## Why it mattered
 *
 * CLAUDE.md's domain model is explicit that this is a DAG and that "a
 * defence [may belong] to several attacks" precisely so one control is one
 * node — its cost is counted once and editing it changes it everywhere.
 * Two copies meant the defence library showed the same control twice, and
 * `capex_amount` / `opex_annual_amount` were counted twice in any
 * cost rollup while the ALE stayed correct (the calculation is per edge, so
 * the duplicate reduced the same product it would have reduced anyway).
 * Nothing errored and nothing was missing, which is why it survived: the
 * result was over-full, not empty.
 *
 * ## The fix, and its trade-off
 *
 * CatalogInsertRun gained a `[catalog_entry_id => Node]` map, and
 * insertRecursive() reuses a node it has already instantiated in this run
 * instead of calling instantiate() again — linking the existing node to
 * the new parent and applying that edge's own defaults, which is the whole
 * point of strength living on the pivot. It also stops recursing on the
 * reused node: its children came in on the first occurrence, and walking
 * them again would rebuild every descendant edge.
 *
 * The trade-off, accepted deliberately: the two edges now share one node,
 * so the *node* defaults of the second occurrence (status, capex, opex from
 * `catalog_entries.defaults`) are the first occurrence's. That is the right
 * answer for a DAG and the wrong answer for anyone relying on per-parent
 * copies, so this was a behaviour change rather than a pure repair — which
 * is why W76 pinned it for a decision instead of fixing it quietly.
 */
test('FIXED (W78): a catalogue entry shared by two parents in one bundle inserts as one node with two edges', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $fixture = w76CatalogFixtures()['iso27001-27002'];
    $shared = collect($fixture['entries'])->firstWhere('ref', 'defence-access-control-policy');
    $bundleRoot = collect($fixture['entries'])->firstWhere('ref', 'risk-unauthorized-access-info');

    // The fixture precondition this pin rests on: the shared entry really
    // is a child of two of that risk's attacks. If a later edit removes
    // the sharing, this fails here rather than passing vacuously.
    $parents = collect($fixture['entries'])
        ->filter(fn (array $e): bool => collect($e['children'] ?? [])->contains(fn (array $c): bool => $c['ref'] === 'defence-access-control-policy'))
        ->pluck('ref');
    expect($parents)->toHaveCount(2);

    $owner = User::factory()->create();
    $assessment = Assessment::factory()->for($owner, 'owner')->create();

    $entry = CatalogEntry::query()
        ->whereNull('owner_user_id')
        ->where('kind', 'risk')
        ->where('title', $bundleRoot['title'])
        ->firstOrFail();

    app(CatalogInserter::class)->insert($entry, $assessment, null, $owner->id);

    $copies = Node::withoutGlobalScopes()
        ->where('assessment_id', $assessment->id)
        ->where('type', 'defence')
        ->where('title', $shared['title'])
        ->get();

    // One node, not two — the specified count, so a regression that made
    // three would fail here as loudly as the two this replaced.
    expect($copies)->toHaveCount(1);

    // ...reached from both attacks. Asserting the edges as well as the node
    // is what stops the opposite regression: a "fix" that deduplicated by
    // dropping the second occurrence entirely would leave one node with one
    // edge and satisfy the count above on its own.
    $parentAttacks = DB::table('attack_defence')
        ->where('defence_node_id', $copies->first()->id)
        ->distinct()
        ->count('attack_node_id');

    expect($parentAttacks)->toBe(2);

    // And the whole bundle is nine controls, not ten: the count the pin
    // measured, stated here so the arithmetic is not only implied by the
    // fixture-derived walk in step 3 above.
    expect(Node::withoutGlobalScopes()
        ->where('assessment_id', $assessment->id)
        ->where('type', 'defence')
        ->count())->toBe(9);
});

test('step 3: every seeded catalogue entry and bundle link the fixtures declare exists', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $expectedByKind = ['risk' => 0, 'attack' => 0, 'defence' => 0];
    $expectedLinks = 0;

    foreach (w76CatalogFixtures() as $fixture) {
        foreach ($fixture['entries'] as $entrySpec) {
            $expectedByKind[$entrySpec['kind']]++;
            $expectedLinks += count($entrySpec['children'] ?? []);

            expect(CatalogEntry::query()
                ->whereNull('owner_user_id')
                ->where('kind', $entrySpec['kind'])
                ->where('title', $entrySpec['title'])
                ->exists())->toBeTrue("catalogue entry '{$entrySpec['ref']}' was not seeded");
        }
    }

    foreach ($expectedByKind as $kind => $expected) {
        expect(CatalogEntry::query()->whereNull('owner_user_id')->where('kind', $kind)->count())
            ->toBe($expected, "seeded system catalogue has the wrong number of '{$kind}' entries");
    }

    expect(DB::table('catalog_entry_links')->count())->toBe($expectedLinks);
});
