/**
 * The help pages' chrome (2.5.1). Help is reachable without an account, so
 * the page has to work for two audiences: someone signed in gets the app's
 * sidebar — the way back to anywhere — and a guest gets the standalone
 * header with a way to log in, because `AuthenticatedLayout` has no user to
 * render for them.
 *
 * The "Back to the app" link is asserted absent in both: it only ever led to
 * the dashboard, and the sidebar replaced it.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import HelpIndex from '../../../resources/js/Pages/Help/Index.vue';
import HelpShow from '../../../resources/js/Pages/Help/Show.vue';

const pageProps: { auth: { user: unknown } } = { auth: { user: null } };

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: pageProps, url: '/help' }),
}));

beforeAll(() => {
    (globalThis as unknown as { route: (name: string) => string }).route = (
        name: string,
    ) => `/${name}`;
});

beforeEach(() => {
    pageProps.auth.user = null;
});

const PAGES = [
    { slug: 'concepts', title: 'Concepts' },
    { slug: 'keyboard', title: 'Keyboard map' },
];

const stubs = {
    AuthenticatedLayout: {
        template: '<div data-testid="app-layout"><slot /></div>',
    },
    AskAssistant: { template: '<div />' },
};

function index() {
    return mount(HelpIndex, {
        props: {
            pages: PAGES,
            assistant: { available: false, reason: null },
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
}

function show() {
    return mount(HelpShow, {
        props: {
            slug: 'keyboard',
            title: 'Keyboard map',
            html: '<h1>Keyboard map</h1>',
            pages: PAGES,
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
}

describe('signed in: the app sidebar', () => {
    beforeEach(() => {
        pageProps.auth.user = { name: 'Dana', email: 'dana@example.test' };
    });

    it('renders the index inside the app layout, with no guest header', () => {
        const wrapper = index();

        expect(wrapper.find('[data-testid="app-layout"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="help-shell-guest"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toContain('Concepts');
    });

    it('renders a page inside the app layout', () => {
        const wrapper = show();

        expect(wrapper.find('[data-testid="app-layout"]').exists()).toBe(true);
        expect(wrapper.find('article').html()).toContain('Keyboard map');
    });

    it('has no "Back to the app" link — the sidebar is the way out', () => {
        expect(index().text()).not.toContain('Back to the app');
        expect(show().text()).not.toContain('Back to the app');
    });
});

describe('a guest: the standalone header', () => {
    it('renders without the app layout, and offers to log in', () => {
        const wrapper = index();

        expect(wrapper.find('[data-testid="app-layout"]').exists()).toBe(false);
        const header = wrapper.find('[data-testid="help-shell-guest"] header');
        expect(header.exists()).toBe(true);
        expect(header.text()).toContain('Log in');
        expect(wrapper.text()).not.toContain('Back to the app');
    });

    it('renders a page, with the topic list marking the one open', () => {
        const wrapper = show();

        expect(wrapper.find('[data-testid="app-layout"]').exists()).toBe(false);
        const current = wrapper.find('nav [aria-current="page"]');
        expect(current.text()).toBe('Keyboard map');
    });
});
