import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ReferencesTab from '../../../resources/js/Components/NodeModal/tabs/ReferencesTab.vue';
import type { NodeReference } from '../../../resources/js/Components/NodeModal/tabs/useReferences';
import { useNodeModal } from '../../../resources/js/Components/NodeModal/useNodeModal';
import { useToast } from '../../../resources/js/Components/UI/useToast';
import type { AssessmentStore } from '../../../resources/js/stores/assessmentStore';

/**
 * W119: the References tab sends what the person typed to a server that
 * validates identifiers (security.md §7), so the client's job is narrow and
 * easy to get subtly wrong: send the right kind with only the fields that kind
 * uses, never send blank input, show the server's own reason on a 422, and
 * offer editing controls only to someone who can edit and is not locked out
 * by a collaborator holding the node (2.5.1).
 */
function ref(id: number, over: Partial<NodeReference> = {}): NodeReference {
    return {
        id,
        kind: 'cve',
        identifier: `CVE-2024-000${id}`,
        url: null,
        title: null,
        position: id,
        ...over,
    };
}

const axios = {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
};

function storeWith(update: boolean): AssessmentStore {
    return {
        abilities: { update },
        assessment: { uuid: 'a1' },
    } as unknown as AssessmentStore;
}

async function render(
    refs: NodeReference[],
    opts: { update?: boolean; nodeType?: string } = {},
) {
    axios.get.mockResolvedValue({ data: { references: refs } });
    const wrapper = mount(ReferencesTab, {
        props: {
            store: storeWith(opts.update ?? true),
            uuid: 'n1',
            nodeType: opts.nodeType ?? 'attack',
        },
    });
    await flushPromises();
    return wrapper;
}

function button(wrapper: Awaited<ReturnType<typeof render>>, text: string) {
    return wrapper.findAll('button').find((b) => b.text().includes(text));
}

function aria(wrapper: Awaited<ReturnType<typeof render>>, label: string) {
    return wrapper.findAll(`button[aria-label="${label}"]`);
}

async function submit(wrapper: Awaited<ReturnType<typeof render>>) {
    await wrapper.find('form').trigger('submit');
    await flushPromises();
}

beforeEach(() => {
    Object.values(axios).forEach((fn) => fn.mockReset());
    (window as unknown as { axios: unknown }).axios = axios;
    (globalThis as unknown as { route: unknown }).route = (
        name: string,
        params: unknown,
    ) => `/${name}/${[params].flat().join('/')}`;
    useToast().toasts.splice(0);
    useNodeModal().lockedBy.value = null;
});

afterEach(() => {
    useNodeModal().lockedBy.value = null;
});

