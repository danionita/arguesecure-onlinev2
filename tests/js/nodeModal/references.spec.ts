import { describe, expect, it } from 'vitest';
import {
    kindLabel,
    kindPlaceholder,
    kindsFor,
    moreKinds,
    needsIdentifierField,
    needsUrlField,
    PRIMARY_KIND,
    primaryKindFor,
    primaryKindMeta,
    REFERENCE_KINDS,
    referenceHref,
} from '../../../resources/js/Components/NodeModal/tabs/references';

/**
 * Pure metadata for the References tab (schema.md §3.12, ui.md §7). No
 * validation logic here — see the module's own docblock for why the
 * identifier pattern is never duplicated client-side.
 */
describe('PRIMARY_KIND / primaryKindMeta / moreKinds', () => {
    it('CVE is the primary kind, surfaced prominently', () => {
        expect(PRIMARY_KIND).toBe('cve');
        expect(primaryKindMeta().value).toBe('cve');
    });

    it('every other kind a risk or attack may carry sits behind "more"', () => {
        const more = moreKinds().map((k) => k.value);
        expect(more).not.toContain('cve');
        expect(more).toEqual(
            REFERENCE_KINDS.map((k) => k.value).filter(
                (v) => v !== 'cve' && v !== 'control',
            ),
        );
        expect(more.length).toBe(REFERENCE_KINDS.length - 2);
    });
});

/** PLAN-2.7 D64: a control is cited by a defence and nothing else. */
describe('kindsFor / primaryKindFor', () => {
    it('offers Control on a defence, first', () => {
        expect(primaryKindFor('defence')).toBe('control');
        expect(primaryKindMeta('defence').value).toBe('control');
        expect(kindsFor('defence').map((k) => k.value)).toContain('control');
        expect(moreKinds('defence').map((k) => k.value)).toEqual([
            'cve',
            'cwe',
            'capec',
            'attack_technique',
            'url',
            'other',
        ]);
    });

    it('never offers Control on a risk or an attack', () => {
        for (const type of ['risk', 'attack', 'root']) {
            expect(primaryKindFor(type)).toBe('cve');
            expect(kindsFor(type).map((k) => k.value)).not.toContain('control');
        }
    });

    it('a control identifier is typed, its link derived', () => {
        expect(needsIdentifierField('control')).toBe(true);
        expect(needsUrlField('control')).toBe(false);
    });
});

describe('kindLabel / kindPlaceholder', () => {
    it('resolves a known kind to its label and placeholder', () => {
        expect(kindLabel('cve')).toBe('CVE');
        expect(kindPlaceholder('cve')).toBe('CVE-2024-3094');
    });

    it('falls back to the raw kind for anything unrecognised', () => {
        expect(kindLabel('bogus')).toBe('bogus');
        expect(kindPlaceholder('bogus')).toBe('');
    });
});

describe('needsIdentifierField', () => {
    it('every kind except url takes an identifier', () => {
        expect(needsIdentifierField('cve')).toBe(true);
        expect(needsIdentifierField('cwe')).toBe(true);
        expect(needsIdentifierField('capec')).toBe(true);
        expect(needsIdentifierField('attack_technique')).toBe(true);
        expect(needsIdentifierField('other')).toBe(true);
        expect(needsIdentifierField('url')).toBe(false);
    });
});

describe('needsUrlField', () => {
    it('only url and other carry a user-supplied URL (security.md §7)', () => {
        expect(needsUrlField('url')).toBe(true);
        expect(needsUrlField('other')).toBe(true);
        expect(needsUrlField('cve')).toBe(false);
        expect(needsUrlField('cwe')).toBe(false);
        expect(needsUrlField('capec')).toBe(false);
        expect(needsUrlField('attack_technique')).toBe(false);
    });
});

describe('referenceHref', () => {
    it('returns the stored url as-is', () => {
        expect(referenceHref({ url: 'https://example.com' })).toBe(
            'https://example.com',
        );
        expect(referenceHref({ url: null })).toBeNull();
    });
});
