<?php

namespace Database\Seeders;

use App\Domain\References\ReferenceValidator;
use App\Domain\Scales\ScaleSets;
use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RootNode;
use App\Models\Scale;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\Support\AssessmentFixtureValidator;
use Database\Seeders\Support\SeederFixtureException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads `database/seeders/templates/*.json` fixtures of `"kind":
 * "assessment"` — the demo web shop, the four system-archetype templates
 * (PLAN.md Phase 9 item 1) and the `standard-*` templates (2.5.1, W103) —
 * into real Assessment/Node rows.
 *
 * # Fixture schema (frozen for W22)
 *
 * ```json
 * {
 *   "schema_version": 1,
 *   "kind": "assessment",
 *   "key": "demo-webshop",
 *   "root_uuid": "<fixed uuid — the idempotency anchor>",
 *   "title": "...", "description": "...",
 *   "currency": "EUR", "amortization_years": 3,
 *   "is_template": false, "is_public": false,
 *   "tags": [{"name": "PII", "color": "#3B82F6"}],
 *   "risks": [{
 *     "ref": "risk-data-breach", "uuid": "<fixed uuid>",
 *     "title": "...", "description": "...",
 *     "impact": {"mode": "quantitative", "amount": 250000}
 *             | {"mode": "qualitative", "level_code": "major"},
 *     "tags": ["PII"],
 *     "attack_refs": ["attack-phishing", "..."]
 *   }],
 *   "attacks": [{
 *     "ref": "attack-phishing", "uuid": "<fixed uuid>",
 *     "title": "...", "description": "...",
 *     "likelihood": {"mode": "quantitative", "amount": 0.4}
 *                 | {"mode": "qualitative", "level_code": "possible"},
 *     "references": [{"kind": "cwe", "identifier": "CWE-287", "title": "..."}],
 *     "defence_refs": ["defence-mfa", "..."]
 *   }],
 *   "defences": [{
 *     "ref": "defence-mfa", "uuid": "<fixed uuid>",
 *     "title": "...", "description": "...",
 *     "is_transfer": false, "status": "implemented|todo|shelved",
 *     "capex_amount": 0, "opex_annual_amount": 6000,
 *     "references": [{"kind": "control", "identifier": "ISO27001-2022:A.5.17"}],
 *     "links": {
 *       "attack-phishing": {
 *         "strength": {"mode": "quantitative", "fraction": 0.75}
 *                    | {"mode": "qualitative", "level_code": "strong"},
 *         "reduction_target": "likelihood" | "impact" | null,
 *         "rationale": "..."
 *       }
 *     }
 *   }]
 * }
 * ```
 *
 * A risk's `attack_refs` and an attack's `defence_refs` are the DAG edges,
 * expressed by reference so the same attack (or defence) can be listed
 * under more than one parent — that is how a fixture expresses the shared
 * -attack case. `defences[*].links` carries the per-(attack,defence) pair
 * data — strength, target, rationale — that only makes sense per edge, not
 * per node (schema.md §3.6: "strength lives on the pivot, not on the
 * defence"). AssessmentFixtureValidator cross-checks that `defence_refs`
 * and `links` agree before this class ever touches the database.
 *
 * Every uuid in a fixture is fixed at authoring time and never
 * regenerated — that is the idempotency mechanism: `root_uuid` already
 * present in `nodes` means this fixture has already been seeded, so the
 * whole file is skipped.
 *
 * # Editing a fixture does not reach a deployment that already seeded it
 *
 * That last sentence is the whole of it, and W76 is where it started
 * mattering. The `root_uuid` check is a check on *existence*, not on
 * content: once a fixture has seeded once, every later edit to its titles,
 * descriptions, numbers, nodes or edges is invisible to that instance
 * forever. `db:seed` reports it as skipped and exits successfully.
 *
 * This is the correct default and should stay. A seeded template is one an
 * administrator may have edited since (W141), and quietly overwriting their
 * work on the next deploy would be far worse than leaving a stale copy. But it does mean a deployment can hold a version of an archetype
 * that no longer matches the file in this repository, which is exactly the
 * "the fixtures are complete but the running instance looks wrong" shape
 * W76 was sent to diagnose — and it is diagnosed by comparing, not
 * assumed.
 *
 * ## The reseed, when the instance genuinely is stale
 *
 * There is no automatic path and there should not be one. Reseeding is a
 * deliberate act, per fixture, taken by someone who has looked:
 *
 *   1. Confirm the instance is actually stale rather than edited. Find the
 *      root node by the fixture's `root_uuid` and compare the assessment's
 *      node and edge counts against the fixture. `tests/Feature/Seeders/
 *      SeededContentMatchesFixtureTest.php` is that comparison, written
 *      out; a node count *higher* than the fixture's means somebody has
 *      added to the template, and a reseed would destroy their work.
 *   2. Check nothing has been built from it. An assessment created from a
 *      template is an independent copy (AssessmentCloner), so instances
 *      already created are unaffected either way — but confirm the
 *      template itself is not an administrator's work in progress.
 *   3. Delete the stale template on the Templates page (an administrator),
 *      not in the database, so the deletion goes through the application.
 *      Deleting the template removes its nodes, which is what releases
 *      the `root_uuid`.
 *   4. Re-run the seeder. `php artisan db:seed --class=SampleContentSeeder
 *      --force`. With the uuid gone the fixture no longer matches an
 *      existing node and seeds fresh; every other fixture is skipped, so
 *      this is safe to run with only one deleted.
 *   5. Verify from the fixture, not by eye: the seeder logs `seeded=` and
 *      `skipped=` lists, and the key you deleted must appear in the first.
 *
 * A deployment that has never seeded anything gets the current fixtures
 * by definition (first-run setup seeds them), so it needs none of this.
 *
 * This class does not call `App\Domain\Structure\AssessmentCloner` or
 * `App\Domain\Catalog\CatalogInserter` — both solve a different problem
 * (cloning an existing assessment at runtime; inserting one catalogue
 * entry into an editing session) and neither is a fit for "build a fresh
 * assessment from a flat content description once, at seed time". It
 * builds nodes directly through the STI subclasses and reuses
 * `Structure\LinkService` for edges, the same primitives those two
 * services are themselves built from, so nothing here duplicates their
 * logic.
 */
class TemplateAssessmentSeeder extends Seeder
{
    private readonly AssessmentFixtureValidator $validator;

    private readonly LinkService $links;

    public function __construct()
    {
        $this->validator = new AssessmentFixtureValidator;
        $this->links = new LinkService;
    }

    /**
     * @param  list<string>  $filePaths  absolute paths to fixture files
     * @param  array{impact: Scale, likelihood: Scale, strength: Scale}  $systemScales
     * @return array{seeded: list<string>, skipped: list<string>}
     */
    public function seedFiles(array $filePaths, ?User $owner, array $systemScales): array
    {
        $seeded = [];
        $skipped = [];

        $levelIdsByKind = [
            'impact' => $systemScales['impact']->levels()->pluck('id', 'code')->all(),
            'likelihood' => $systemScales['likelihood']->levels()->pluck('id', 'code')->all(),
            'strength' => $systemScales['strength']->levels()->pluck('id', 'code')->all(),
        ];

        foreach ($filePaths as $path) {
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new SeederFixtureException("Could not read fixture file: {$path}");
            }

            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new SeederFixtureException("Fixture file is not valid JSON: {$path}");
            }

            $fixture = $this->validator->validate($decoded, $path);

            if (Node::withoutGlobalScopes()->where('uuid', $fixture['root_uuid'])->exists()) {
                $skipped[] = $fixture['key'];

                continue;
            }

            DB::transaction(function () use ($fixture, $owner, $systemScales, $levelIdsByKind): void {
                $this->seedOne($fixture, $owner, $systemScales, $levelIdsByKind);
            });

            $seeded[] = $fixture['key'];
        }

        return ['seeded' => $seeded, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array{impact: Scale, likelihood: Scale, strength: Scale}  $systemScales
     * @param  array<string, array<string, int>>  $levelIdsByKind
     */
    private function seedOne(array $fixture, ?User $owner, array $systemScales, array $levelIdsByKind): void
    {
        // A template belongs to nobody (W141): the owner, if one was passed,
        // owns only a fixture that is not a template, and an instance without
        // an admin seeds the whole library all the same.
        $isTemplate = (bool) ($fixture['is_template'] ?? false);
        if (! $isTemplate && $owner === null) {
            throw new SeederFixtureException("Fixture '{$fixture['key']}' is not a template and needs an owner.");
        }
        $actor = $isTemplate ? null : $owner;

        $assessment = new Assessment;
        $assessment->uuid = (string) Str::uuid();
        $assessment->owner_user_id = $actor?->id;
        $assessment->currency = $fixture['currency'];
        $assessment->display_mode = 'mixed';
        $assessment->impact_scale_id = $systemScales['impact']->id;
        $assessment->likelihood_scale_id = $systemScales['likelihood']->id;
        $assessment->strength_scale_id = $systemScales['strength']->id;
        // The default scale's rows, so the dropdown names it (W126).
        $assessment->scale_set_key = ScaleSets::DEFAULT_KEY;
        $assessment->amortization_years = $fixture['amortization_years'] ?? 3;
        $assessment->is_public = $isTemplate ? false : (bool) ($fixture['is_public'] ?? false);
        $assessment->is_template = $isTemplate;
        $assessment->save();

        $root = new RootNode;
        $root->uuid = $fixture['root_uuid'];
        $root->assessment_id = $assessment->id;
        $root->title = $fixture['title'];
        $root->description = $fixture['description'] ?? null;
        $root->created_by = $actor?->id;
        $root->updated_by = $actor?->id;
        $root->save();

        $tagsByName = [];
        foreach ((array) ($fixture['tags'] ?? []) as $tagSpec) {
            $tag = new Tag;
            $tag->assessment_id = $assessment->id;
            $tag->name = $tagSpec['name'];
            $tag->slug = Str::slug($tagSpec['name']);
            if (isset($tagSpec['color'])) {
                $tag->color = $tagSpec['color'];
            }
            $tag->save();
            $tagsByName[$tagSpec['name']] = $tag;
        }

        $attackNodes = [];
        foreach ($fixture['attacks'] as $attackSpec) {
            $attack = new Attack([
                'title' => $attackSpec['title'],
                'description' => $attackSpec['description'] ?? null,
            ]);
            $attack->uuid = $attackSpec['uuid'];
            $attack->assessment_id = $assessment->id;
            $attack->created_by = $actor?->id;
            $attack->updated_by = $actor?->id;
            [$mode, $key, $value] = $this->resolveValue($attackSpec['likelihood'], 'likelihood_aro', $levelIdsByKind['likelihood']);
            $attack->likelihood_mode = $mode;
            $attack->{$key} = $value;
            $attack->save();

            $attackNodes[$attackSpec['ref']] = $attack;
        }

        // Collected and written in one insert at the end: the standards
        // templates carry hundreds of citations, and first-run setup seeds
        // them inline, so a query per citation is a visible wait (W102).
        $referenceRows = [];
        $positions = ['risk' => 0, 'defence' => 0];

        $defenceNodes = [];
        foreach ($fixture['defences'] as $defenceSpec) {
            $defence = new Defence([
                'title' => $defenceSpec['title'],
                'description' => $defenceSpec['description'] ?? null,
                'is_transfer' => (bool) ($defenceSpec['is_transfer'] ?? false),
                'status' => $defenceSpec['status'],
                'capex_amount' => $defenceSpec['capex_amount'] ?? null,
                'opex_annual_amount' => $defenceSpec['opex_annual_amount'] ?? null,
            ]);
            $defence->uuid = $defenceSpec['uuid'];
            $defence->assessment_id = $assessment->id;
            $defence->created_by = $actor?->id;
            $defence->updated_by = $actor?->id;
            $defence->position = $positions['defence']++;
            $defence->save();

            foreach ((array) ($defenceSpec['references'] ?? []) as $i => $refSpec) {
                $referenceRows[] = $this->referenceRow($defence, $refSpec, $i);
            }

            $defenceNodes[$defenceSpec['ref']] = $defence;
        }

        foreach ($fixture['risks'] as $riskSpec) {
            $risk = new Risk([
                'title' => $riskSpec['title'],
                'description' => $riskSpec['description'] ?? null,
            ]);
            $risk->uuid = $riskSpec['uuid'];
            $risk->assessment_id = $assessment->id;
            $risk->created_by = $actor?->id;
            $risk->updated_by = $actor?->id;
            $risk->position = $positions['risk']++;
            [$mode, $key, $value] = $this->resolveValue($riskSpec['impact'], 'impact_amount', $levelIdsByKind['impact']);
            $risk->impact_mode = $mode;
            $risk->{$key} = $value;
            $risk->save();

            foreach ((array) ($riskSpec['tags'] ?? []) as $tagName) {
                $risk->tags()->attach($tagsByName[$tagName]->id);
            }

            foreach ($riskSpec['attack_refs'] as $attackRef) {
                $this->links->linkRiskToAttack($risk, $attackNodes[$attackRef]);
            }
        }

        foreach ($fixture['attacks'] as $attackSpec) {
            $attack = $attackNodes[$attackSpec['ref']];

            foreach ((array) ($attackSpec['references'] ?? []) as $i => $refSpec) {
                $referenceRows[] = $this->referenceRow($attack, $refSpec, $i);
            }

            foreach ($attackSpec['defence_refs'] as $defenceRef) {
                $defence = $defenceNodes[$defenceRef];
                $linkSpec = $fixture['defences'][$this->indexByRef($fixture['defences'], $defenceRef)]['links'][$attackSpec['ref']];

                $pivot = $this->links->linkAttackToDefence($attack, $defence, null, $linkSpec['reduction_target'] ?? null);
                [$mode, $key, $value] = $this->resolveValue($linkSpec['strength'], 'strength_fraction', $levelIdsByKind['strength']);
                $pivot->strength_mode = $mode;
                $pivot->{$key} = $value;
                $pivot->rationale = $linkSpec['rationale'] ?? null;
                $pivot->save();
            }
        }

        foreach (array_chunk($referenceRows, 500) as $chunk) {
            DB::table('node_references')->insert($chunk);
        }
    }

    /**
     * Resolves a ValueSpec (`{"mode": "quantitative", "<amountKey>": n}` or
     * `{"mode": "qualitative", "level_code": c}`) to the triple this
     * seeder assigns onto the node/pivot: the mode string, which of the
     * two mutually-exclusive columns to set, and its value. The level code
     * is resolved against the *system* scale actually loaded (not
     * hardcoded), so if a future system scale ever renumbers a level id
     * this still resolves correctly — matching schema.md §3.14's "codes,
     * never ids" rule even though these fixtures target the system
     * calibration only, never a custom one.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, int>  $levelIdsByCode
     * @return array{0: string, 1: string, 2: int|float}
     */
    private function resolveValue(array $spec, string $amountColumn, array $levelIdsByCode): array
    {
        $levelColumn = match ($amountColumn) {
            'impact_amount' => 'impact_level_id',
            'likelihood_aro' => 'likelihood_level_id',
            'strength_fraction' => 'strength_level_id',
            // Unreachable: every call site passes one of the three columns
            // above; this arm only exists so the match is provably
            // exhaustive to the type checker, the same shape
            // CatalogInserter::instantiateNode() uses for the identical
            // reason.
            default => throw new SeederFixtureException("Unknown amount column '{$amountColumn}'."),
        };

        if ($spec['mode'] === 'quantitative') {
            $amountKey = $amountColumn === 'strength_fraction' ? 'fraction' : 'amount';

            return [$spec['mode'], $amountColumn, (float) $spec[$amountKey]];
        }

        $code = $spec['level_code'];
        if (! isset($levelIdsByCode[$code])) {
            // The validator already restricts level_code to the fixed
            // system-scale code set, so this can only fire if
            // SystemScaleSeeder's bands and the validator's allowed-code
            // constants have drifted apart — fail loudly rather than
            // silently writing a NULL level id (W22 non-negotiables).
            throw new SeederFixtureException("Level code '{$code}' does not resolve against the seeded system scale.");
        }

        return [$spec['mode'], $levelColumn, $levelIdsByCode[$code]];
    }

    /**
     * One `node_references` row, inserted by seedOne() with the rest. The
     * url is derived from the identifier for every derived kind, exactly as
     * StoreNodeReferenceRequest does, and taken from the fixture only for
     * `url`/`other`.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function referenceRow(Node $node, array $spec, int $position): array
    {
        $kind = $spec['kind'];
        if (in_array($kind, ReferenceValidator::DERIVED_KINDS, true)) {
            $identifier = ReferenceValidator::normalizeIdentifier($kind, $spec['identifier']);
            $url = ReferenceValidator::deriveUrl($kind, $identifier);
        } else {
            $identifier = $spec['identifier'] ?? null;
            $url = $spec['url'];
        }

        $now = now();

        return [
            'node_id' => $node->id,
            'kind' => $kind,
            'identifier' => $identifier,
            'url' => $url,
            'title' => $spec['title'] ?? null,
            'position' => $position,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param  list<array<string, mixed>>  $defences */
    private function indexByRef(array $defences, string $ref): int
    {
        foreach ($defences as $i => $defence) {
            if ($defence['ref'] === $ref) {
                return $i;
            }
        }

        // Unreachable: AssessmentFixtureValidator already guarantees every
        // defence_ref resolves to a declared defence.
        throw new SeederFixtureException("Unresolvable defence ref '{$ref}'.");
    }
}
