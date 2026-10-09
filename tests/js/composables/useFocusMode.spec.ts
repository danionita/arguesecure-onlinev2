import { describe, expect, it } from 'vitest';
import { enumerateOccurrences } from '../../../resources/js/composables/occurrences';
import { useFocusMode } from '../../../resources/js/composables/useFocusMode';
import { makeFixtureStore } from './support/fixture';

describe('useFocusMode', () => {
    it('is inactive with nothing focused, and everything is visible', () => {
        const store = makeFixtureStore();
        const focus = useFocusMode(store);
        expect(focus.isActive.value).toBe(false);
        expect(focus.isVisible('root/riskA')).toBe(true);
        expect(focus.isVisible('anything')).toBe(true);
    });

    it('isolates one subtree: the focused occurrence and its descendants stay visible, everything else does not', () => {
        const store = makeFixtureStore();
        const focus = useFocusMode(store);
        focus.enter('root/riskA');

        expect(focus.isActive.value).toBe(true);
        expect(focus.isVisible('root/riskA')).toBe(true); // itself
        expect(focus.isVisible('root/riskA/sharedAttack')).toBe(true); // descendant
        expect(focus.isVisible('root/riskA/sharedAttack/defence1')).toBe(true);
        expect(focus.isVisible('root/riskB')).toBe(false); // sibling
        expect(focus.isVisible('root/riskB/sharedAttack')).toBe(false); // sharedAttack's OTHER occurrence
        expect(focus.isVisible('root')).toBe(false); // ancestor
    });

    it('focusing one occurrence of a shared node does not reveal its other, unrelated occurrences', () => {
        const store = makeFixtureStore();
        const focus = useFocusMode(store);
        // Focus sharedAttack specifically as it renders under riskA.
        focus.enter('root/riskA/sharedAttack');

        expect(focus.isVisible('root/riskA/sharedAttack/defence1')).toBe(true);
        // sharedAttack's occurrence under riskB is a DIFFERENT occurrence
        // and must not be pulled into view by focusing the riskA one.
        expect(focus.isVisible('root/riskB/sharedAttack')).toBe(false);
    });

    it('builds a root-first breadcrumb back to the focused occurrence', () => {
        const store = makeFixtureStore();
        const focus = useFocusMode(store);
        focus.enter('root/riskA/sharedAttack/defence1');

        expect(focus.breadcrumb.value.map((b) => b.uuid)).toEqual([
            'root',
            'riskA',
            'sharedAttack',
            'defence1',
        ]);
        expect(focus.breadcrumb.value.map((b) => b.title)).toEqual([
            'Assessment',
            'Risk A',
            'Phishing',
            'MFA',
        ]);
    });

    it('exit() restores full visibility — Escape (W14) calls this', () => {
        const store = makeFixtureStore();
        const focus = useFocusMode(store);
        focus.enter('root/riskA');
        focus.exit();

        expect(focus.isActive.value).toBe(false);
        expect(focus.isVisible('root/riskB')).toBe(true);
        expect(focus.breadcrumb.value).toEqual([]);
    });

    it('entering an unknown path key is inert rather than throwing', () => {
        const store = makeFixtureStore();
        const focus = useFocusMode(store);
        focus.enter('does/not/exist');
        expect(focus.focusedOccurrence.value).toBeNull();
        // Falls back to "everything visible" rather than hiding the whole tree.
        expect(focus.isVisible('root/riskA')).toBe(true);
    });

    it('matches the real occurrence set from enumerateOccurrences for a sanity check', () => {
        const store = makeFixtureStore();
        const focus = useFocusMode(store);
        const attack2Occurrence = enumerateOccurrences(store).find(
            (o) => o.uuid === 'attack2',
        )!;
        focus.enter(attack2Occurrence.pathKey);
        expect(focus.isVisible('root/riskA/attack2/defence2')).toBe(true);
    });
});
