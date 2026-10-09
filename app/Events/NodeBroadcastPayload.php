<?php

namespace App\Events;

use App\Models\Node;

/**
 * The wire shape every W16 node broadcast reuses — deliberately identical
 * to AssessmentController::nodeToArray() / NodeController::nodeToArray()
 * (both outside this package's ownership, so this cannot simply call one of
 * them) so resources/js/stores/assessmentStore.ts's `NodeRecord` shape is
 * the single thing every one of the app's three "here is a node" payloads
 * — the initial page load, the PATCH response, and this broadcast — has to
 * agree with.
 */
final class NodeBroadcastPayload
{
    /** @return array<string, mixed> */
    public static function toArray(Node $node): array
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
