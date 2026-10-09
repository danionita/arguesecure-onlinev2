<?php

namespace App\Http\Controllers;

use App\Domain\History\UndoService;
use App\Domain\Locking\LockManager;
use App\Domain\Structure\LinkService;
use App\Http\Requests\Assessment\DestroyNodeRequest;
use App\Http\Requests\Assessment\SearchNodesRequest;
use App\Http\Requests\Assessment\StoreNodeRequest;
use App\Http\Requests\Assessment\UpdateNodeRequest;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Node CRUD for the tree editor (ui.md §3.3-§3.5, PLAN.md W12). Every
 * action is fronted by a FormRequest whose authorize() calls
 * AssessmentPolicy via NodePolicy — no exceptions (CLAUDE.md).
 */
class NodeController extends Controller
{
    /**
     * A suggestion list is scanned, not read. Ten is about what fits without
     * scrolling, and a longer list is a sign the search term is too short
     * rather than a reason to render more of it.
     */
    private const SEARCH_LIMIT = 10;

    public function store(StoreNodeRequest $request, Assessment $assessment, LinkService $links, UndoService $undo): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;

        $node = DB::transaction(function () use ($validated, $assessment, $userId, $links): Node {
            // Fixed three-way match, never a dynamic class lookup built
            // from request input (security.md defect #7, CLAUDE.md) —
            // mirrors the hardcoded map Node::$typeMap uses for hydration.
            $node = match ($validated['type']) {
                'risk' => new Risk,
                'attack' => new Attack,
                'defence' => new Defence,
                // Unreachable: StoreNodeRequest::rules() whitelists 'type'
                // to exactly these three values (Rule::in) — this default
                // arm exists only so the match is exhaustive over `mixed`
                // to static analysis, matching Node::$typeMap's own
                // "hardcoded map, never dynamic" contract.
                default => throw new \InvalidArgumentException("Unsupported node type: {$validated['type']}"),
            };

            $node->assessment_id = $assessment->id;
            $node->title = $validated['title'] ?? '';
            // From the confirmed draft, or an accepted suggestion (W52).
            $node->description = $validated['description'] ?? null;
            if ($node instanceof Defence) {
                if (isset($validated['status'])) {
                    $node->status = $validated['status'];
                }
                $node->is_transfer = (bool) ($validated['is_transfer'] ?? false);
            }
            $node->created_by = $userId;
            $node->updated_by = $userId;

            // `nodes.position` orders risks under the root and defences in
            // the assessment library (schema.md §3.4); attack/defence
            // occurrence order lives on the edge and is set below via
            // LinkService's own next-position logic.
            if ($validated['type'] === 'risk' || $validated['type'] === 'defence') {
                $max = Node::withoutGlobalScopes()
                    ->where('assessment_id', $assessment->id)
                    ->where('type', $validated['type'])
                    ->max('position');
                $node->position = $max === null ? 0 : (int) $max + 1;
            }

            $node->save();

            if ($validated['type'] === 'attack') {
                /** @var Risk $risk */
                $risk = Risk::query()->where('uuid', $validated['risk_uuid'])->firstOrFail();
                $links->linkRiskToAttack($risk, $node);
            } elseif ($validated['type'] === 'defence') {
                /** @var Attack $attack */
                $attack = Attack::query()->where('uuid', $validated['attack_uuid'])->firstOrFail();
                $links->linkAttackToDefence($attack, $node);
            }

            return $node;
        });

        // Pushed after the transaction commits, so a failed create never
        // leaves an operation on the stack whose inverse has nothing to undo.
        $undo->record(
            $assessment,
            $request->user(),
            'node.create',
            sprintf('creation of %s', $this->describe($node)),
            ['action' => 'delete_node', 'node_uuids' => [$node->uuid]],
        );

