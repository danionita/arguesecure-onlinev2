/**
 * `Snapshots/Diff.vue` — the comparison of two saved versions.
 *
 * Everything shown was computed server-side from frozen columns; the page's
 * own logic is how it presents "nothing changed" versus a change, which way a
 * delta is signed, and how a null or boolean field value is written.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SnapshotDiffData } from '../../../resources/js/Components/Snapshots/types';
import SnapshotDiff from '../../../resources/js/Pages/Snapshots/Diff.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

const route = (name: string, param?: string) => `/${name}/${param ?? ''}`;

function totals(
    over: Partial<SnapshotDiffData['totals']> = {},
): SnapshotDiffData['totals'] {
    const same = { from: 100, to: 100, delta: 0 };
    return { inherent: same, current: same, target: same, ...over };
}

function diff(over: Partial<SnapshotDiffData> = {}): SnapshotDiffData {
    return {
        from: { id: 1, name: 'Workshop one', createdAt: null },
        to: { id: 2, name: 'Workshop two', createdAt: null },
        nodes: { added: [], removed: [], changed: [] },
        riskAttackLinks: { added: [], removed: [], changed: [] },
        attackDefenceLinks: { added: [], removed: [], changed: [] },
        totals: totals(),
        ...over,
    };
}

function render(d: SnapshotDiffData) {
    return mount(SnapshotDiff, {
        props: { assessment: { uuid: 'a1', currency: 'EUR' }, diff: d },
        global: {
            config: { globalProperties: { route } },
            stubs: {
                Money: {
                    props: ['value', 'currency'],
                    template:
                        '<span data-testid="money">{{ value === null ? "n/a" : value }}</span>',
                },
            },
        },
    });
}

beforeEach(() => {
    (globalThis as unknown as { route: typeof route }).route = route;
});

describe('the header', () => {
    it('names both versions in order and links back to the history', () => {
        const wrapper = render(diff());

        expect(wrapper.get('h1').text()).toBe('Workshop one → Workshop two');
        expect(wrapper.get('a').attributes('href')).toBe(
            '/assessments.history.index/a1',
        );
    });
});

describe('headline figures', () => {
    it('shows inherent, current and target in that order, each from → to', () => {
        const wrapper = render(
            diff({
                totals: totals({
                    inherent: { from: 1000, to: 900, delta: -100 },
                    current: { from: 500, to: 600, delta: 100 },
                    target: { from: null, to: null, delta: null },
                }),
            }),
        );
        const text = wrapper.text();

        expect(text.indexOf('Inherent')).toBeLessThan(text.indexOf('Current'));
        expect(text.indexOf('Current')).toBeLessThan(text.indexOf('Target'));
        const money = wrapper
            .findAll('[data-testid="money"]')
            .map((m) => m.text());
        // inherent: from, to, delta; current: from, to, delta; target: from, to only
        expect(money).toEqual([
            '1000',
            '900',
            '-100',
            '500',
            '600',
            '100',
            'n/a',
            'n/a',
        ]);
    });

    it('prefixes an increase with + and leaves a decrease to carry its own minus', () => {
        const wrapper = render(
            diff({
                totals: totals({
                    inherent: { from: 1000, to: 900, delta: -100 },
                    current: { from: 500, to: 600, delta: 100 },
                }),
            }),
        );
        // Increases in risk use the attack colour, decreases the implemented one.
        const inherentDelta = wrapper
            .findAll('.text-status-implemented-text')
            .map((d) => d.text());
        const currentDelta = wrapper
            .findAll('.text-node-attack-text')
            .map((d) => d.text());
        expect(inherentDelta).toContain('-100');
        expect(currentDelta).toContain('+100');
    });

    it('renders no delta line when a delta is null', () => {
        const wrapper = render(
            diff({
                totals: totals({
                    inherent: { from: null, to: 5, delta: null },
                    current: { from: null, to: 5, delta: null },
                    target: { from: null, to: 5, delta: null },
                }),
            }),
        );

        // Two values per baseline and nothing else.
        expect(wrapper.findAll('[data-testid="money"]')).toHaveLength(6);
    });
});

describe('nothing changed', () => {
    it('says so for nodes and for both link tables', () => {
        const text = render(diff()).text();

        expect(text).toContain('No structural changes.');
        expect(text.match(/No changes\./g)).toHaveLength(2);
    });
});

describe('node changes', () => {
    const changed = diff({
        nodes: {
            added: [{ uuid: 'n1', type: 'risk', title: 'Data breach' }],
            removed: [{ uuid: 'n2', type: 'attack', title: null }],
            changed: [
                {
                    uuid: 'n3',
                    type: 'defence',
                    title: 'MFA',
                    fields: [
                        { field: 'status', from: 'todo', to: 'implemented' },
                        { field: 'is_transfer', from: false, to: true },
                        { field: 'capex_amount', from: null, to: 500 },
                    ],
                },
            ],
        },
    });

    it('labels added, removed and changed nodes with their type', () => {
        const wrapper = render(changed);
        const rows = wrapper.findAll('ul.space-y-1\\.5 > li');

        expect(wrapper.text()).not.toContain('No structural changes.');
        expect(rows[0].text()).toContain('Added');
        expect(rows[0].text()).toContain('Data breach');
        expect(rows[0].text()).toContain('(risk)');
        expect(rows[1].text()).toContain('Removed');
        expect(rows[2].text()).toContain('Changed');
        expect(rows[2].text()).toContain('(defence)');
    });

    it('falls back to the uuid for an untitled node', () => {
        expect(render(changed).text()).toContain('n2');
    });

    it('writes field changes with — for null and true/false for booleans', () => {
        const text = render(changed).text().replace(/\s+/g, ' ');

        expect(text).toContain('status: todo → implemented');
        expect(text).toContain('is_transfer: false → true');
        expect(text).toContain('capex_amount: — → 500');
    });
});

describe('link changes', () => {
    const edges = diff({
        riskAttackLinks: {
            added: [{ risk_uuid: 'r1', attack_uuid: 'a1' }],
            removed: [{ risk_uuid: 'r2', attack_uuid: 'a2' }],
            changed: [],
        },
        attackDefenceLinks: {
            added: [{ attack_uuid: 'a3', defence_uuid: 'd3' }],
            removed: [{ attack_uuid: 'a4', defence_uuid: 'd4' }],
            changed: [
                {
                    edge: { attack_uuid: 'a5', defence_uuid: 'd5' },
                    fields: [
                        { field: 'strength_fraction', from: 0.2, to: 0.8 },
                    ],
                },
            ],
        },
    });

    it('lists added and removed risk to attack links', () => {
        const text = render(edges).text().replace(/\s+/g, ' ');

        expect(text).toContain('Added r1 → a1');
        expect(text).toContain('Removed r2 → a2');
    });

    it('lists added, removed and strength-changed attack to defence links', () => {
        const text = render(edges).text().replace(/\s+/g, ' ');

        expect(text).toContain('Added a3 → d3');
        expect(text).toContain('Removed a4 → d4');
        expect(text).toContain('Changed a5 → d5');
        expect(text).toContain('strength_fraction: 0.2 → 0.8');
    });

    it('does not say "No changes." for a table that has any', () => {
        expect(render(edges).text()).not.toContain('No changes.');
    });
});
