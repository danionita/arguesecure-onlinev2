import { describe, expect, it } from 'vitest';
import { useDuplicateHighlight } from '../../../resources/js/composables/useDuplicateHighlight';
import { makeFixtureStore } from './support/fixture';

describe('useDuplicateHighlight', () => {
    it('reports 1 for a node that appears exactly once', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        expect(dup.countFor('riskA')).toBe(1);
    });

    it('reports the correct count for a node duplicated via a shared parent', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        expect(dup.countFor('sharedAttack')).toBe(2);
    });

    it('reports every occurrence for a node appearing under several parents — the DAG case a tree cannot produce', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        // defence2 is linked to sharedAttack (which itself renders twice)
        // AND attack2 -> 3 total rendered occurrences, not the 2 distinct
        // parent links store.attacksForDefence() would report.
        expect(store.attacksForDefence('defence2')).toHaveLength(2);
        expect(dup.countFor('defence2')).toBe(3);
    });

    it('hover highlights every occurrence of the hovered node', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        dup.hover('sharedAttack');

        expect(dup.isDuplicate.value).toBe(true);
        expect(dup.highlightedOccurrences.value).toHaveLength(2);
        expect(dup.highlightedPathKeys.value).toEqual(
            new Set(['root/riskA/sharedAttack', 'root/riskB/sharedAttack']),
        );
    });

    it('a single-occurrence node is not flagged as a duplicate on hover', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        dup.hover('riskA');
        expect(dup.isDuplicate.value).toBe(false);
        expect(dup.highlightedOccurrences.value).toHaveLength(1);
    });

    it('unhover clears the highlight unless pinned', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        dup.hover('sharedAttack');
        dup.unhover('sharedAttack');
        expect(dup.activeUuid.value).toBeNull();
    });

    it('pin is the accessible equivalent of hover: it survives after unhover, for keyboard/screen-reader users', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        dup.pin('defence2');
        dup.hover('riskA');
        dup.unhover('riskA');

        // Pin still wins even though hover moved elsewhere and cleared.
        expect(dup.activeUuid.value).toBe('defence2');
        expect(dup.highlightedOccurrences.value).toHaveLength(3);
    });

    it('pin toggles off when the same node is pinned twice', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        dup.pin('defence2');
        dup.pin('defence2');
        expect(dup.pinnedUuid.value).toBeNull();
    });

    it('clear() resets both hover and pin', () => {
        const store = makeFixtureStore();
        const dup = useDuplicateHighlight(store);
        dup.hover('riskA');
        dup.pin('defence2');
        dup.clear();
        expect(dup.activeUuid.value).toBeNull();
    });
});
