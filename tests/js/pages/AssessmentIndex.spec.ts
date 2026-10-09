/**
 * The assessment list's two W42 additions: the filter (item 13) and the
 * per-row actions (item 20).
 *
 * The filter is the one that has to be tested here rather than on the server,
 * because its whole claim is that it makes no request. The actions are tested
 * on both sides — the button here, the 403 in
 * `tests/Feature/Dashboard/AssessmentListActionsTest.php` — since a page that
 * renders a button the route refuses is as broken as a route that allows what
 * the page hides.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Index from '../../../resources/js/Pages/Assessment/Index.vue';

const visit = vi.fn();
const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: {
        name: 'Link',
        props: ['href', 'method', 'as'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: {}, url: '/assessments' }),
    useForm: () => ({
        title: '',
        description: '',
        errors: {},
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
    }),
    router: {
        get visit() {
            return visit;
        },
        get post() {
            return post;
        },
        delete: vi.fn(),
    },
}));

const route = (name: string, param?: string) =>
    param ? `/${name}/${param}` : `/${name}`;

function row(over: Record<string, unknown> = {}) {
    return {
        uuid: 'a1',
        title: 'Payment platform',
        description: 'Cardholder data and the systems around it',
        is_public: false,
        is_template: false,
        is_owner: true,
        can_manage: true,
        can_delete: true,
        owner_name: null,
        updated_at: null,
        tags: [],
        ...over,
    };
}

function render(assessments = [row()]) {
    return mount(Index, {
        global: {
            config: { globalProperties: { route } },
            stubs: { AuthenticatedLayout: { template: '<div><slot /></div>' } },
        },
        props: { assessments },
    });
}

beforeEach(() => {
    visit.mockClear();
    post.mockClear();
    (globalThis as unknown as { route: typeof route }).route = route;
});

describe('the filter', () => {
    it('narrows the list without making a request', async () => {
        const wrapper = render([
            row({ uuid: 'a1', title: 'Payment platform' }),
            row({ uuid: 'a2', title: 'Warehouse robots' }),
        ]);

        await wrapper.get('input[type="search"]').setValue('warehouse');

        expect(wrapper.findAll('[data-assessment]')).toHaveLength(1);
        expect(
            wrapper.get('[data-assessment]').attributes('data-assessment'),
        ).toBe('a2');
        // The whole list is already on the client, unpaginated. Going back to
        // the server to hide some of it would be slower and no more correct.
        expect(visit).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
    });

    it('matches the description as well as the title', async () => {
        const wrapper = render([
            row({ uuid: 'a1', description: 'Cardholder data' }),
            row({
                uuid: 'a2',
                title: 'Robots',
                description: 'Warehouse floor',
            }),
        ]);

        await wrapper.get('input[type="search"]').setValue('cardholder');

        expect(wrapper.findAll('[data-assessment]')).toHaveLength(1);
    });

    it('ignores case and surrounding space', async () => {
        const wrapper = render([row({ title: 'Payment platform' })]);

        await wrapper.get('input[type="search"]').setValue('  PAYMENT  ');

        expect(wrapper.findAll('[data-assessment]')).toHaveLength(1);
    });

    it('says nothing matched, rather than offering to create a first one', async () => {
        const wrapper = render([row()]);

        await wrapper.get('input[type="search"]').setValue('zzz');

        // The user has assessments; they just cannot see them from here.
        // Showing "No assessments yet — create one" at that moment reads as
        // if theirs had been lost.
        expect(wrapper.text()).toContain('No assessments match that');
        expect(wrapper.text()).not.toContain('No assessments yet');
    });

    it('is not offered when there is nothing to filter', () => {
        expect(render([]).find('input[type="search"]').exists()).toBe(false);
    });
});

/** The row's actions by accessible name — they are icons since 2.5.1. */
function actions(wrapper: ReturnType<typeof render>): string[] {
    return wrapper
        .findAll('[data-testid="assessment-actions"] [aria-label]')
        .map((el) => el.attributes('aria-label')!);
}

