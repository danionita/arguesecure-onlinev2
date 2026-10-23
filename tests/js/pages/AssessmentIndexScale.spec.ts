/**
 * The New assessment pop-up's Scale select (W126).
 */
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Index from '../../../resources/js/Pages/Assessment/Index.vue';

const post = vi.fn();
let form: Record<string, unknown>;

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: {}, url: '/assessments' }),
    useForm: (data: Record<string, unknown>) => {
        form = reactive({
            ...data,
            errors: {},
            processing: false,
            post,
            reset: vi.fn(),
        });
        return form;
    },
    router: { visit: vi.fn(), post: vi.fn(), delete: vi.fn(), get: vi.fn() },
}));

const route = (name: string) => `/${name}`;

function open(scaleOptions?: { value: string; label: string }[]) {
    window.history.replaceState({}, '', '/assessments?new=1');

    mounted = mount(Index, {
        props: { assessments: [], ...(scaleOptions ? { scaleOptions } : {}) },
        attachTo: document.body,
        global: {
            config: { globalProperties: { route } },
            stubs: { AuthenticatedLayout: { template: '<div><slot /></div>' } },
        },
    });

    return mounted;
}

let mounted: ReturnType<typeof open> | null = null;

beforeEach(() => {
    post.mockClear();
    (globalThis as unknown as { route: typeof route }).route = route;
});

afterEach(() => {
    mounted?.unmount();
    mounted = null;
    document.body.innerHTML = '';
});

describe('the New assessment pop-up', () => {
    it('lists the admin scales and starts on Standard, the first', () => {
        open([
            { value: 'default', label: 'Standard' },
            { value: 'small-organisation', label: 'Small organisation' },
            { value: 'acme', label: 'Acme' },
        ]);

        const select = document.body.querySelector(
            'select',
        ) as HTMLSelectElement;
        expect(
            Array.from(select.options).map((o) => o.textContent?.trim()),
        ).toEqual(['Standard', 'Small organisation', 'Acme']);
        expect(select.value).toBe('default');
        expect(form.scale_set_key).toBe('default');
    });

    it('sends the chosen scale with the form', async () => {
        const wrapper = open([
            { value: 'default', label: 'Standard' },
            { value: 'acme', label: 'Acme' },
        ]);
        const select = document.body.querySelector(
            'select',
        ) as HTMLSelectElement;

        select.value = 'acme';
        select.dispatchEvent(new Event('change'));
        await wrapper.vm.$nextTick();
        document.body.querySelector('form')!.dispatchEvent(new Event('submit'));

        expect(form.scale_set_key).toBe('acme');
        expect(post).toHaveBeenCalledWith(
            '/assessments.store',
            expect.anything(),
        );
    });

    it('offers no select when the server sent no scales', () => {
        open();

        expect(document.body.querySelector('select')).toBeNull();
    });
});
