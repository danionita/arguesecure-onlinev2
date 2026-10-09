import { describe, expect, it } from 'vitest';
import { enumerateOccurrences } from '../../../resources/js/composables/occurrences';
import { useCollapseState } from '../../../resources/js/composables/useCollapseState';
import { makeFixtureStore, makeMemoryStorage } from './support/fixture';

describe('useCollapseState', () => {
    it('collapseAll() collapses every occurrence with children', () => {
        const store = makeFixtureStore();
        const collapse = useCollapseState(store, {
            assessmentUuid: 'a1',
            storage: makeMemoryStorage(),
        });
        collapse.collapseAll();

        for (const occurrence of enumerateOccurrences(store)) {
            expect(store.isExpanded(occurrence.pathKey)).toBe(false);
        }
    });

    it('expandAll() re-expands everything', () => {
        const store = makeFixtureStore();
        const collapse = useCollapseState(store, {
            assessmentUuid: 'a1',
            storage: makeMemoryStorage(),
        });
        collapse.collapseAll();
        collapse.expandAll();

        for (const occurrence of enumerateOccurrences(store)) {
            expect(store.isExpanded(occurrence.pathKey)).toBe(true);
        }
    });

    it("toggle() flips one occurrence without affecting others, including a shared node's other occurrence", () => {
        const store = makeFixtureStore();
        const collapse = useCollapseState(store, {
            assessmentUuid: 'a1',
            storage: makeMemoryStorage(),
        });
        collapse.toggle('root/riskA/sharedAttack');

        expect(store.isExpanded('root/riskA/sharedAttack')).toBe(false);
        expect(store.isExpanded('root/riskB/sharedAttack')).toBe(true);
    });

    it('persists collapse state per assessment and restores it', () => {
        const storage = makeMemoryStorage();
        const store = makeFixtureStore();
        const collapse = useCollapseState(store, {
            assessmentUuid: 'a1',
            storage,
        });
        collapse.toggle('root/riskA');
        collapse.toggle('root/riskA/sharedAttack');

        // Simulate a reload: fresh store, fresh composable, same storage.
        const reloadedStore = makeFixtureStore();
        const reloadedCollapse = useCollapseState(reloadedStore, {
            assessmentUuid: 'a1',
            storage,
        });
        reloadedCollapse.restore();

        expect(reloadedStore.isExpanded('root/riskA')).toBe(false);
        expect(reloadedStore.isExpanded('root/riskA/sharedAttack')).toBe(false);
        expect(reloadedStore.isExpanded('root/riskB')).toBe(true);
    });

    it("keeps two assessments' persisted collapse state independent", () => {
        const storage = makeMemoryStorage();
        const storeA = makeFixtureStore();
        const collapseA = useCollapseState(storeA, {
            assessmentUuid: 'assessment-a',
            storage,
        });
        collapseA.collapseAll();

        const storeB = makeFixtureStore();
        const collapseB = useCollapseState(storeB, {
            assessmentUuid: 'assessment-b',
            storage,
        });
        collapseB.restore();

        // assessment-b has nothing persisted yet, so it stays expanded.
        expect(storeB.isExpanded('root/riskA')).toBe(true);
    });

    it("keeps two users' persisted collapse state independent on the same assessment", () => {
        const storage = makeMemoryStorage();
        const storeDana = makeFixtureStore();
        useCollapseState(storeDana, {
            assessmentUuid: 'a1',
            userId: 'dana',
            storage,
        }).collapseAll();

        const storeSam = makeFixtureStore();
        const samCollapse = useCollapseState(storeSam, {
            assessmentUuid: 'a1',
            userId: 'sam',
            storage,
        });
        samCollapse.restore();

        expect(storeSam.isExpanded('root/riskA')).toBe(true);
    });
});
