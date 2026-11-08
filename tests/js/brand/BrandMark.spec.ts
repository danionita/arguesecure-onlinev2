/**
 * The brand mark and the palette behind it.
 *
 * The contrast table reads resources/css/app.css itself, so a token edited
 * to a failing value fails here rather than in someone's eyes. Text pairs
 * need 4.5:1 (WCAG 2.x AA), borders and the logo 3:1.
 */
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import BrandMark from '../../../resources/js/Components/UI/BrandMark.vue';

const css = readFileSync('resources/css/app.css', 'utf8');

/** The `--as-*` declarations of one block, found by its opening line. */
function tokens(opening: string): Record<string, string> {
    const start = css.indexOf(opening);
    expect(start, `${opening} not found`).toBeGreaterThan(-1);
    const end = css.indexOf('\n}', start);
    const out: Record<string, string> = {};
    for (const m of css
        .slice(start, end)
        .matchAll(/--as-([\w-]+): (#[0-9a-f]{6});/g)) {
        out[m[1]] = m[2];
    }
    return out;
}

function luminance(hex: string): number {
    const c = [1, 3, 5].map((i) => {
        const v = parseInt(hex.slice(i, i + 2), 16) / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}

function ratio(a: string, b: string): number {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

const schemes = {
    light: tokens(':root {\n    --as-surface-0'),
    'dark (explicit)': tokens('\n.dark {'),
    'dark (system)': tokens('    :root:not(.light):not(.dark) {'),
};

describe.each(Object.entries(schemes))('palette, %s', (_name, t) => {
    const text: Array<[string, string]> = [
        ['text-primary', 'surface-0'],
        ['text-primary', 'surface-1'],
        ['text-secondary', 'surface-0'],
        ['text-secondary', 'surface-2'],
        ['text-muted', 'surface-0'],
        ['text-muted', 'surface-1'],
        ['text-muted', 'surface-2'],
        ...['risk', 'attack', 'defence', 'transfer'].map(
            (n): [string, string] => [`node-${n}-text`, `node-${n}-bg`],
        ),
        ...['implemented', 'todo'].map((n): [string, string] => [
            `status-${n}-text`,
            `status-${n}-bg`,
        ]),
        ['text-link', 'surface-0'],
        ['text-link', 'surface-1'],
        ['text-link', 'surface-2'],
        ['node-root-text', 'node-root-bg'],
        ['missing-text', 'missing-bg'],
        ['status-shelved-text', 'status-shelved-bg'],
        ...[1, 2, 3, 4, 5].map((n): [string, string] => [
            `magnitude-${n}-text`,
            `magnitude-${n}-bg`,
        ]),
    ];

    it.each(text)('%s on %s reaches 4.5:1', (fg, bg) => {
        expect(ratio(t[fg], t[bg])).toBeGreaterThanOrEqual(4.5);
    });

    const graphic: Array<[string, string]> = [
        ['focus-ring', 'surface-0'],
        ['focus-ring', 'surface-1'],
        ['border-strong', 'surface-1'],
        ['brand-outline', 'surface-0'],
        ['brand-accent', 'surface-0'],
        ['brand-outline', 'surface-1'],
        ['brand-accent', 'surface-1'],
    ];

    it.each(graphic)('%s against %s reaches 3:1', (fg, bg) => {
        expect(ratio(t[fg], t[bg])).toBeGreaterThanOrEqual(3);
    });

    it('keeps the root node apart from the shelved and missing neutrals', () => {
        for (const other of ['status-shelved-bg', 'missing-bg', 'surface-2']) {
            expect(t['node-root-bg']).not.toBe(t[other]);
        }
        expect(
            ratio(t['node-root-border'], t['node-root-bg']),
        ).toBeGreaterThanOrEqual(3);
        expect(t['node-root-border']).not.toBe(t['status-shelved-border']);
    });

    it('uses the website accent for links', () => {
        // Pinned values, so a drift from the website's palette is caught.
        const expected = _name === 'light' ? '#0f6e6a' : '#8fd3cc';
        expect(t['text-link']).toBe(expected);
        expect(t['brand-accent']).toBe(expected);
    });
});

describe('BrandMark', () => {
    it('draws the shield and the three-node argument, hidden from screen readers', () => {
        const svg = mount(BrandMark).get('svg');

        expect(svg.attributes('aria-hidden')).toBe('true');
        expect(svg.attributes('viewBox')).toBe('0 0 44 44');
        expect(svg.findAll('path')).toHaveLength(2);
        expect(svg.findAll('circle')).toHaveLength(3);
    });

    it('takes its colours from the theme tokens, not fixed values', () => {
        const html = mount(BrandMark).html();

        expect(html).toContain('var(--as-brand-outline)');
        expect(html).toContain('var(--as-brand-accent)');
        expect(html).not.toMatch(/#[0-9a-f]{6}/i);
    });

    it('scales both dimensions together', () => {
        const svg = mount(BrandMark, { props: { size: 56 } }).get('svg');

        expect(svg.attributes('width')).toBe('56');
        expect(svg.attributes('height')).toBe('56');
    });
});
