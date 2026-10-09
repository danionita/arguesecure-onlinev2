/**
 * The list centres in its viewport (W84, item 11).
 *
 * ## Why this reads the source file
 *
 * Same instrument, same reason as `tests/js/tree/listViewport.spec.ts` (W70)
 * and `tests/js/list/TreeNode.spec.ts` (W84): centring is a painted position
 * jsdom does not compute, so this pins the *class* Show.vue's `<ul
 * role="tree">` carries rather than a measured position. The actual
 * two-viewport-width claim ("centred when narrower than the page, and the
 * cap never lets it be wider") is asserted in a real browser in
 * tests/e2e/16-toolbar-one-row.spec.ts, which this unit test cannot replace
 * — jsdom has no layout engine to tell a centred element from a left-aligned
 * one. What this guards is that the mechanism (the `mx-auto` class) is still
 * there at all, which a refactor could otherwise drop with nothing in
 * tests/js catching it.
 *
 * `Pages/Assessment/Show.vue` is not this package's file (it is W82's,
 * merged) — this is the one line changed there for item 11, made under the
 * "first-mover" rule documented in the W84 dispatch. The test lives here,
 * in this package's own `tests/js/list/`, rather than being added to
 * `tests/js/tree/listViewport.spec.ts` (W70's), so as not to touch a file
 * this package does not own either.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const SHOW_VUE = resolve(
    __dirname,
    '../../../resources/js/Pages/Assessment/Show.vue',
);

const source = readFileSync(SHOW_VUE, 'utf8');

/** The `<ul role="tree">` element and its attributes, whitespace and all. */
const listElement = /<ul\b[^>]*\brole="tree"[^>]*>/s.exec(source)?.[0] ?? '';

describe('the list centres in its viewport (W84, item 11)', () => {
    it('has a tree list to check at all', () => {
        expect(listElement).not.toBe('');
    });

    it('carries mx-auto', () => {
        expect(listElement).toMatch(/\bmx-auto\b/);
    });

    it('still carries its reading-measure cap (W70) alongside the centring', () => {
        // Not a duplicate of tests/js/tree/listViewport.spec.ts's own
        // max-w-4xl assertion: that test guards the cap does not widen; this
        // one guards that centring it did not silently remove the cap the
        // same edit sits next to.
        expect(listElement).toMatch(/\bmax-w-4xl\b/);
    });
});