        return response()->json(['node' => $this->nodeToArray($node->fresh())], 201);
    }

    /**
     * Suggestions for the add-a-child field (ui.md §3.3, W40).
     *
     * Adding a child used to mean creating a node, always, which is how an
     * assessment ends up with "Phishing" three times under three risks —
     * three nodes, three sets of defences to keep in step, and a total that
     * counts the same attack as if it were three different problems. The DAG
     * has always allowed one node under several parents; nothing in the UI
     * offered it at the moment the user is actually deciding.
     *
     * Scoped to this assessment and to a single type. The `type` value is
     * mapped through an explicit match(), never used to build a class name
     * (security.md defect #7) — the same rule store() follows.
     */
    public function search(SearchNodesRequest $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validated();
        $term = trim((string) ($validated['q'] ?? ''));
        $parentUuid = $validated['parent_uuid'] ?? null;

        $query = match ($validated['type']) {
            'attack' => Attack::query(),
            'defence' => Defence::query(),
            // Unreachable: SearchNodesRequest whitelists these two via
            // Rule::in. Present so the match is exhaustive over `mixed`.
            default => throw new \InvalidArgumentException("Unsupported node type: {$validated['type']}"),
        };

        // Pivot columns, not an Eloquent relation, and this is not a style
        // preference.
        //
        // `risk_attack` and `attack_defence` are self-referencing: both ends
        // are rows in `nodes`. Eloquent handles that by aliasing the related
        // table inside the subquery (`nodes as laravel_reserved_0`), while
        // Risk/Attack/Defence each carry a global scope written as a literal
        // `where('nodes.type', …)`. Inside an aliased subquery that literal
        // binds to the *outer* nodes row, so the scope reads "this attack's
        // type is 'risk'" — always false. `withCount('risks')` therefore
        // returns 0 for every attack, and `whereDoesntHave('risks', …)`
        // excludes nothing at all, both silently and both with green-looking
        // SQL. That is how the first version of this method shipped a count
        // of zero and an exclusion that never excluded.
        [$pivot, $childKey, $parentKey] = $validated['type'] === 'attack'
            ? ['risk_attack', 'attack_node_id', 'risk_node_id']
            : ['attack_defence', 'defence_node_id', 'attack_node_id'];

        $query
            ->select('nodes.*')
            ->addSelect(['occurrences' => DB::table($pivot)
                ->selectRaw('count(*)')
                ->whereColumn("{$pivot}.{$childKey}", 'nodes.id'),
            ])
            ->where('assessment_id', $assessment->id);

        if ($term !== '') {
            // Escaped by hand: an unescaped `%` in a user's search term
            // matches everything, which turns a suggestion box into a
            // full listing on one keystroke.
            $escaped = addcslashes($term, '%_\\');
            $query->where('title', 'like', "%{$escaped}%");
        }

        // A node already under this parent is not a suggestion — choosing it
        // would write a duplicate edge against the unique index and 500.
        // Same reasoning as W38's `canLink`, applied one step earlier so the
        // option never appears rather than failing when taken.
        if ($parentUuid !== null) {
            $parentId = Node::withoutGlobalScopes()
                ->where('assessment_id', $assessment->id)
                ->where('uuid', $parentUuid)
                ->value('id');

            $query->whereNotExists(fn ($sub) => $sub->from($pivot)
                ->whereColumn("{$pivot}.{$childKey}", 'nodes.id')
                ->where("{$pivot}.{$parentKey}", $parentId));
        }

        /** @var Collection<int, Node> $matches */
        $matches = $query
            // Most recently touched first: the node someone is linking to is
            // usually the one they were just looking at.
            ->orderByDesc('updated_at')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return response()->json([
            'nodes' => $matches->map(fn (Node $node) => [
                'uuid' => $node->uuid,
                'title' => (string) $node->title,
                'description' => $this->snippet($node->description),
                // "Already under 2 risks" is the part that needs saying:
                // the whole point is linking to something you cannot see.
                // Read through getAttribute(): `occurrences` is a select
                // subquery, not a column or an accessor, so it exists on the
                // model at runtime and nowhere in its type.
                'occurrences' => (int) $node->getAttribute('occurrences'),
            ]),
        ]);
    }

    /** One line of context in a dropdown row, not a description field. */
    private function snippet(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        $flat = preg_replace('/\s+/', ' ', trim($description)) ?? '';

        return mb_strlen($flat) > 100 ? mb_substr($flat, 0, 99).'…' : $flat;
    }

    /**
     * Every PATCH carries `updated_at` (ui.md §3.5); on mismatch this
     * returns 409 with the current server row rather than clobbering a
     * concurrent edit. Locks are cooperative UX (W16); this check is the
     * actual correctness guarantee and stands on its own without them.
     */
    public function update(UpdateNodeRequest $request, Assessment $assessment, Node $node, LockManager $locks): JsonResponse
    {
        // 2.5.1: one editor per node. The modal holds a whole-node lock while
        // it is open and renders read-only for everyone else; this is the
        // backstop for a client that does not (a stale tab, a script). The
        // holder's own writes pass, including from their other tabs — the
        // lock names a user, not a connection.
        $holder = $locks->currentHolder($assessment->uuid, $node->uuid, LockController::NODE_LOCK);
        if ($holder !== null && $holder['user_id'] !== $request->user()?->id) {
            return response()->json([
                'message' => "Currently being edited by {$holder['name']}.",
                'holder' => $holder,
            ], 423);
        }

        $validated = $request->validated();
        $incomingUpdatedAt = Carbon::parse($validated['updated_at']);
        unset($validated['updated_at']);

        if ($node->updated_at !== null && ! $incomingUpdatedAt->eq($node->updated_at)) {
            return response()->json([
                'message' => 'This node was changed by someone else.',
                'node' => $this->nodeToArray($node),
            ], 409);
        }

        $node->fill($validated);
        $node->updated_by = $request->user()?->id;
        $node->save();

        return response()->json(['node' => $this->nodeToArray($node->fresh())]);
    }

    public function destroy(DestroyNodeRequest $request, Assessment $assessment, Node $node, UndoService $undo): JsonResponse
    {
        // Summary captured before the delete: after it, the title is still
        // readable but the prompt ("Undo Dana's deletion of 'Phishing'?") is
        // built from what the node was at the moment it was removed.
        $summary = sprintf('deletion of %s', $this->describe($node));

        $node->delete();

        // A soft delete leaves the edge rows in place (see DeleteNodeApplier),
        // which is what makes the inverse a links-intact restore.
        $undo->record(
            $assessment,
            $request->user(),
            'node.delete',
            $summary,
            ['action' => 'restore_node', 'node_uuids' => [$node->uuid]],
        );

        return response()->json(status: 204);
    }

    private function describe(Node $node): string
    {
        $title = trim((string) $node->title);

        return $title === ''
            ? sprintf('an untitled %s', $node->type)
            : sprintf("'%s'", $title);
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
            // W43. Present on every node payload, including a brand-new one
            // that cannot have any yet, so the client never has to tell "no
            // labels" apart from "this response predates labels" — the store
            // writes these records straight into `nodes`, and an `undefined`
            // there would reach the filter and the chip row.
            'tags' => $node->tags->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ])->values()->all(),
            'is_transfer' => (bool) $node->is_transfer,
            'status' => $node->status,
            'capex_amount' => $node->capex_amount,
            'opex_annual_amount' => $node->opex_annual_amount,
            'updated_at' => $node->updated_at?->toJSON(),
        ];
    }
}
