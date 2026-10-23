/**
 * *Assessments shared with me* (W73 item 20, D33).
 *
 * The page's whole reason for existing is that `/assessments` does not
 * distinguish a shared row from an owned one, so the two things worth
 * asserting here are that every row names its owner and that the emptiness
 * says the right thing — "nobody has shared anything with you" is a different
 * statement from "you have no assessments", and the combined list could only
 * make the second one.
 *
 * The scoping — which rows reach the page at all — is server-side and is
 * asserted in tests/Feature/Dashboard/SharedAssessmentsTest.php, where a leak
 * would actually happen.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Shared from '../../../resources/js/Pages/Assessment/Shared.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: {}, url: '/assessments/shared' }),
    router: { post: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

const route = (name: string, param?: string) =>
    param ? `/${name}/${param}` : `/${name}`;

beforeEach(() => {
    (globalThis as unknown as { route: typeof route }).route = route;
});

function render(assessments: Record<string, unknown>[] = []) {
    return mount(Shared, {
        global: {
            config: { globalProperties: { route } },
            stubs: { AuthenticatedLayout: { template: '<div><slot /></div>' } },
        },
        props: { assessments },
    });
}

const row = {
    uuid: 'a1',
    title: 'Payment platform',
    description: 'Cardholder data',
    is_public: false,
    is_owner: false,
    can_manage: false,
    can_delete: false,
    updated_at: null,
    owner_name: 'Dana',
    tags: [],
};

describe('the page', () => {
    it('is titled for what it is, not for the list it came out of', () => {
        expect(render().get('h1').text()).toBe('Assessments shared with me');
    });

    it('names the owner on every row', () => {
        const wrapper = render([
            row,
            { ...row, uuid: 'a2', title: 'Web shop', owner_name: 'Sam' },
        ]);

        expect(wrapper.text()).toContain('Shared by Dana');
        expect(wrapper.text()).toContain('Shared by Sam');
        // Knowing whose work you are about to open is most of what makes a
        // shared list usable, and it is the thing `/assessments` could not
        // say per row.
        expect(wrapper.text().match(/Shared by/g)).toHaveLength(2);
    });

    it('links each row into the editor', () => {
        const wrapper = render([row]);

        expect(wrapper.get('a[href="/assessments.show/a1"]').text()).toBe(
            'Payment platform',
        );
    });

    /**
     * 2.5.1: the same list as *My assessments* — the same filter and the same
     * icon actions, each gated on the permission the server computed for the
     * row. A viewer gets Export and Duplicate; a manager Settings and Share.
     */
    it('uses the same list and actions as My assessments', () => {
        const names = (wrapper: ReturnType<typeof render>) =>
            wrapper
                .findAll('[data-testid="assessment-actions"] [aria-label]')
                .map((el) => el.attributes('aria-label'));

        expect(names(render([row]))).toEqual(['Export', 'Duplicate']);
        expect(names(render([{ ...row, can_manage: true }]))).toEqual([
            'Settings',
            'Share',
            'Export',
            'Duplicate',
        ]);
        expect(render([row]).find('input[type="search"]').exists()).toBe(true);
    });
});

describe('the emptiness', () => {
    it('says nobody has shared anything, not that you have no assessments', () => {
        const text = render().text();

        expect(text).toContain('Nothing shared with you');
        expect(text).toContain('Assessments other people invite you to');
        // The combined list's empty state reads as if the user's own work had
        // been lost, which is the wrong thing to tell someone whose only
        // problem is that no one has invited them yet.
        expect(text).not.toContain('Create one to start mapping');
    });

    it('renders no rows and no list when there is nothing', () => {
        const wrapper = render();

        expect(wrapper.find('ul').exists()).toBe(false);
        expect(wrapper.findAll('[data-assessment]')).toHaveLength(0);
    });
});
