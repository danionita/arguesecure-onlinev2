/**
 * The list centres in its viewport.
 *
 * ## Why this reads the source file
 *
 * Same instrument, same reason as `tests/js/tree/listViewport.spec.ts`
 * and `tests/js/list/TreeNode.spec.ts`: centring is a painted position
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
 * The test lives here, in `tests/js/list/`, rather than in
 * `tests/js/tree/listViewport.spec.ts`, which covers the reading-measure cap.
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

describe('the list centres in its viewport', () => {
    it('has a tree list to check at all', () => {
        expect(listElement).not.toBe('');
    });

    it('carries mx-auto', () => {
        expect(listElement).toMatch(/\bmx-auto\b/);
    });

    it('still carries its reading-measure cap alongside the centring', () => {
        // Not a duplicate of tests/js/tree/listViewport.spec.ts's own
        // max-w-4xl assertion: that test guards the cap does not widen; this
        // one guards that centring it did not silently remove the cap the
        // same edit sits next to.
        expect(listElement).toMatch(/\bmax-w-4xl\b/);
    });
});
