/**
 * The list view's reading measure (W70, item 8; ui.md §3.2).
 *
 * ## Why this reads the source file
 *
 * Every other test in this directory mounts a component and asserts on what a
 * reader sees, which is the right instrument for markup and copy. Item 8 is
 * neither: it is a single utility class on the `<ul role="tree">` in
 * `Pages/Assessment/Show.vue`, and the thing it changes — how far the eye has
 * to travel from a title to its chips — is a painted width that jsdom does not
 * paint. Mounting `Show.vue` would need an Inertia page, a layout, a router
 * and a `route()` shim, and would still measure nothing, because jsdom
 * computes no layout.
 *
 * So this asserts the *specified value*, in the one place it exists. A test
 * that merely checked "some max-width is present" would stay green if the cap
 * were widened back to `max-w-7xl`, which is the change this guards against —
 * W68's lesson, where a presence assertion survived the removal of the thing
 * it guarded.
 *
 * If a later package moves the cap, this test goes red and its author reads
 * this docblock, which is the point of pinning it here rather than trusting
 * the class to survive a refactor unnoticed.
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

describe('the list viewport is capped at a reading measure', () => {
    it('has a tree list to cap at all', () => {
        // Guards the regex above rather than the behaviour: if the element is
        // renamed or restructured, every assertion below would pass vacuously
        // against an empty string.
        expect(listElement).not.toBe('');
    });

    it('caps the list column at max-w-4xl', () => {
        expect(listElement).toMatch(/\bmax-w-4xl\b/);
    });

    it('caps it and does not also carry a wider cap', () => {
        // Two `max-w-*` classes on one element is a merge artefact, and which
        // one wins depends on stylesheet order rather than on intent.
        expect(listElement.match(/\bmax-w-[a-z0-9]+\b/g)).toEqual([
            'max-w-4xl',
        ]);
    });

    it('leaves the chart view uncapped', () => {
        // The chart is a canvas and wants the whole window (W34): the editor
        // stopped being `max-w-7xl` for exactly that reason, and item 8
        // narrows the list *only*. `ChartView` is rendered as a sibling of the
        // list, so a cap placed on the shared scroll container instead of on
        // the `<ul>` would letterbox the chart again.
        const chartTag = /<ChartView\b[^>]*>/s.exec(source)?.[0] ?? '';

        expect(chartTag).not.toBe('');
        expect(chartTag).not.toMatch(/\bmax-w-/);
    });
});