describe('ReferencesTab list', () => {
    it('loads the references of this node', async () => {
        await render([]);

        expect(axios.get).toHaveBeenCalledExactlyOnceWith(
            '/assessments.node-references.index/a1/n1',
        );
    });

    it('explains an empty list differently for a defence than for an attack', async () => {
        const attack = await render([], { nodeType: 'attack' });
        expect(attack.text()).toContain('No references yet');
        expect(attack.text()).toContain(
            'Attach a CVE or related identifier to back this up.',
        );

        const defence = await render([], { nodeType: 'defence' });
        expect(defence.text()).toContain(
            'Cite the framework control this implements',
        );
    });

    it('shows the load error instead of the empty state', async () => {
        axios.get.mockRejectedValue(new Error('500'));
        const wrapper = mount(ReferencesTab, {
            props: { store: storeWith(true), uuid: 'n1', nodeType: 'attack' },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Could not load references.');
        expect(wrapper.text()).not.toContain('No references yet');
    });

    it('lists kind label, identifier and title, linking only references that have a URL', async () => {
        const wrapper = await render([
            ref(1, { title: 'Backdoor in xz' }),
            ref(2, {
                kind: 'url',
                identifier: null,
                url: 'https://example.test/advisory',
            }),
        ]);

        const items = wrapper.findAll('li');
        expect(items[0].text()).toContain('CVE');
        expect(items[0].text()).toContain('CVE-2024-0001');
        expect(items[0].text()).toContain('Backdoor in xz');
        expect(items[0].find('a').exists()).toBe(false);

        const link = items[1].find('a');
        expect(link.attributes('href')).toBe('https://example.test/advisory');
        expect(link.attributes('rel')).toBe('noopener nofollow');
        expect(link.text()).toBe('https://example.test/advisory');
    });
});

describe('ReferencesTab permissions', () => {
    it('gives a user who cannot update no form and no row controls', async () => {
        const wrapper = await render([ref(1)], { update: false });

        expect(wrapper.find('form').exists()).toBe(false);
        expect(aria(wrapper, 'Remove')).toHaveLength(0);
        expect(aria(wrapper, 'Move up')).toHaveLength(0);
    });

    it('turns a user who can update read-only while someone else holds the node', async () => {
        useNodeModal().lockedBy.value = { user_id: 9, name: 'Eve' };
        const wrapper = await render([ref(1)]);

        expect(wrapper.find('form').exists()).toBe(false);
        expect(aria(wrapper, 'Remove')).toHaveLength(0);
    });

    it('offers the form and row controls to an editor holding no lock conflict', async () => {
        const wrapper = await render([ref(1)]);

        expect(wrapper.find('form').exists()).toBe(true);
        expect(aria(wrapper, 'Remove')).toHaveLength(1);
    });
});

describe('ReferencesTab ordering and removal', () => {
    it('disables Move up on the first row and Move down on the last', async () => {
        const wrapper = await render([ref(1), ref(2), ref(3)]);

        const up = aria(wrapper, 'Move up');
        const down = aria(wrapper, 'Move down');
        expect(up.map((b) => b.attributes('disabled') !== undefined)).toEqual([
            true,
            false,
            false,
        ]);
        expect(down.map((b) => b.attributes('disabled') !== undefined)).toEqual(
            [false, false, true],
        );
    });

    it('Move down sends the whole new order with the two neighbours swapped', async () => {
        const wrapper = await render([ref(1), ref(2), ref(3)]);
        axios.post.mockResolvedValue({
            data: { references: [ref(2), ref(1), ref(3)] },
        });

        await aria(wrapper, 'Move down')[0].trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledExactlyOnceWith(
            '/assessments.node-references.reorder/a1/n1',
            { order: [2, 1, 3] },
        );
        expect(
            wrapper.findAll('li').map((li) => li.text().slice(0, 20)),
        ).toEqual([
            expect.stringContaining('CVE-2024-0002'),
            expect.stringContaining('CVE-2024-0001'),
            expect.stringContaining('CVE-2024-0003'),
        ]);
    });

    it('Move up sends the order with the row swapped with the one above', async () => {
        const wrapper = await render([ref(1), ref(2), ref(3)]);
        axios.post.mockResolvedValue({
            data: { references: [ref(1), ref(3), ref(2)] },
        });

        await aria(wrapper, 'Move up')[2].trigger('click');
        await flushPromises();

        expect(axios.post.mock.calls[0][1]).toEqual({ order: [1, 3, 2] });
    });

    it('tells the user when a reorder fails', async () => {
        const wrapper = await render([ref(1), ref(2)]);
        axios.post.mockRejectedValue(new Error('500'));

        await aria(wrapper, 'Move down')[0].trigger('click');
        await flushPromises();

        expect(useToast().toasts.map((t) => t.text)).toEqual([
            'Could not reorder references.',
        ]);
    });

    it('Remove deletes that reference and drops its row', async () => {
        const wrapper = await render([ref(1), ref(2)]);
        axios.delete.mockResolvedValue({});

        await aria(wrapper, 'Remove')[1].trigger('click');
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledExactlyOnceWith(
            '/assessments.node-references.destroy/a1/n1/2',
        );
        expect(wrapper.findAll('li')).toHaveLength(1);
    });

    it('keeps the row and tells the user when removal fails', async () => {
        const wrapper = await render([ref(1)]);
        axios.delete.mockRejectedValue(new Error('500'));

        await aria(wrapper, 'Remove')[0].trigger('click');
        await flushPromises();

        expect(wrapper.findAll('li')).toHaveLength(1);
        expect(useToast().toasts.map((t) => t.text)).toEqual([
            'Could not remove that reference.',
        ]);
    });
});

describe('ReferencesTab add form', () => {
    it('opens on CVE for an attack, and on Control for a defence', async () => {
        const attack = await render([], { nodeType: 'attack' });
        expect(attack.find('input').attributes('placeholder')).toBe(
            'CVE-2024-3094',
        );

        const defence = await render([], { nodeType: 'defence' });
        expect(defence.find('input').attributes('placeholder')).toBe(
            'ISO27001-2022:A.5.23 or NIS2:ART.21(2)(D)',
        );
    });

    it('refuses a blank identifier without contacting the server', async () => {
        const wrapper = await render([]);

        await wrapper.find('input').setValue('   ');
        await submit(wrapper);

        expect(wrapper.text()).toContain('Enter a CVE identifier.');
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('sends the trimmed identifier and label, and clears the form', async () => {
        const wrapper = await render([]);
        axios.post.mockResolvedValue({
            data: { reference: ref(5, { title: 'xz' }) },
        });

        const [identifier, label] = wrapper.findAll('input');
        await identifier.setValue('  CVE-2024-3094 ');
        await label.setValue(' xz ');
        await submit(wrapper);

        expect(axios.post).toHaveBeenCalledExactlyOnceWith(
            '/assessments.node-references.store/a1/n1',
            { kind: 'cve', identifier: 'CVE-2024-3094', title: 'xz' },
        );
        expect(wrapper.findAll('li')).toHaveLength(1);
        expect((identifier.element as HTMLInputElement).value).toBe('');
        expect((label.element as HTMLInputElement).value).toBe('');
    });

    it('omits the title when the label is blank', async () => {
        const wrapper = await render([]);
        axios.post.mockResolvedValue({ data: { reference: ref(5) } });

        await wrapper.find('input').setValue('CVE-2024-3094');
        await submit(wrapper);

        expect(axios.post.mock.calls[0][1]).toEqual({
            kind: 'cve',
            identifier: 'CVE-2024-3094',
        });
    });

    it('reveals the other kinds behind "More kinds" and collapses back to the primary kind', async () => {
        const wrapper = await render([]);
        expect(wrapper.find('select').exists()).toBe(false);

        await button(wrapper, 'More kinds')!.trigger('click');
        const select = wrapper.find('select');
        expect(select.exists()).toBe(true);
        // A control can only be cited on a defence (PLAN-2.7 D64).
        const values = select.findAll('option').map((o) => o.element.value);
        expect(values).toContain('cwe');
        expect(values).not.toContain('control');

        await select.setValue('cwe');
        expect(wrapper.find('input').attributes('placeholder')).toBe('CWE-79');

        await button(wrapper, 'Just CVE')!.trigger('click');
        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.find('input').attributes('placeholder')).toBe(
            'CVE-2024-3094',
        );
    });

    it('a URL reference sends a url and no identifier, and requires the URL', async () => {
        const wrapper = await render([]);
        axios.post.mockResolvedValue({
            data: {
                reference: ref(5, {
                    kind: 'url',
                    identifier: null,
                    url: 'https://example.test/x',
                }),
            },
        });

        await button(wrapper, 'More kinds')!.trigger('click');
        await wrapper.find('select').setValue('url');
        await submit(wrapper);
        expect(wrapper.text()).toContain('Enter a URL.');
        expect(axios.post).not.toHaveBeenCalled();

        await wrapper.find('input').setValue(' https://example.test/x ');
        await submit(wrapper);

        expect(axios.post.mock.calls[0][1]).toEqual({
            kind: 'url',
            url: 'https://example.test/x',
        });
    });

    it('switching kind clears what was typed for the previous kind', async () => {
        const wrapper = await render([]);

        await wrapper.find('input').setValue('CVE-2024-3094');
        await button(wrapper, 'More kinds')!.trigger('click');
        await wrapper.find('select').setValue('cwe');

        expect((wrapper.find('input').element as HTMLInputElement).value).toBe(
            '',
        );
    });

    it('shows the server reason for a rejected identifier, in preference to its generic message', async () => {
        const wrapper = await render([]);
        axios.post.mockRejectedValue({
            response: {
                data: {
                    message: 'The given data was invalid.',
                    errors: { identifier: ['Not a valid CVE identifier.'] },
                },
            },
        });

        await wrapper.find('input').setValue('nonsense');
        await submit(wrapper);

        expect(wrapper.text()).toContain('Not a valid CVE identifier.');
        expect(wrapper.text()).not.toContain('The given data was invalid.');
        // Typed input is kept so the person can correct it.
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe(
            'nonsense',
        );
    });

    it('falls back to the response message, then to a kind-specific sentence', async () => {
        const wrapper = await render([]);
        await wrapper.find('input').setValue('CVE-2024-3094');

        axios.post.mockRejectedValueOnce({
            response: { data: { message: 'Too many references.' } },
        });
        await submit(wrapper);
        expect(wrapper.text()).toContain('Too many references.');

        axios.post.mockRejectedValueOnce(new Error('network'));
        await submit(wrapper);
        expect(wrapper.text()).toContain('Could not add that CVE reference.');
    });

    it('does not leave the Add button disabled after a failure', async () => {
        const wrapper = await render([]);
        axios.post.mockRejectedValue(new Error('network'));

        await wrapper.find('input').setValue('CVE-2024-3094');
        await submit(wrapper);

        expect(
            button(wrapper, 'Add reference')!.attributes('disabled'),
        ).toBeUndefined();
    });
});

/**
 * DEFECT: the uuid watcher reloads, but useReferences(store.assessment.uuid,
 * props.uuid) captured the first node's uuid at setup, so a changed `uuid`
 * prop reloads (and writes to) the ORIGINAL node. Today NodeModal remounts the
 * tab per node, which hides it. Fix: give useReferences a getter for the node
 * uuid, or key the tab on it; then flip the expectation to '.../a1/n2'.
 */
describe('ReferencesTab node switch', () => {
    it('DEFECT: a changed uuid reloads the references of the original node', async () => {
        const wrapper = await render([]);

        await wrapper.setProps({ uuid: 'n2' });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(axios.get).toHaveBeenLastCalledWith(
            '/assessments.node-references.index/a1/n1',
        );
    });
});
