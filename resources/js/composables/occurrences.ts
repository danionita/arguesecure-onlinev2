/**
 * Shared DAG-walk used by every W15 composable (search, filters, focus
 * mode, duplicate highlighting).
 *
 * assessmentStore.ts (W12) is explicit that "the renderer therefore keys on
 * (node uuid, path), not on node uuid alone" (ui.md §3.1) and that the
 * store deliberately exposes only accessor functions — `risksOrder()`,
 * `attacksUnderRisk()`, `defencesUnderAttack()` — not the underlying edge
 * arrays walked as a tree. This module walks the DAG the exact same way
 * TreeNode.vue does (root -> risks -> attacks -> defences, using those same
 * accessors) so every occurrence W15 reasons about is guaranteed to be one
 * TreeNode.vue would actually render — no parallel structure that can
 * drift out of sync with what W12 owns.
 *
 * This is also why occurrence *counts* computed here can legitimately
 * exceed `store.occurrenceCount()`: that function counts distinct parent
 * *links* (schema.md's DAG edges), but a node can be rendered more times
 * than it has distinct parents when one of its ancestors is itself
 * duplicated. A defence linked to a single attack that itself sits under
 * two risks renders twice, not once — see useDuplicateHighlight.ts.
 */
import type {
    AssessmentStore,
    AttackDefenceLink,
} from '../stores/assessmentStore';
import { occurrencePath } from '../stores/assessmentStore';

export interface Occurrence {
    uuid: string;
    /** uuids from the root down to and including this occurrence. */
    path: string[];
    /** `occurrencePath(...path)` — the same key TreeNode.vue uses for `:key` and expand state. */
    pathKey: string;
    /** pathKey of the immediate parent occurrence, or null for the root. */
    parentPathKey: string | null;
    depth: number;
    /** Present only for a defence occurrence — the specific attack_defence edge that produced it (schema.md §3.6: strength lives on the pivot, not the node). */
    edge?: AttackDefenceLink;
}

/** Walks the whole DAG from the root, producing one Occurrence per rendered position — exactly what TreeNode.vue recurses over. */
export function enumerateOccurrences(store: AssessmentStore): Occurrence[] {
    const result: Occurrence[] = [];
    const root = store.rootUuid();
    if (!root) return result;

    function childrenOf(uuid: string): string[] {
        const node = store.nodes[uuid];
        if (!node) return [];
        switch (node.type) {
            case 'root':
                return store.risksOrder();
            case 'risk':
                return store.attacksUnderRisk(uuid);
            case 'attack':
                return store.defencesUnderAttack(uuid);
            default:
                return [];
        }
    }

    function edgeFor(
        parentUuid: string,
        childUuid: string,
    ): AttackDefenceLink | undefined {
        const parent = store.nodes[parentUuid];
        if (parent?.type !== 'attack') return undefined;
        return store.attackDefenceLinks.find(
            (l) => l.attack_uuid === parentUuid && l.defence_uuid === childUuid,
        );
    }

    function walk(
        uuid: string,
        ancestorPath: string[],
        edge?: AttackDefenceLink,
    ) {
        const path = [...ancestorPath, uuid];
        const pathKey = occurrencePath(...path);
        const parentPathKey = ancestorPath.length
            ? occurrencePath(...ancestorPath)
            : null;
        result.push({
            uuid,
            path,
            pathKey,
            parentPathKey,
            depth: path.length,
            edge,
        });

        for (const childUuid of childrenOf(uuid)) {
            walk(childUuid, path, edgeFor(uuid, childUuid));
        }
    }

    walk(root, []);
    return result;
}

/** All occurrences of one node uuid, wherever in the DAG it renders. */
export function occurrencesOfUuid(
    store: AssessmentStore,
    uuid: string,
): Occurrence[] {
    return enumerateOccurrences(store).filter((o) => o.uuid === uuid);
}
