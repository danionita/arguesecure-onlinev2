/**
 * The dashboard (W42 item 17, reshaped by W73 item 21 / D32).
 *
 * Moved here from `tests/js/pages` by W73, which owns `tests/js/dashboard/**`
 * and the page itself: D32 replaced four stacked sections with two sections
 * of two tabs, so three of the assertions below had to change shape, and the
 * spec belongs beside the components it now exercises.
 *
 * The empty case is still what most of this file tests, and that is not
 * padding. Every empty state survived D32 unchanged — they are as true inside
 * a tab panel as they were under a heading — and "reads as an invitation, not
 * a set of blank boxes" is still the requirement, so it is still the thing
 * asserted. What is new is that a panel can be *hidden*, which is a way for
 * an empty state to stop being read that stacked sections did not have.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '../../../resources/js/Pages/Dashboard.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: {}, url: '/dashboard' }),
}));

const route = (name: string, param?: string) =>
    param ? `/${name}/${param}` : `/${name}`;

beforeEach(() => {
    (globalThis as unknown as { route: typeof route }).route = route;
});

function render(over: Record<string, unknown> = {}) {
    return mount(Dashboard, {
        global: {
            config: { globalProperties: { route } },
            stubs: { AuthenticatedLayout: { template: '<div><slot /></div>' } },
        },
        props: {
            assignedToMe: [],
            mentions: [],
            myAssessments: [],
            sharedWithMe: [],
            ...over,
        },
    });
}

const assessment = {
    uuid: 'a1',
    title: 'Payment platform',
    description: 'Cardholder data',
    updated_at: null,
};

const mention = {
    assessment_uuid: 'a1',
    assessment_title: 'Payment platform',
    node_uuid: 'n1',
    node_title: 'Data breach',
    excerpt: 'can you check this?',
    author: 'Dana',
    resolved: false,
    created_at: '2026-01-01T00:00:00Z',
};

const assignment = {
    assessment_uuid: 'a1',
    assessment_title: 'Payment platform',
    node_uuid: 'n1',
    type: 'Risk',
    title: 'Data breach',
    missing: 'no impact entered',
};

/** Tab buttons in DOM order, across both tablists. */
const tabNames = (wrapper: ReturnType<typeof render>) =>
    wrapper.findAll('[role="tab"]').map((tab) => tab.text());

describe('two sections of tabs (D32)', () => {
    it('pairs the four lists into Notifications and Assessments', () => {
        const wrapper = render();

        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual([
            'Notifications',
            'Assessments',
        ]);
        // The pairing is the decision. Four stacked sections put the two that
        // answer "what needs me" half a screen from the two that answer
        // "where do I go", and neither pair fit above the fold.
        expect(tabNames(wrapper).map((t) => t.split(/\s+/)[0])).toEqual([
            'Assigned',
            'Mentions',
            'Mine',
            'Shared',
        ]);
    });

    it('still puts work before inventory', () => {
        // The one thing about W42's ordering that D32 keeps. A dashboard that
        // opens with a list of things you own has told you nothing you did
        // not know.
        const headings = render()
            .findAll('h2')
            .map((h) => h.text());

        expect(headings.indexOf('Notifications')).toBeLessThan(
            headings.indexOf('Assessments'),
        );
    });

    it('gives each section a labelled landmark, and each tablist the same name', () => {
        const wrapper = render();
        const sections = wrapper.findAll('section');

        expect(sections).toHaveLength(2);
        for (const section of sections) {
            const id = section.attributes('aria-labelledby')!;
            expect(wrapper.find(`[id="${id}"]`).exists()).toBe(true);
        }

        // The tablist repeats its section's heading, so its tabs are
        // announced as belonging to it rather than floating on the page.
        expect(
            wrapper
                .findAll('[role="tablist"]')
                .map((l) => l.attributes('aria-label')),
        ).toEqual(['Notifications', 'Assessments']);
    });

    it('opens on the first tab of each section', () => {
        const selected = render()
            .findAll('[role="tab"]')
            .filter((tab) => tab.attributes('aria-selected') === 'true')
            .map((tab) => tab.text());

        expect(selected).toHaveLength(2);
        expect(selected[0]).toContain('Assigned to me');
        expect(selected[1]).toContain('Mine');
    });
});

