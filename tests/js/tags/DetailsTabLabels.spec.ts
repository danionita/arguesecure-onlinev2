/**
 * Labels on the Details tab (W43, item 18).
 *
 * `TagEditor.spec.ts` covers the field's own behaviour against props. These
 * cover the wiring: that the tab shows the node's labels, offers the
 * assessment's vocabulary, and calls the store rather than emitting into
 * nothing — which is the join that a field working perfectly in isolation
 * can still get wrong.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DetailsTab from '../../../resources/js/Components/NodeModal/tabs/DetailsTab.vue';
import { makeComputedStore } from '../nodeModal/fixture';

vi.stubGlobal(
    'route',
    (name: string, params: unknown) => `/${name}/${JSON.stringify(params)}`,
);

function render(uuid: string) {
    const store = makeComputedStore();

    store.tags.push(
        { id: 1, name: 'PCI', slug: 'pci', color: null },
        { id: 2, name: 'Vendor', slug: 'vendor', color: null },
    );
    store.nodes[uuid].tags = [{ id: 1, name: 'PCI', slug: 'pci', color: null }];

    const attachTag = vi.fn().mockResolvedValue(undefined);
    const detachTag = vi.fn().mockResolvedValue(undefined);
    Object.assign(store, { attachTag, detachTag });

    const wrapper = mount(DetailsTab, { props: { store, uuid } });

    return { wrapper, store, attachTag, detachTag };
}

let riskUuid: string;

beforeEach(() => {
    const store = makeComputedStore();
    riskUuid = Object.values(store.nodes).find((n) => n.type === 'risk')!.uuid;
});

describe('the labels row', () => {
    it('shows the labels this node carries', () => {
        const { wrapper } = render(riskUuid);

        expect(wrapper.get('[data-testid="labels"]').text()).toContain('PCI');
    });

    it('offers the rest of the assessment vocabulary, not the ones already on', async () => {
        const { wrapper } = render(riskUuid);

        await wrapper.get('[data-testid="labels"] input').trigger('focus');

        const options = wrapper.findAll('[role="option"]').map((o) => o.text());
        expect(options).toEqual(['Vendor']);
    });

    it('adds through the store, which is where the server decides the name', async () => {
        const { wrapper, attachTag } = render(riskUuid);
        const input = wrapper.get('[data-testid="labels"] input');

        await input.setValue('Payments');
        await input.trigger('keydown', { key: 'Enter' });

        expect(attachTag).toHaveBeenCalledWith(riskUuid, 'Payments');
    });

    it('removes by tag id, not by the name on the chip', async () => {
        const { wrapper, detachTag } = render(riskUuid);

        await wrapper.get('[aria-label="Remove label PCI"]').trigger('click');

        // Names are what a person reads; ids are what the route takes. A
        // remove keyed on the name would break the moment two assessments
        // spelled one label differently.
        expect(detachTag).toHaveBeenCalledWith(riskUuid, 1);
    });
});
