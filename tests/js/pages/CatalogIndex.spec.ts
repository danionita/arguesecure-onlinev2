/**
 * `Catalog/Index.vue` — the catalogue browser.
 *
 * Bare it is browse-only; opened with an assessment it offers Insert, and an
 * attack or defence entry must first be given a parent because
 * `CatalogInserter` rejects a parentless one with a 422. These specs pin the
 * search request, when Insert exists, which parents are offered for which
 * kind, and what is sent and shown when an insert succeeds or fails.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    CatalogEntrySummary,
    CatalogParents,
} from '../../../resources/js/Components/Catalog/types';
import { useToast } from '../../../resources/js/Components/UI/useToast';
import Index from '../../../resources/js/Pages/Catalog/Index.vue';

const routerGet = vi.fn();
const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    router: { get: (...a: unknown[]) => routerGet(...a) },
}));

const route = (name: string, params?: Record<string, unknown>) =>
    params ? `/${name}?${JSON.stringify(params)}` : `/${name}`;

function entry(over: Partial<CatalogEntrySummary> = {}): CatalogEntrySummary {
    return {
        id: 1,
        kind: 'risk',
        title: 'Data breach',
        description: null,
        isBundle: false,
        isPublic: true,
        isSystem: false,
        ...over,
    };
}

const parents: CatalogParents = {
    risks: [
        { uuid: 'r1', title: 'Ransomware' },
        { uuid: 'r2', title: '' },
    ],
    attacks: [{ uuid: 'a1', title: 'Phishing' }],
};

function render(over: Record<string, unknown> = {}) {
    return mount(Index, {
        props: {
            entries: [entry()],
            filters: { kind: null, q: null },
            assessment: { uuid: 'asm-1' },
            parents,
            ...over,
        },
        global: {
            config: { globalProperties: { route } },
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
                Modal: {
                    props: ['show', 'title'],
                    template:
                        '<div v-if="show" data-testid="modal"><h3>{{ title }}</h3><slot /><slot name="footer" /></div>',
                },
            },
        },
    });
}

const insertButton = (w: ReturnType<typeof render>, i = 0) =>
    w.findAll('button').filter((b) => b.text() === 'Insert')[i];

beforeEach(() => {
    routerGet.mockReset();
    post.mockReset();
    post.mockResolvedValue({ data: { nodes: [{}] } });
    useToast().toasts.splice(0);
    (globalThis as unknown as { route: typeof route }).route = route;
    (window as unknown as { axios: unknown }).axios = { post };
});

describe('listing', () => {
    it('shows the empty state when nothing matches', () => {
        const wrapper = render({ entries: [] });

        expect(wrapper.text()).toContain('No matching entries');
    });

    it('lists each entry and no empty state when there are results', () => {
        const wrapper = render({
            entries: [entry(), entry({ id: 2, title: 'Phishing' })],
        });

        expect(wrapper.text()).toContain('Data breach');
        expect(wrapper.text()).toContain('Phishing');
        expect(wrapper.text()).not.toContain('No matching entries');
    });

    it('seeds the search box and kind from the current filters', () => {
        const wrapper = render({ filters: { kind: 'attack', q: 'phish' } });

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            'phish',
        );
        expect((wrapper.get('select').element as HTMLSelectElement).value).toBe(
            'attack',
        );
    });
});

describe('searching', () => {
    it('sends the term, kind and assessment to catalog.index, replacing history', async () => {
        const wrapper = render();
        await wrapper.get('input').setValue('phish');
        await wrapper.get('select').setValue('attack');
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Search')!
            .trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/catalog.index',
            { q: 'phish', kind: 'attack', assessment: 'asm-1' },
            { preserveState: true, replace: true },
        );
    });

    it('omits an empty term and the "all kinds" choice rather than sending blanks', async () => {
        const wrapper = render({ assessment: null });
        await wrapper.get('input').trigger('keyup.enter');

        expect(routerGet.mock.calls[0][1]).toEqual({
            q: undefined,
            kind: undefined,
            assessment: undefined,
        });
    });
});

describe('browse-only versus insertable', () => {
    it('offers no Insert without an assessment in context', () => {
        const wrapper = render({ assessment: null });

        expect(insertButton(wrapper)).toBeUndefined();
    });

    it('offers Insert on every entry when an assessment is in context', () => {
        const wrapper = render({
            entries: [entry(), entry({ id: 2, kind: 'attack' })],
        });

        expect(
            wrapper.findAll('button').filter((b) => b.text() === 'Insert'),
        ).toHaveLength(2);
    });
});

describe('inserting a risk', () => {
    it('posts at once with an empty body and no parent prompt', async () => {
        const wrapper = render();
        await insertButton(wrapper).trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith(
            '/assessments.catalog-entries.insert?{"assessment":"asm-1","catalogEntry":1}',
            {},
        );
        expect(wrapper.find('[data-testid="modal"]').exists()).toBe(false);
    });

    it('reports a single insert plainly', async () => {
        const wrapper = render();
        await insertButton(wrapper).trigger('click');
        await flushPromises();

        expect(useToast().toasts.map((t) => [t.tone, t.text])).toEqual([
            ['success', "Inserted 'Data breach'."],
        ]);
    });

    it('counts the linked items when a bundle brought children with it', async () => {
        post.mockResolvedValue({ data: { nodes: [{}, {}, {}] } });
        const wrapper = render();
        await insertButton(wrapper).trigger('click');
        await flushPromises();

        expect(useToast().toasts[0].text).toBe(
            "Inserted 'Data breach' and 2 linked item(s).",
        );
    });

    it('shows the server message on failure and a generic one when there is none', async () => {
        post.mockRejectedValueOnce({
            response: { data: { message: 'Not allowed.' } },
        });
        post.mockRejectedValueOnce(new Error('network'));
        const wrapper = render();

        await insertButton(wrapper).trigger('click');
        await flushPromises();
        await insertButton(wrapper).trigger('click');
        await flushPromises();

        expect(useToast().toasts.map((t) => [t.tone, t.text])).toEqual([
            ['danger', 'Not allowed.'],
            ['danger', 'Could not insert that entry.'],
        ]);
    });
});

describe('inserting an attack or defence', () => {
    const attack = entry({ id: 5, kind: 'attack', title: 'Phishing' });
    const defence = entry({ id: 6, kind: 'defence', title: 'MFA' });

    it('asks which risk an attack goes under, offering risks with a fallback for untitled ones', async () => {
        const wrapper = render({ entries: [attack] });
        await insertButton(wrapper).trigger('click');

        const modal = wrapper.get('[data-testid="modal"]');
        expect(post).not.toHaveBeenCalled();
        expect(modal.text()).toContain("Insert 'Phishing'");
        expect(modal.text()).toContain('Attach under risk');
        expect(
            modal
                .findAll('option')
                .map((o) => [o.attributes('value'), o.text()]),
        ).toEqual([
            ['', 'Choose one…'],
            ['r1', 'Ransomware'],
            ['r2', '(untitled risk)'],
        ]);
    });

    it('asks which attack a defence goes under', async () => {
        const wrapper = render({ entries: [defence] });
        await insertButton(wrapper).trigger('click');

        const modal = wrapper.get('[data-testid="modal"]');
        expect(modal.text()).toContain('Attach under attack');
        expect(modal.findAll('option').map((o) => o.text())).toEqual([
            'Choose one…',
            'Phishing',
        ]);
    });

    it('keeps the modal Insert disabled until a parent is chosen, and does not post', async () => {
        const wrapper = render({ entries: [attack] });
        await insertButton(wrapper).trigger('click');
        const modal = wrapper.get('[data-testid="modal"]');
        const confirm = modal
            .findAll('button')
            .find((b) => b.text() === 'Insert')!;

        expect(confirm.attributes('disabled')).toBeDefined();
        await confirm.trigger('click');
        expect(post).not.toHaveBeenCalled();

        await modal.get('select').setValue('r1');
        expect(confirm.attributes('disabled')).toBeUndefined();
    });

    it('posts the chosen parent_uuid, then closes the modal', async () => {
        const wrapper = render({ entries: [attack] });
        await insertButton(wrapper).trigger('click');
        const modal = wrapper.get('[data-testid="modal"]');
        await modal.get('select').setValue('r1');
        await modal
            .findAll('button')
            .find((b) => b.text() === 'Insert')!
            .trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith(
            '/assessments.catalog-entries.insert?{"assessment":"asm-1","catalogEntry":5}',
            { parent_uuid: 'r1' },
        );
        expect(wrapper.find('[data-testid="modal"]').exists()).toBe(false);
    });

    it('keeps the modal open after a failure so the choice can be retried', async () => {
        post.mockRejectedValueOnce(new Error('boom'));
        const wrapper = render({ entries: [attack] });
        await insertButton(wrapper).trigger('click');
        const modal = wrapper.get('[data-testid="modal"]');
        await modal.get('select').setValue('r1');
        await modal
            .findAll('button')
            .find((b) => b.text() === 'Insert')!
            .trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="modal"]').exists()).toBe(true);
    });

    it('Cancel closes the modal without posting', async () => {
        const wrapper = render({ entries: [attack] });
        await insertButton(wrapper).trigger('click');
        await wrapper
            .get('[data-testid="modal"]')
            .findAll('button')
            .find((b) => b.text() === 'Cancel')!
            .trigger('click');

        expect(wrapper.find('[data-testid="modal"]').exists()).toBe(false);
        expect(post).not.toHaveBeenCalled();
    });

    it('forgets a previously chosen parent when the modal is reopened', async () => {
        const wrapper = render({ entries: [attack] });
        await insertButton(wrapper).trigger('click');
        await wrapper.get('[data-testid="modal"] select').setValue('r1');
        await wrapper
            .get('[data-testid="modal"]')
            .findAll('button')
            .find((b) => b.text() === 'Cancel')!
            .trigger('click');
        await insertButton(wrapper).trigger('click');

        expect(
            (
                wrapper.get('[data-testid="modal"] select')
                    .element as HTMLSelectElement
            ).value,
        ).toBe('');
    });
});
