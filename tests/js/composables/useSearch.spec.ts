import { describe, expect, it } from 'vitest';
import { useSearch } from '../../../resources/js/composables/useSearch';
import { makeFixtureStore } from './support/fixture';

describe('useSearch', () => {
    it('matches on title', () => {
        const search = useSearch(makeFixtureStore());
        search.query.value = 'phishing';
        expect(search.matchingUuids.value).toEqual(new Set(['sharedAttack']));
    });

    it('matches on description', () => {
        const search = useSearch(makeFixtureStore());
        search.query.value = 'credential theft';
        expect(search.matchingUuids.value.has('sharedAttack')).toBe(true);
    });

    it('is case-insensitive and trims whitespace', () => {
        const search = useSearch(makeFixtureStore());
        search.query.value = '  PHISHING  ';
        expect(search.matchingUuids.value.has('sharedAttack')).toBe(true);
    });

    it('produces one match per rendered occurrence, not per node', () => {
        const search = useSearch(makeFixtureStore());
        search.query.value = 'phishing'; // sharedAttack, which renders twice
        expect(search.matches.value).toHaveLength(2);
    });

    it('an empty query matches nothing', () => {
        const search = useSearch(makeFixtureStore());
        search.query.value = '';
        expect(search.matches.value).toEqual([]);
        expect(search.activeMatch.value).toBeNull();
    });

    it('next()/previous() step through matches and wrap around', () => {
        const search = useSearch(makeFixtureStore());
        search.query.value = 'phishing';
        expect(search.matches.value).toHaveLength(2);

        expect(search.activeIndex.value).toBe(0);
        search.next();
        expect(search.activeIndex.value).toBe(1);
        search.next();
        expect(search.activeIndex.value).toBe(0); // wraps

        search.previous();
        expect(search.activeIndex.value).toBe(1); // wraps the other way
    });

    it('resets the active index when the query changes', () => {
        const search = useSearch(makeFixtureStore());
        search.query.value = 'phishing';
        search.next();
        expect(search.activeIndex.value).toBe(1);

        search.query.value = 'sql';
        expect(search.activeIndex.value).toBe(0);
    });

    it('clear() empties the query and resets position', () => {
        const search = useSearch(makeFixtureStore());
        search.query.value = 'phishing';
        search.next();
        search.clear();
        expect(search.query.value).toBe('');
        expect(search.activeIndex.value).toBe(0);
    });

    it('exposes an inputEl ref and a focus() function for W14 to bind "/" to', () => {
        const search = useSearch(makeFixtureStore());
        const input = document.createElement('input');
        document.body.appendChild(input);
        search.inputEl.value = input;
        search.focus();
        expect(document.activeElement).toBe(input);
        document.body.removeChild(input);
    });
});
