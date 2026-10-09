/**
 * The layout's two jobs (W41): reserve the sidebar's gutter, and let a
 * keyboard past it.
 *
 * The gutter assertion is the one that matters. D16's whole claim is that
 * expanding the nav inside an assessment must not reflow the content, because
 * the chart lays out to the width it is given and someone is usually pointing
 * at it. jsdom has no layout engine, so this asserts the *mechanism* — the
 * reserved width depends on `railed` and never on the overlay — and the e2e
 * suite asserts the pixels.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AuthenticatedLayout from '../../../resources/js/Layouts/AuthenticatedLayout.vue';

const page = { props: {} as Record<string, unknown>, url: '/dashboard' };

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href', 'method', 'as'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => page,
}));

const route = (name: string, param?: string) =>
    param ? `/${name}/${param}` : `/${name}`;

beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.classList.remove('light', 'dark');
    page.props = {
        nav: {
            authenticated: true,
            isAdmin: false,
            myAssessments: [],
            sharedWithMe: [],
            notificationCount: 0,
        },
    };
    page.url = '/dashboard';
});

function render() {
    return mount(AuthenticatedLayout, {
        global: { config: { globalProperties: { route } } },
        slots: { default: '<p>page body</p>' },
    });
}

/** The element whose width the content is pushed by. */
function gutter(wrapper: ReturnType<typeof render>) {
    return wrapper.get('nav').element.parentElement!;
}

describe('the gutter', () => {
    it('is the full panel width on a document page', () => {
        expect(gutter(render()).className).toContain('w-60');
    });

    it('is a rail on an assessment page', () => {
        page.url = '/assessments/0192f3a4-5b6c-7d8e-9fa0-b1c2d3e4f5a6';

        expect(gutter(render()).className).toContain('w-12');
    });

    it('stays a rail while the overlay is open', async () => {
        page.url = '/assessments/0192f3a4-5b6c-7d8e-9fa0-b1c2d3e4f5a6';
        const wrapper = render();

        await wrapper
            .findAll('button')
            .find((b) => b.attributes('aria-label') === 'Open navigation')!
            .trigger('click');

        // The panel is now on screen and the gutter has not moved. This is
        // the assertion D16 exists for: expanding the nav must not re-lay-out
        // the chart underneath it.
        expect(gutter(wrapper).className).toContain('w-12');
        expect(gutter(wrapper).className).not.toContain('w-60');
        expect(wrapper.get('nav').classes()).toContain('fixed');
    });

    it('does not treat the assessment list as an assessment', () => {
        // `/assessments` is a document page; only `/assessments/{uuid}` is a
        // canvas. A prefix match would rail the list too.
        page.url = '/assessments';

        expect(gutter(render()).className).toContain('w-60');
    });

    it('does not treat "Shared with me" as an assessment (2.5.1)', () => {
        // `/assessments/shared` has one segment after the prefix, exactly
        // like an assessment's uuid, and the old `[^/]+` match railed it —
        // clicking "Shared with me" collapsed the navigation.
        page.url = '/assessments/shared';

        expect(gutter(render()).className).toContain('w-60');
    });
});

describe('keyboard reach', () => {
    it('puts a skip link before the navigation, targeting the main region', () => {
        const wrapper = render();
        const first = wrapper.get('a');

        expect(first.text()).toBe('Skip to content');
        expect(first.attributes('href')).toBe('#main-content');
        // Focusable target, or the link moves the scroll position and leaves
        // focus behind in the sidebar.
        expect(wrapper.get('main').attributes('id')).toBe('main-content');
        expect(wrapper.get('main').attributes('tabindex')).toBe('-1');
    });

    it('renders the navigation before the content in DOM order', () => {
        const wrapper = render();
        const html = wrapper.html();

        expect(html.indexOf('<nav')).toBeLessThan(html.indexOf('page body'));
    });
});

describe('missing nav props', () => {
    it('renders rather than throwing when the page did not share them', () => {
        // Guests, the Help pages and several component tests arrive with a
        // partial props object; a sidebar that threw on a missing list would
        // take the whole page with it.
        page.props = {};

        expect(() => render()).not.toThrow();
    });
});
