import { describe, expect, it } from 'vitest';
import { enumerateOccurrences } from '../../../resources/js/composables/occurrences';
import { useFilters } from '../../../resources/js/composables/useFilters';
import { makeFixtureStore } from './support/fixture';

function pathKeyFor(store: ReturnType<typeof makeFixtureStore>, uuid: string) {
    return enumerateOccurrences(store).find((o) => o.uuid === uuid)!.pathKey;
}

describe('useFilters', () => {
    it('is inactive and dims nothing when no filter is set', () => {
        const store = makeFixtureStore();
        const filters = useFilters(store);
        expect(filters.isActive.value).toBe(false);
        expect(filters.dimmedPathKeys.value.size).toBe(0);
    });

    it('filters by node type', () => {
        const store = makeFixtureStore();
        const filters = useFilters(store);
        filters.toggleType('risk');

        expect(filters.isDimmed(pathKeyFor(store, 'riskA'))).toBe(false);
        expect(filters.isDimmed(pathKeyFor(store, 'sharedAttack'))).toBe(true);
    });

    it('treats a transfer as a distinct type from a plain defence', () => {
        const store = makeFixtureStore();
        store.nodes.defence1.is_transfer = true;
        const filters = useFilters(store);
        filters.toggleType('transfer');

        expect(filters.isDimmed(pathKeyFor(store, 'defence1'))).toBe(false);
        expect(filters.isDimmed(pathKeyFor(store, 'defence2'))).toBe(true);
    });

    it('filters by defence status', () => {
        const store = makeFixtureStore();
        const filters = useFilters(store);
        filters.toggleStatus('implemented');

        expect(filters.isDimmed(pathKeyFor(store, 'defence1'))).toBe(false);
        // defence2 is 'todo'
        const defence2Keys = enumerateOccurrences(store)
            .filter((o) => o.uuid === 'defence2')
            .map((o) => o.pathKey);
        for (const key of defence2Keys) {
            expect(filters.isDimmed(key)).toBe(true);
        }
        // status filter only applies to defences; risks/attacks/root are dimmed too since they don't match
        expect(filters.isDimmed(pathKeyFor(store, 'riskA'))).toBe(true);
    });

    it('filters by "has missing values", per occurrence for defences', () => {
        const store = makeFixtureStore();
        const filters = useFilters(store);
        filters.setMissingOnly(true);

        // attack2 has no likelihood set -> missing
        expect(filters.isDimmed(pathKeyFor(store, 'attack2'))).toBe(false);
        // sharedAttack has likelihood set -> not missing
        expect(filters.isDimmed(pathKeyFor(store, 'sharedAttack'))).toBe(true);

        // defence2 is missing strength on the sharedAttack edge but NOT on
        // the attack2 edge — missingness must be evaluated per occurrence.
        const defence2Occurrences = enumerateOccurrences(store).filter(
            (o) => o.uuid === 'defence2',
        );
        const underSharedAttack = defence2Occurrences.filter(
            (o) => o.edge?.attack_uuid === 'sharedAttack',
        );
        const underAttack2 = defence2Occurrences.find(
            (o) => o.edge?.attack_uuid === 'attack2',
        )!;
        for (const occurrence of underSharedAttack) {
            expect(filters.isDimmed(occurrence.pathKey)).toBe(false);
        }
        expect(filters.isDimmed(underAttack2.pathKey)).toBe(true);
    });

    it('filters by tag via the injected tagsForNode accessor (the store carries no tag data yet)', () => {
        const store = makeFixtureStore();
        const filters = useFilters(store, {
            tagsForNode: (uuid) => (uuid === 'riskA' ? ['pii'] : []),
        });
        filters.toggleTag('pii');

        expect(filters.isDimmed(pathKeyFor(store, 'riskA'))).toBe(false);
        expect(filters.isDimmed(pathKeyFor(store, 'riskB'))).toBe(true);
    });

    it('defaults to no tags on any node when tagsForNode is not supplied', () => {
        const store = makeFixtureStore();
        const filters = useFilters(store);
        filters.toggleTag('anything');
        expect(filters.isDimmed(pathKeyFor(store, 'riskA'))).toBe(true);
    });

    it('combines filters with AND semantics', () => {
        const store = makeFixtureStore();
        const filters = useFilters(store);
        filters.toggleType('attack');
        filters.setMissingOnly(true);

        // attack2: type attack AND missing likelihood -> visible
        expect(filters.isDimmed(pathKeyFor(store, 'attack2'))).toBe(false);
        // sharedAttack: type attack but NOT missing -> dimmed
        expect(filters.isDimmed(pathKeyFor(store, 'sharedAttack'))).toBe(true);
    });

    it('clear() resets every filter', () => {
        const store = makeFixtureStore();
        const filters = useFilters(store);
        filters.toggleType('risk');
        filters.toggleStatus('todo');
        filters.setMissingOnly(true);
        filters.clear();

        expect(filters.isActive.value).toBe(false);
        expect(filters.state.types).toEqual([]);
        expect(filters.state.statuses).toEqual([]);
        expect(filters.state.missingOnly).toBe(false);
    });
});
