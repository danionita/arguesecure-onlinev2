import { beforeEach, describe, expect, it } from 'vitest';
import { useNodeModal } from '../../../resources/js/Components/NodeModal/useNodeModal';

/**
 * ui.md §7: the node modal "never becomes a route; deep-linking to a node
 * uses a query parameter so back/forward behave." useNodeModal() is a
 * module-level singleton (matching Components/UI/useToast.ts's own
 * convention), so every test resets both its state and the jsdom URL to
 * stay isolated.
 */
describe('useNodeModal', () => {
    beforeEach(() => {
        const { close } = useNodeModal();
        close();
        window.history.replaceState(null, '', '/assessments/abc');
    });

    it('starts closed', () => {
        const { openUuid } = useNodeModal();
        expect(openUuid.value).toBeNull();
    });

    it('open() sets the uuid and defaults to the details tab', () => {
        const { open, openUuid, activeTab } = useNodeModal();
        open('node-1');
        expect(openUuid.value).toBe('node-1');
        expect(activeTab.value).toBe('details');
    });

    it('open() accepts an explicit starting tab', () => {
        const { open, activeTab } = useNodeModal();
        open('node-1', 'links');
        expect(activeTab.value).toBe('links');
    });

    it('open() writes node and tab query parameters for deep-linking', () => {
        const { open } = useNodeModal();
        open('node-1', 'values');
        const params = new URL(window.location.href).searchParams;
        expect(params.get('node')).toBe('node-1');
        expect(params.get('tab')).toBe('values');
    });

    it('close() clears state and removes the query parameters', () => {
        const { open, close, openUuid } = useNodeModal();
        open('node-1');
        close();
        expect(openUuid.value).toBeNull();
        const params = new URL(window.location.href).searchParams;
        expect(params.has('node')).toBe(false);
        expect(params.has('tab')).toBe(false);
    });

    it('setTab() switches tabs on an already-open modal and updates the URL', () => {
        const { open, setTab, activeTab } = useNodeModal();
        open('node-1', 'details');
        setTab('references');
        expect(activeTab.value).toBe('references');
        const params = new URL(window.location.href).searchParams;
        expect(params.get('tab')).toBe('references');
    });

    it('restoreFromQuery() opens the node named in the URL on mount', () => {
        window.history.replaceState(
            null,
            '',
            '/assessments/abc?node=node-2&tab=links',
        );
        const { restoreFromQuery, openUuid, activeTab } = useNodeModal();
        restoreFromQuery();
        expect(openUuid.value).toBe('node-2');
        expect(activeTab.value).toBe('links');
    });

    it('restoreFromQuery() does nothing when no node is named', () => {
        const { restoreFromQuery, openUuid } = useNodeModal();
        restoreFromQuery();
        expect(openUuid.value).toBeNull();
    });
});
