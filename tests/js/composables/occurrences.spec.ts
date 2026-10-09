import { describe, expect, it } from 'vitest';
import {
    enumerateOccurrences,
    occurrencesOfUuid,
} from '../../../resources/js/composables/occurrences';
import { makeFixtureStore } from './support/fixture';

describe('enumerateOccurrences', () => {
    it('walks the DAG the same way TreeNode.vue renders it: root -> risks -> attacks -> defences', () => {
        const store = makeFixtureStore();
        const occurrences = enumerateOccurrences(store);

        // root, riskA, riskB, sharedAttack (x2), attack2, defence1 (x2), defence2 (x3)
        expect(occurrences).toHaveLength(1 + 2 + 3 + 5);
        expect(occurrences[0]).toMatchObject({ uuid: 'root', depth: 1 });
    });

    it('produces a distinct occurrence per DAG position, not per uuid', () => {
        const store = makeFixtureStore();
        const sharedAttackOccurrences = occurrencesOfUuid(
            store,
            'sharedAttack',
        );
        expect(sharedAttackOccurrences).toHaveLength(2);
        expect(
            sharedAttackOccurrences.map((o) => o.parentPathKey).sort(),
        ).toEqual(['root/riskA', 'root/riskB']);
    });

    it('reports MORE occurrences than distinct parent links when an ancestor is itself duplicated', () => {
        const store = makeFixtureStore();
        // defence2 has two distinct attack_defence links (sharedAttack, attack2)...
        expect(store.attacksForDefence('defence2')).toHaveLength(2);
        // ...but sharedAttack itself renders twice, so defence2 renders three times.
        expect(occurrencesOfUuid(store, 'defence2')).toHaveLength(3);
    });

    it('attaches the specific attack_defence edge for each defence occurrence', () => {
        const store = makeFixtureStore();
        const occurrences = occurrencesOfUuid(store, 'defence1');
        for (const occurrence of occurrences) {
            expect(occurrence.edge?.attack_uuid).toBe('sharedAttack');
            expect(occurrence.edge?.defence_uuid).toBe('defence1');
            expect(occurrence.edge?.strength_fraction).toBe(0.8);
        }
    });

    it('returns an empty list when there is no root', () => {
        const store = makeFixtureStore();
        delete store.nodes[store.rootUuid()!];
        expect(enumerateOccurrences(store)).toEqual([]);
    });
});
