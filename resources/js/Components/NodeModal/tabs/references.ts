/**
 * Pure metadata for the References tab (ui.md §7, schema.md §3.12): kind
 * labels/placeholders and the primary-vs-"more" grouping ("Surface CVE
 * prominently and the rest behind a 'more' affordance", schema.md §3.12's
 * last line). No validation logic lives here — the identifier pattern is
 * server-authoritative (App\Domain\References\ReferenceValidator,
 * security.md §7) and is never duplicated client-side, so a form only ever
 * *offers* the right shape of input; the server has the final word on
 * whether it is accepted. Follows the same "pure logic in a plain .ts
 * module next to the component" precedent as numeric.ts.
 */

export type ReferenceKind =
    'cve' | 'cwe' | 'capec' | 'attack_technique' | 'control' | 'url' | 'other';

export interface ReferenceKindMeta {
    value: ReferenceKind;
    label: string;
    placeholder: string;
}

/** Must stay in sync with config/arguesecure.php's `references.kinds` order. */
export const REFERENCE_KINDS: ReferenceKindMeta[] = [
    { value: 'cve', label: 'CVE', placeholder: 'CVE-2024-3094' },
    { value: 'cwe', label: 'CWE', placeholder: 'CWE-79' },
    { value: 'capec', label: 'CAPEC', placeholder: 'CAPEC-163' },
    {
        value: 'attack_technique',
        label: 'ATT&CK technique',
        placeholder: 'T1566 or T1566.001',
    },
    {
        value: 'control',
        label: 'Control',
        placeholder: 'ISO27001-2022:A.5.23 or NIS2:ART.21(2)(D)',
    },
    { value: 'url', label: 'URL', placeholder: 'https://example.com/advisory' },
    { value: 'other', label: 'Other', placeholder: 'Free-text identifier' },
];

/** The one kind surfaced prominently; every other kind sits behind "more". */
export const PRIMARY_KIND: ReferenceKind = 'cve';

/**
 * Only a defence may cite a framework control (PLAN-2.7 D64; the server
 * refuses it anywhere else), and on a defence it is what is cited most, so
 * it is the kind a defence's form opens on.
 */
export function kindsFor(nodeType: string): ReferenceKindMeta[] {
    return nodeType === 'defence'
        ? REFERENCE_KINDS
        : REFERENCE_KINDS.filter((k) => k.value !== 'control');
}

export function primaryKindFor(nodeType: string): ReferenceKind {
    return nodeType === 'defence' ? 'control' : PRIMARY_KIND;
}

export function primaryKindMeta(nodeType = ''): ReferenceKindMeta {
    return (
        REFERENCE_KINDS.find((k) => k.value === primaryKindFor(nodeType)) ??
        REFERENCE_KINDS[0]
    );
}

/** Every kind except the primary one — what the "more" disclosure reveals. */
export function moreKinds(nodeType = ''): ReferenceKindMeta[] {
    return kindsFor(nodeType).filter(
        (k) => k.value !== primaryKindFor(nodeType),
    );
}

export function kindLabel(kind: string): string {
    return REFERENCE_KINDS.find((k) => k.value === kind)?.label ?? kind;
}

export function kindPlaceholder(kind: string): string {
    return REFERENCE_KINDS.find((k) => k.value === kind)?.placeholder ?? '';
}

/**
 * `url` carries no identifier at all (schema.md §3.12: "required for every
 * kind except url") — the URL field *is* the input.
 */
export function needsIdentifierField(kind: string): boolean {
    return kind !== 'url';
}

/**
 * Only `url` and `other` carry a user-supplied URL (security.md §7); every
 * other kind's URL is derived server-side and this field must not render
 * for them at all — showing it would invite exactly the "smuggle a URL
 * past a derived kind" mistake the backend already guards against.
 */
export function needsUrlField(kind: string): boolean {
    return kind === 'url' || kind === 'other';
}

/** Best-effort display link for a reference row — always the stored `url`. */
export function referenceHref(reference: {
    url: string | null;
}): string | null {
    return reference.url;
}