describe('per-row actions', () => {
    /**
     * W86 item 7: Settings, Share and Export, matching `AssessmentActions.vue`
     * (D40) minus History and Print; plus Duplicate and Delete. 2.5.1 made
     * all five icons, named in the tooltip and the accessible name.
     */
    it('offers Settings, Share, Export, Duplicate and Delete — never Report or Download', () => {
        expect(actions(render([row({ can_manage: true })]))).toEqual([
            'Settings',
            'Share',
            'Export',
            'Duplicate',
            'Delete',
        ]);
    });

    it('renders them as icons, with no visible words', () => {
        const bar = render([row()]).get('[data-testid="assessment-actions"]');

        expect(bar.text().trim()).toBe('');
        expect(bar.findAll('svg').length).toBe(5);
    });

    it('opens the same ExportMenu the editor uses, not a second menu with the same name', () => {
        const wrapper = render([row()]);

        expect(
            wrapper.get('[aria-haspopup="menu"]').attributes('aria-label'),
        ).toBe('Export');
    });

    it('offers Export to everyone, gated on nothing — export never needed manage', () => {
        expect(actions(render([row({ can_manage: false })]))).toContain(
            'Export',
        );
    });

    /**
     * The mutation-tested guard: `can_manage` is what keeps Settings off a
     * viewer's screen.
     */
    it('offers Settings only to someone who can manage', () => {
        expect(actions(render([row({ can_manage: true })]))).toContain(
            'Settings',
        );
        expect(actions(render([row({ can_manage: false })]))).not.toContain(
            'Settings',
        );
    });

    it('offers Share only to someone who can manage', () => {
        expect(actions(render([row({ can_manage: true })]))).toContain('Share');
        expect(actions(render([row({ can_manage: false })]))).not.toContain(
            'Share',
        );
    });

    it('offers Delete only to someone who can delete', () => {
        expect(actions(render([row({ can_delete: true })]))).toContain(
            'Delete',
        );
        expect(actions(render([row({ can_delete: false })]))).not.toContain(
            'Delete',
        );
    });

    it('reads the permissions from the server, never from ownership', () => {
        const names = actions(
            render([
                row({ is_owner: false, can_manage: true, can_delete: false }),
            ]),
        );

        expect(names).toContain('Share');
        expect(names).not.toContain('Delete');
    });

    it('offers Duplicate to everyone, because the copy is the actor’s own', () => {
        expect(
            actions(
                render([
                    row({
                        is_owner: false,
                        can_manage: false,
                        can_delete: false,
                    }),
                ]),
            ),
        ).toContain('Duplicate');
    });

    it('posts the duplicate and blocks a second click while it runs', async () => {
        const wrapper = render([row()]);
        const button = () => wrapper.get('button[aria-label="Duplicate"]');

        await button().trigger('click');

        expect(post).toHaveBeenCalledWith(
            '/assessments.duplicate/a1',
            {},
            expect.anything(),
        );
        expect(button().attributes('disabled')).toBeDefined();
    });

    it('names the assessment in the delete confirmation', async () => {
        const wrapper = render([row({ title: 'Payment platform' })]);
        await wrapper.get('button[aria-label="Delete"]').trigger('click');

        expect(document.body.textContent).toContain('Payment platform');
    });
});

describe('it is "My assessments" (2.5.1)', () => {
    it('is titled for what it lists', () => {
        expect(render().get('h1').text()).toBe('My assessments');
    });
});

/**
 * Item 8: the create-assessment dialog's form was rendered with no padding
 * wrapper at all — `Modal.vue`'s body slot carries none of its own, and
 * every sibling caller (`ConfirmDialog.vue`, `ShareDialog.vue`, the
 * catalogue's insert dialog) supplies `px-4 py-4`/`p-4` around it. jsdom has
 * no layout engine, so this cannot assert pixels — it asserts the actual
 * class the fix depends on, the same way the print guard above asserts the
 * specific class rather than "a class exists".
 */
describe('the create-assessment dialog has the padding wrapper its siblings use (item 8)', () => {
    it('wraps the form body in the px-4 py-4 convention, not left bare', async () => {
        const wrapper = render([]);
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'New assessment')!
            .trigger('click');

        // Teleported to <body> (Modal.vue), so it is outside `wrapper`'s own
        // element — same reason the delete-confirmation test above reads
        // `document.body` rather than the wrapper.
        const form = document.body.querySelector('form');

        expect(form).not.toBeNull();
        expect(Array.from(form!.classList)).toContain('px-4');
        expect(Array.from(form!.classList)).toContain('py-4');
    });
});

describe('labels on the row (W43 item 18)', () => {
    it('renders the root node’s labels as chips', () => {
        const wrapper = render([row({ tags: ['PCI', '2026 review'] })]);

        expect(wrapper.text()).toContain('PCI');
        expect(wrapper.text()).toContain('2026 review');
    });

    it('filters by label as well as by title and description', async () => {
        const wrapper = render([
            row({ uuid: 'a1', title: 'Payments', tags: ['PCI'] }),
            row({ uuid: 'a2', title: 'Robots', tags: ['Warehouse'] }),
        ]);

        await wrapper.get('input[type="search"]').setValue('pci');

        // Putting labels on the row and then not letting anyone search by
        // them would leave the reader scanning for a chip by eye, which is
        // the work the label was meant to save.
        expect(wrapper.findAll('[data-assessment]')).toHaveLength(1);
        expect(
            wrapper.get('[data-assessment]').attributes('data-assessment'),
        ).toBe('a1');
        expect(visit).not.toHaveBeenCalled();
    });
});
