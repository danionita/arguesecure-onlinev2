<?php

namespace App\Http\Controllers;

use App\Domain\History\RevisionRecorder;
use App\Domain\History\UndoService;
use App\Domain\Structure\LinkService;
use App\Http\Requests\Assessment\DestroyAttackDefenceLinkRequest;
use App\Http\Requests\Assessment\DestroyRiskAttackLinkRequest;
use App\Http\Requests\Assessment\StoreAttackDefenceLinkRequest;
use App\Http\Requests\Assessment\StoreRiskAttackLinkRequest;
use App\Http\Requests\Assessment\UpdateAttackDefenceLinkRequest;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Risk;
use Illuminate\Http\JsonResponse;

/**
 * Edge mutations for the node modal's Links tab (ui.md §7, PLAN.md W13):
 * which risks an attack belongs to, which defences apply to an attack, and
 * — because "strength belongs to the attack-defence edge, not the defence
 * node" — the per-link strength and reduction target.
 *
 * Creating and removing edges goes through LinkService (app/Domain/Structure,
 * not owned by this package) rather than duplicating its same-assessment
 * check here, matching CLAUDE.md's "business logic goes in app/Domain/".
 * Editing an *existing* edge's scalar fields (strength, reduction target,
 * rationale) is done directly against the AttackDefence pivot model here —
 * LinkService currently only exposes link/unlink, no field-level update —
 * the same shape NodeController already uses for a node's own scalar
 * fields ($node->fill()/save(), no dedicated "UpdateNodeService" either).
 * Reported in the W13 summary as a candidate for a future LinkService
 * method if link-editing logic grows beyond this.
 */
class LinkController extends Controller
{
    public function storeRiskAttack(StoreRiskAttackLinkRequest $request, Assessment $assessment, LinkService $links, UndoService $undo): JsonResponse
    {
        $validated = $request->validated();

        /** @var Risk $risk */
        $risk = Risk::query()->where('uuid', $validated['risk_uuid'])->firstOrFail();
        /** @var Attack $attack */
        $attack = Attack::query()->where('uuid', $validated['attack_uuid'])->firstOrFail();

        $link = $links->linkRiskToAttack($risk, $attack);

        $this->recordLink($undo, $assessment, 'risk_attack', $risk->uuid, $attack->uuid, $attack->title, $risk->title);

        return response()->json([
            'link' => [
                'risk_uuid' => $risk->uuid,
                'attack_uuid' => $attack->uuid,
                'position' => $link->position,
            ],
        ], 201);
    }

    public function destroyRiskAttack(DestroyRiskAttackLinkRequest $request, Assessment $assessment, LinkService $links): JsonResponse
    {
        $validated = $request->validated();

        /** @var Risk $risk */
        $risk = Risk::query()->where('uuid', $validated['risk_uuid'])->firstOrFail();
        /** @var Attack $attack */
        $attack = Attack::query()->where('uuid', $validated['attack_uuid'])->firstOrFail();

        $links->unlinkRiskFromAttack($risk, $attack);

        return response()->json(status: 204);
    }

    public function storeAttackDefence(StoreAttackDefenceLinkRequest $request, Assessment $assessment, LinkService $links, UndoService $undo): JsonResponse
    {
        $validated = $request->validated();

        /** @var Attack $attack */
        $attack = Attack::query()->where('uuid', $validated['attack_uuid'])->firstOrFail();
        /** @var Defence $defence */
        $defence = Defence::query()->where('uuid', $validated['defence_uuid'])->firstOrFail();

        $link = $links->linkAttackToDefence($attack, $defence, reductionTarget: $validated['reduction_target'] ?? null);

        $this->recordLink($undo, $assessment, 'attack_defence', $attack->uuid, $defence->uuid, $defence->title, $attack->title);

        return response()->json(['link' => $this->linkToArray($link, $attack, $defence)], 201);
    }

    /**
     * Push a link creation onto the undo stack (W38).
     *
     * Creating an edge was not undoable before this: W14 recorded reorder and
     * move from its own route closures, and W13 built this controller without
     * touching UndoService, so a link created from the Links tab left no trace
     * on the stack at all. W38 makes linking a drag gesture — one drop away,
     * on a projector, in front of a room — which turns "not undoable" from an
     * inconsistency into a hazard.
     *
     * The inverse is the existing `unlink` command, which captures the row's
     * own fields before deleting so a redo restores them exactly. No new
     * applier is needed; this was a missing caller, not a missing mechanism.
     *
     * The summary names the destination because the stack is shown to a
     * reader who did not necessarily perform the action ("Undo Dana's…"), and
     * "Linked 'Phishing'" does not say where.
     */
    private function recordLink(
        UndoService $undo,
        Assessment $assessment,
        string $edge,
        string $parentUuid,
        string $childUuid,
        string $childTitle,
        string $parentTitle,
    ): void {
        $undo->record(
            $assessment,
            request()->user(),
            'link.create',
            "Linked '{$childTitle}' to '{$parentTitle}'",
            [
                'action' => 'unlink',
                'edge' => $edge,
                'parent_uuid' => $parentUuid,
                'child_uuid' => $childUuid,
            ],
        );
    }

    /**
     * Field-level edit of an existing edge — see the class doc for why this
     * is direct Eloquent rather than a LinkService call.
     */
    public function updateAttackDefence(
        UpdateAttackDefenceLinkRequest $request,
        Assessment $assessment,
        RevisionRecorder $revisions,
    ): JsonResponse {
        $validated = $request->validated();

        /** @var Attack $attack */
        $attack = Attack::query()->where('uuid', $validated['attack_uuid'])->firstOrFail();
        /** @var Defence $defence */
        $defence = Defence::query()->where('uuid', $validated['defence_uuid'])->firstOrFail();

        /** @var AttackDefence $link */
        $link = AttackDefence::query()
            ->where('attack_node_id', $attack->id)
            ->where('defence_node_id', $defence->id)
            ->firstOrFail();

        unset($validated['attack_uuid'], $validated['defence_uuid']);

        // W56. Captured before the fill: the pivot has no observer to read
        // getOriginal() from the way NodeObserver does for a node, and this
        // method is its only writer.
        $before = $link->getAttributes();

        $link->fill($validated);
        $link->save();

        // Closes the first of W48's history gaps (schema.md §3.9). Strength is
        // a multiplicand in the total, so a change to it that left no trace
        // made a past total unreconstructable — see RevisionRecorder.
        $revisions->recordLinkUpdate($link, $attack, $defence, $before, $request->user());

        return response()->json(['link' => $this->linkToArray($link, $attack, $defence)]);
    }

    public function destroyAttackDefence(DestroyAttackDefenceLinkRequest $request, Assessment $assessment, LinkService $links): JsonResponse
    {
        $validated = $request->validated();

        /** @var Attack $attack */
        $attack = Attack::query()->where('uuid', $validated['attack_uuid'])->firstOrFail();
        /** @var Defence $defence */
        $defence = Defence::query()->where('uuid', $validated['defence_uuid'])->firstOrFail();

        $links->unlinkAttackFromDefence($attack, $defence);

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function linkToArray(AttackDefence $link, Attack $attack, Defence $defence): array
    {
        return [
            'attack_uuid' => $attack->uuid,
            'defence_uuid' => $defence->uuid,
            'position' => $link->position,
            'strength_mode' => $link->strength_mode,
            'strength_fraction' => $link->strength_fraction,
            'strength_level_id' => $link->strength_level_id,
            'reduction_target' => $link->reduction_target,
            'rationale' => $link->rationale,
        ];
    }
}