describe('counts on the tab labels', () => {
    it('puts the count where it can be seen without clicking', () => {
        // D32: a tab whose count is hidden until you click it is a
        // notification you will not see. The number is plain text inside the
        // button, so it is part of the tab's accessible name rather than a
        // pill a screen reader walks past.
        const wrapper = render({
            assignedToMe: [assignment, { ...assignment, node_uuid: 'n2' }],
        });

        const tab = wrapper
            .findAll('[role="tab"]')
            .find((t) => t.text().startsWith('Assigned to me'))!;

        expect(tab.text()).toContain('2');
        expect(tab.text()).toContain('assigned to you');
    });

    it('counts only unresolved mentions, because a closed thread is not waiting', () => {
        const wrapper = render({
            mentions: [
                mention,
                { ...mention, node_uuid: 'n2', resolved: true },
                { ...mention, node_uuid: 'n3', resolved: true },
            ],
        });

        const tab = wrapper
            .findAll('[role="tab"]')
            .find((t) => t.text().startsWith('Mentions'))!;

        // Three rows, one of them still wanting an answer.
        expect(tab.text()).toContain('1');
        expect(tab.text()).toContain('unresolved');
    });

    it('draws no count at all when there is nothing to count', () => {
        const tab = render()
            .findAll('[role="tab"]')
            .find((t) => t.text().startsWith('Mentions'))!;

        // A zero badge is noise on a page whose job is to be read at a
        // glance, and "0 unresolved" is a sentence nobody needs read to them.
        expect(tab.text()).toBe('Mentions');
    });
});

describe('empty states', () => {
    it('explains what will appear, in every one of the four panels', () => {
        // Rendered whether or not their panel is the visible one — `v-show`,
        // not `v-if` — so this is the whole set, and none of the copy
        // mentions a work package.
        const text = render().text();

        expect(text).toContain('When someone assigns');
        expect(text).toContain('Comments that mention you');
        expect(text).toContain('Create one to start mapping');
        expect(text).toContain('Assessments other people invite you to');
    });

    it('offers no "See all" link for a panel with nothing in it', () => {
        expect(render().text()).not.toContain('See all');
    });
});

describe('with data', () => {
    it('names the owner on a shared row, and not on an owned one', () => {
        const wrapper = render({
            myAssessments: [assessment],
            sharedWithMe: [{ ...assessment, uuid: 'a2', owner_name: 'Dana' }],
        });

        expect(wrapper.text()).toContain('Shared by Dana');
        expect(wrapper.text().match(/Shared by/g)).toHaveLength(1);
    });

    it('sends "See all" on the shared panel to the shared page, not the list (D33)', () => {
        // `/assessments` mixes owned and shared rows and does not
        // distinguish them, which made this link a promise the destination
        // could not keep.
        const wrapper = render({
            sharedWithMe: [{ ...assessment, owner_name: 'Dana' }],
        });

        const seeAll = wrapper
            .findAll('a')
            .filter((a) => a.text() === 'See all')
            .map((a) => a.attributes('href'));

        expect(seeAll).toEqual(['/assessments.shared']);
    });

    it('sends "See all" on the owned panel to the full list', () => {
        const wrapper = render({ myAssessments: [assessment] });

        expect(
            wrapper
                .findAll('a')
                .find((a) => a.text() === 'See all')!
                .attributes('href'),
        ).toBe('/assessments.index');
    });

    it('deep-links an assignment straight to the node it is about', () => {
        const wrapper = render({ assignedToMe: [assignment] });

        // The point of showing an assignment here is getting to it in one
        // click, not being told it exists.
        const link = wrapper.get('a[href*="node=n1"]');
        expect(link.text()).toBe('Data breach');
        expect(wrapper.text()).toContain('no impact entered');
    });

    it('deep-links a mention to the comment thread it is in', () => {
        const wrapper = render({ mentions: [mention] });

        expect(wrapper.get('a[href*="tab=comments"]').text()).toBe(
            'Data breach',
        );
    });
});
