/**
 * The one action bar every assessment view renders (W82, D40).
 *
 * jsdom has no layout engine, so nothing here is about pixels — that stays
 * in `tests/e2e/16-toolbar-one-row.spec.ts`. What is asserted here is the
 * thing that regresses silently: that all five actions are one component
 * rather than four arrangements that happen to agree, that a viewer sees
 * fewer of them than a manager does, and that the bar hides itself from
 * print by the *specific* rule `print.css` defines rather than by a class
 * merely being present somewhere (CLAUDE.md's W68 write-up on that trap).
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import AssessmentActions, {
    type AssessmentActionsAbilities,
} from '../../../resources/js/Components/Toolbar/AssessmentActions.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
    };
});

const route = (name: string, uuid: string) => `/${name}/${uuid}`;

beforeAll(() => {
    (globalThis as unknown as { route: typeof route }).route = route;
});

const UUID = '11111111-2222-3333-4444-555555555555';

function mountActions(
    over: {
        abilities?: Partial<AssessmentActionsAbilities>;
        viewMode?: 'chart' | 'list' | 'report';
    } = {},
) {
    return mount(AssessmentActions, {
        props: {
            assessment: { uuid: UUID },
            abilities: { manage: false, ...over.abilities },
            viewMode: over.viewMode,
        },
        global: {
            config: { globalProperties: { route } },
        },
    });
}

describe('a manager sees all five, in order', () => {
    it('renders Settings, History, Share, Export and Print in one component', () => {
        const wrapper = mountActions({ abilities: { manage: true } });
        const bar = wrapper.get('[data-testid="assessment-actions"]');

        expect(bar.text()).toContain('Settings');
        expect(bar.text()).toContain('History');
        expect(bar.text()).toContain('Share');
        expect(bar.text()).toContain('Export');
        expect(bar.text()).toContain('Print');

        // D40's order, literally: the five in the bar's own DOM order.
        const labels = ['Settings', 'History', 'Share', 'Export', 'Print'];
        const positions = labels.map((label) => bar.text().indexOf(label));
        expect(positions).toEqual([...positions].sort((a, b) => a - b));
    });

    it('points Settings and History at the assessment they belong to', () => {
        const wrapper = mountActions({ abilities: { manage: true } });

        expect(
            wrapper
                .findAll('a')
                .find((a) => a.text() === 'Settings')!
                .attributes('href'),
        ).toBe(`/assessments.settings.edit/${UUID}`);
        expect(
            wrapper
                .findAll('a')
                .find((a) => a.text() === 'History')!
                .attributes('href'),
        ).toBe(`/assessments.history.index/${UUID}`);
    });
});

describe('a viewer sees the subset their abilities allow', () => {
    /**
     * The mutation-tested guard: `abilities.manage` is what keeps Settings
     * and Share off a viewer's screen. Neutering it (see the note below the
     * test) turns this red, which is the whole point of writing it as an
     * absence assertion on the *specific* labels rather than a shrinking
     * count — CLAUDE.md's rule for exactly this kind of test.
     */
    it('hides Settings and Share, and keeps History, Export and Print', () => {
        const wrapper = mountActions({ abilities: { manage: false } });
        const text = wrapper.get('[data-testid="assessment-actions"]').text();

        expect(text).not.toContain('Settings');
        expect(text).not.toContain('Share');
        expect(text).toContain('History');
        expect(text).toContain('Export');
        expect(text).toContain('Print');
    });

    it('offers a manager both of the gated actions', () => {
        const wrapper = mountActions({ abilities: { manage: true } });
        const text = wrapper.get('[data-testid="assessment-actions"]').text();

        expect(text).toContain('Settings');
        expect(text).toContain('Share');
    });
});

describe('Share and Print, as they were (D40: "move as they are")', () => {
    it('emits share rather than navigating, so a page-level dialog can open', async () => {
        const wrapper = mountActions({ abilities: { manage: true } });

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Share')!
            .trigger('click');

        expect(wrapper.emitted('share')).toHaveLength(1);
    });

    it('prints the current view: emits print, then calls window.print', async () => {
        const printSpy = vi.fn();
        window.print = printSpy;

        const wrapper = mountActions({ viewMode: 'list' });
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Print')!
            .trigger('click');

        expect(wrapper.emitted('print')).toHaveLength(1);
        expect(printSpy).toHaveBeenCalledTimes(1);
    });

    it('names what Print prints, because the same click means three different things', () => {
        expect(
            mountActions({ viewMode: 'chart' })
                .findAll('button')
                .find((b) => b.text() === 'Print')!
                .attributes('aria-label'),
        ).toBe('Print chart');
        expect(
            mountActions({ viewMode: 'list' })
                .findAll('button')
                .find((b) => b.text() === 'Print')!
                .attributes('aria-label'),
        ).toBe('Print list');
        expect(
            mountActions({ viewMode: 'report' })
                .findAll('button')
                .find((b) => b.text() === 'Print')!
                .attributes('aria-label'),
        ).toBe('Print report');
    });
});

/**
 * D42, and the trap the brief names by name: asserting "the bar has
 * `no-print`" is a presence assertion, and presence assertions pass for the
 * wrong reason the moment `print.css`'s actual rule changes shape. This
 * checks both halves — the class this component's root carries, and that
 * `print.css` still defines what that class means — so weakening either one
 * on its own turns this red.
 *
 * Mutation-tested by hand: removing `no-print` from this component's root
 * `<div>` turns the first assertion red; commenting out `.no-print {
 * display: none !important; }` in `resources/css/print.css` turns the
 * second one red. Both were confirmed and both are restored — see the W82
 * report.
 */
describe('print (D42)', () => {
    it('carries the specific class print.css hides, not just any class', () => {
        const bar = mountActions().get('[data-testid="assessment-actions"]');
        expect(bar.classes()).toContain('no-print');
    });

    it('print.css actually hides .no-print, so the class above means something', async () => {
        const fs = await import('node:fs');
        const path = await import('node:path');
        const css = fs.readFileSync(
            path.resolve(__dirname, '../../../resources/css/print.css'),
            'utf-8',
        );

        expect(css).toMatch(
            /\.no-print\s*\{\s*display:\s*none\s*!important;?\s*\}/,
        );
    });
});
