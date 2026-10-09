/**
 * Link.md — Attack<->Defence and Risk<->Attack edge mutations for the node
 * modal's Links tab (ui.md §7). Talks to LinkController directly rather
 * than through a dedicated store method: the store (resources/js/stores/
 * assessmentStore.ts, outside this package's ownership) exposes no
 * addLink/removeLink/updateLink API — only addChild(), which creates a
 * *new* node and its edge together, not "link to an existing node". Per
 * the W13 brief ("work through the store's existing public surface and
 * report any gap"), this is exactly that gap, reported in the W13 summary.
 *
 * What the store *does* already expose publicly is enough to build this
 * without editing it: `riskAttackLinks`/`attackDefenceLinks` are plain
 * reactive arrays returned as-is (createAssessmentStore's own internal
 * addChild()/deleteNode() mutate them the same direct way, via
 * push/splice), and `recomputeLocally()` is a public method. So every
 * function below (a) calls the server, then (b) mutates those same shared
 * reactive arrays and calls `store.recomputeLocally()` — the identical
 * effect a dedicated store method would have, using only what is already
 * public.
 */
import type { AssessmentStore } from '@/stores/assessmentStore';

export function useLinkMutations(store: AssessmentStore) {
    const assessmentUuid = () => store.assessment.uuid;

    async function linkRiskToAttack(
        riskUuid: string,
        attackUuid: string,
    ): Promise<void> {
        const response = await window.axios.post<{
            link: { risk_uuid: string; attack_uuid: string; position: number };
        }>(route('assessments.risk-attack-links.store', assessmentUuid()), {
            risk_uuid: riskUuid,
            attack_uuid: attackUuid,
        });
        store.riskAttackLinks.push(response.data.link);
        store.recomputeLocally();
    }

    async function unlinkRiskFromAttack(
        riskUuid: string,
        attackUuid: string,
    ): Promise<void> {
        await window.axios.delete(
            route('assessments.risk-attack-links.destroy', assessmentUuid()),
            { data: { risk_uuid: riskUuid, attack_uuid: attackUuid } },
        );
        const index = store.riskAttackLinks.findIndex(
            (l) => l.risk_uuid === riskUuid && l.attack_uuid === attackUuid,
        );
        if (index !== -1) store.riskAttackLinks.splice(index, 1);
        store.recomputeLocally();
    }

    async function linkAttackToDefence(
        attackUuid: string,
        defenceUuid: string,
    ): Promise<void> {
        const response = await window.axios.post<{
            link: {
                attack_uuid: string;
                defence_uuid: string;
                position: number;
                strength_mode: 'quantitative' | 'qualitative' | null;
                strength_fraction: number | null;
                strength_level_id: number | null;
                reduction_target: 'likelihood' | 'impact' | null;
                rationale: string | null;
            };
        }>(route('assessments.attack-defence-links.store', assessmentUuid()), {
            attack_uuid: attackUuid,
            defence_uuid: defenceUuid,
        });
        store.attackDefenceLinks.push(response.data.link);
        store.recomputeLocally();
    }

    async function unlinkAttackFromDefence(
        attackUuid: string,
        defenceUuid: string,
    ): Promise<void> {
        await window.axios.delete(
            route('assessments.attack-defence-links.destroy', assessmentUuid()),
            { data: { attack_uuid: attackUuid, defence_uuid: defenceUuid } },
        );
        const index = store.attackDefenceLinks.findIndex(
            (l) =>
                l.attack_uuid === attackUuid && l.defence_uuid === defenceUuid,
        );
        if (index !== -1) store.attackDefenceLinks.splice(index, 1);
        store.recomputeLocally();
    }

    /**
     * Strength lives on the edge (schema.md §3.6) — this is the one place
     * it is ever written from the UI, reached from the Links tab.
     */
    async function updateAttackDefenceLink(
        attackUuid: string,
        defenceUuid: string,
        patch: {
            strength_mode?: 'quantitative' | 'qualitative' | null;
            strength_fraction?: number | null;
            strength_level_id?: number | null;
            reduction_target?: 'likelihood' | 'impact' | null;
            rationale?: string | null;
        },
    ): Promise<void> {
        const response = await window.axios.patch<{
            link: {
                attack_uuid: string;
                defence_uuid: string;
                position: number;
                strength_mode: 'quantitative' | 'qualitative' | null;
                strength_fraction: number | null;
                strength_level_id: number | null;
                reduction_target: 'likelihood' | 'impact' | null;
                rationale: string | null;
            };
        }>(route('assessments.attack-defence-links.update', assessmentUuid()), {
            attack_uuid: attackUuid,
            defence_uuid: defenceUuid,
            ...patch,
        });

        const existing = store.attackDefenceLinks.find(
            (l) =>
                l.attack_uuid === attackUuid && l.defence_uuid === defenceUuid,
        );
        if (existing) Object.assign(existing, response.data.link);
        store.recomputeLocally();
    }

    return {
        linkRiskToAttack,
        unlinkRiskFromAttack,
        linkAttackToDefence,
        unlinkAttackFromDefence,
        updateAttackDefenceLink,
    };
}
