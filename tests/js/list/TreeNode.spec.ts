/**
 * The list's row spacing and per-level indent (W84, item 16, D51).
 *
 * ## Why this reads the source file
 *
 * As `tests/js/tree/listViewport.spec.ts` (W70) already documents for the
 * neighbouring reading-measure claim: the thing being asserted here is a
 * painted gap between rows and a painted offset per tier, and jsdom computes
 * no layout at all — `getBoundingClientRect()` on every element in this tree
 * would report zeroes whether the spacing classes are present or not. So this
 * reads `Components/Tree/TreeNode.vue`'s source directly and asserts the
 * *exact* utility classes, the same instrument W70 used and for the same
 * reason: a test asserting "some margin is present" would stay green if the
 * margin were reverted to nothing, which is precisely CLAUDE.md's documented
 * case of a presence assertion surviving the removal of the thing it
 * guarded (`ReportOptions::$figures`, W80).
 *
 * `Components/Tree/TreeNode.vue` — not `Components/List/` — is where this
 * file lives; see that component's own docblock for why it was not moved
 * even though it is exclusively the list's renderer.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const TREE_NODE_VUE = resolve(
    __dirname,
    '../../../resources/js/Components/Tree/TreeNode.vue',
);

const source = readFileSync(TREE_NODE_VUE, 'utf8');

/** The `<li role="treeitem">` element and its attributes, whitespace and all. */
const treeitemElement =
    /<li\b[^>]*\brole="treeitem"[^>]*>/s.exec(source)?.[0] ?? '';

/** The `<ul role="group">` element — the indented child list for one tier. */
const groupElement = /<ul\b[^>]*\brole="group"[^>]*>/s.exec(source)?.[0] ?? '';

describe('the list row carries a specified vertical gap (D51)', () => {
    it('has a treeitem element to check at all', () => {
        // Guards the regex above rather than the behaviour: if the element is
        // renamed or restructured, every assertion below would pass
        // vacuously against an empty string.
        expect(treeitemElement).not.toBe('');
    });

    it('carries exactly mb-1.5, not merely "some" margin', () => {
        expect(treeitemElement).toMatch(/\bmb-1\.5\b/);
    });

    it('carries no other bottom-margin utility that would fight it', () => {
        // Two `mb-*` classes on one element is a merge artefact, and which
        // one wins depends on class order in the compiled stylesheet rather
        // than on intent — the same failure mode
        // tests/js/tree/listViewport.spec.ts guards for `max-w-*`.
        expect(treeitemElement.match(/\bmb-[a-z0-9.]+\b/g)).toEqual(['mb-1.5']);
    });
});

describe('the list indents one level with a specified offset (D51)', () => {
    it('has a group element to check at all', () => {
        expect(groupElement).not.toBe('');
    });

    it('carries exactly ml-4 and pl-4, grown from the pre-W84 ml-2.5/pl-2.5', () => {
        expect(groupElement).toMatch(/\bml-4\b/);
        expect(groupElement).toMatch(/\bpl-4\b/);
        // The old, smaller values must actually be gone — not merely joined
        // by the new ones, which would leave both classes in the compiled
        // stylesheet with the cascade deciding which wins.
        expect(groupElement).not.toMatch(/\bml-2\.5\b/);
        expect(groupElement).not.toMatch(/\bpl-2\.5\b/);
    });

    it('carries no other left-margin or left-padding utility that would fight the indent', () => {
        expect(groupElement.match(/\bml-[a-z0-9.]+\b/g)).toEqual(['ml-4']);
        expect(groupElement.match(/\bpl-[a-z0-9.]+\b/g)).toEqual(['pl-4']);
    });

    it('keeps the connecting rule, so the indent still reads as nesting and not just whitespace', () => {
        expect(groupElement).toMatch(/\bborder-l\b/);
        expect(groupElement).toMatch(/\bborder-border-subtle\b/);
    });
});

describe('the list hands its card the child count (W127)', () => {
    it('passes the number of children, which the card shows while collapsed', () => {
        expect(source).toMatch(/:child-count="childUuids\.length"/);
    });
});
