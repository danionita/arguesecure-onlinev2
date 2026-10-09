<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogInserter;
use App\Domain\Catalog\CatalogInsertionException;
use App\Domain\Catalog\CatalogSearch;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\CatalogEntry;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RiskAttack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalogue browser and inserter (schema.md §3.14, PLAN.md Phase 8
 * item 4). Per CLAUDE.md's W20 non-negotiables: browsing needs only an
 * authenticated user; inserting an entry into a specific assessment needs
 * `update` on that assessment — the same ability NodeController gates
 * node creation with, since an insert is exactly a node (or small tree of
 * nodes) create.
 *
 * No FormRequest class here — this package owns only this controller, not
 * app/Http/Requests/**, matching SnapshotController's/LockController's own
 * documented reason for doing authorization and validation inline.
 */
class CatalogController extends Controller
{
    public function index(Request $request, CatalogSearch $search): Response
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'string', 'in:risk,attack,defence'],
            'q' => ['nullable', 'string', 'max:120'],
            'assessment' => ['nullable', 'string', 'uuid'],
        ]);

        $user = $request->user();
        $entries = $search->search($user, $validated['kind'] ?? null, $validated['q'] ?? null);

        $assessment = null;
        $parents = ['risks' => [], 'attacks' => []];

        if (isset($validated['assessment'])) {
            $assessment = Assessment::query()->where('uuid', $validated['assessment'])->firstOrFail();

            if (Gate::forUser($user)->denies('update', $assessment)) {
                abort(403);
            }

            $parents = $this->parentCandidates($assessment);
        }

        return Inertia::render('Catalog/Index', [
            'entries' => $entries->map(fn (CatalogEntry $entry): array => $this->entryToArray($entry))->values(),
            'filters' => ['kind' => $validated['kind'] ?? null, 'q' => $validated['q'] ?? null],
            'assessment' => $assessment ? ['uuid' => $assessment->uuid] : null,
            'parents' => $parents,
        ]);
    }

    /**
     * Inserts one catalogue entry — and, if it is a bundle, every entry
     * pre-linked to it — into the target assessment. `parent_uuid` names
     * the risk (for an `attack` entry) or attack (for a `defence` entry)
     * the new node attaches to; required for anything but a `risk` entry,
     * enforced by CatalogInserter itself so this controller stays a thin
     * translation of the request, not a second copy of that rule.
     */
    public function insert(Request $request, Assessment $assessment, CatalogEntry $catalogEntry, CatalogInserter $inserter): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('update', $assessment)) {
            abort(403);
        }

        $validated = $request->validate([
            'parent_uuid' => ['nullable', 'string', 'uuid'],
        ]);

        $parent = null;

        if (isset($validated['parent_uuid'])) {
            // Scoped to this assessment before being handed to the
            // inserter — the same cross-assessment IDOR guard
            // SnapshotController's own docblock describes for its
            // non-scoped-binding routes.
            $parent = Node::query()
                ->where('assessment_id', $assessment->id)
                ->where('uuid', $validated['parent_uuid'])
                ->firstOrFail();
        }

        try {
            $result = $inserter->insert($catalogEntry, $assessment, $parent, $request->user()?->id);
        } catch (CatalogInsertionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'nodes' => array_map(fn (Node $node): array => $this->nodeToArray($node), $result['nodes']),
            'riskAttackLinks' => array_map(fn (RiskAttack $link): array => [
                'risk_uuid' => $link->risk?->uuid,
                'attack_uuid' => $link->attack?->uuid,
                'position' => $link->position,
            ], $result['riskAttackLinks']),
            'attackDefenceLinks' => array_map(fn (AttackDefence $link): array => [
                'attack_uuid' => $link->attack?->uuid,
                'defence_uuid' => $link->defence?->uuid,
                'position' => $link->position,
                'strength_mode' => $link->strength_mode,
                'strength_fraction' => $link->strength_fraction,
                'strength_level_id' => $link->strength_level_id,
                'reduction_target' => $link->reduction_target,
            ], $result['attackDefenceLinks']),
        ], 201);
    }

    /** @return array{risks: list<array<string, mixed>>, attacks: list<array<string, mixed>>} */
    private function parentCandidates(Assessment $assessment): array
    {
        $risks = Risk::query()->where('assessment_id', $assessment->id)->orderBy('position')->get(['uuid', 'title']);
        $attacks = Attack::query()->where('assessment_id', $assessment->id)->orderBy('position')->get(['uuid', 'title']);

        return [
            'risks' => $risks->map(fn (Risk $r): array => ['uuid' => $r->uuid, 'title' => (string) $r->title])->values()->all(),
            'attacks' => $attacks->map(fn (Attack $a): array => ['uuid' => $a->uuid, 'title' => (string) $a->title])->values()->all(),
        ];
    }

    /**
     * Never emits owner_user_id or any user field — see CLAUDE.md's
     * over-serialisation warning.
     *
     * @return array<string, mixed>
     */
    private function entryToArray(CatalogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'title' => $entry->title,
            'description' => $entry->description,
            'isBundle' => (int) $entry->getAttribute('catalog_children_count') > 0,
            'isPublic' => (bool) $entry->is_public,
            'isSystem' => $entry->owner_user_id === null,
        ];
    }

    /** @return array<string, mixed> */
    private function nodeToArray(Node $node): array
    {
        return [
            'uuid' => $node->uuid,
            'type' => $node->type,
            'title' => (string) $node->title,
            'description' => $node->description,
            'position' => $node->position,
            'impact_mode' => $node->impact_mode,
            'impact_amount' => $node->impact_amount,
            'impact_level_id' => $node->impact_level_id,
            'likelihood_mode' => $node->likelihood_mode,
            'likelihood_aro' => $node->likelihood_aro,
            'likelihood_level_id' => $node->likelihood_level_id,
            'is_transfer' => (bool) $node->is_transfer,
            'status' => $node->status,
            'capex_amount' => $node->capex_amount,
            'opex_annual_amount' => $node->opex_annual_amount,
            'updated_at' => $node->updated_at?->toJSON(),
        ];
    }
}
