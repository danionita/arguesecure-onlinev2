import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import {
    useKeyboardOutline,
    type UseKeyboardOutline,
} from '../../../resources/js/composables/useKeyboardOutline';
import {
    createAssessmentStore,
    type AssessmentStoreInit,
    type AttackDefenceLink,
    type NodeRecord,
    type RiskAttackLink,
} from '../../../resources/js/stores/assessmentStore';

/**
 * Same DAG fixture shape as tests/js/stores/assessmentStore.spec.ts (one
 * root, two risks sharing one attack, one defence) — reused here because
 * the flattened "visible list" this composable computes must walk exactly
 * the same store calls TreeNode.vue's own render pass makes.
 */
function makeInit(): AssessmentStoreInit {
    const now = '2026-01-01T00:00:00.000000Z';
    const node = (over: Partial<NodeRecord>): NodeRecord => ({
        uuid: 'x',
        type: 'risk',
        title: '',
        description: null,
        position: 0,
        impact_mode: null,
        impact_amount: null,
        impact_level_id: null,
        likelihood_mode: null,
        likelihood_aro: null,
        likelihood_level_id: null,
        is_transfer: false,
        status: null,
        capex_amount: null,
        opex_annual_amount: null,
        tags: [],
        updated_at: now,
        ...over,
    });

    const nodes: NodeRecord[] = [
        node({ uuid: 'root', type: 'root', title: 'Assessment' }),
        node({ uuid: 'riskA', type: 'risk', title: 'Risk A', position: 0 }),
        node({ uuid: 'riskB', type: 'risk', title: 'Risk B', position: 1 }),
        node({ uuid: 'attack1', type: 'attack', title: 'Phishing' }),
        node({ uuid: 'defence1', type: 'defence', title: 'MFA' }),
    ];

    const riskAttackLinks: RiskAttackLink[] = [
        { risk_uuid: 'riskA', attack_uuid: 'attack1', position: 0 },
        { risk_uuid: 'riskB', attack_uuid: 'attack1', position: 0 },
    ];

    const attackDefenceLinks: AttackDefenceLink[] = [
        {
            attack_uuid: 'attack1',
            defence_uuid: 'defence1',
            position: 0,
            strength_mode: null,
            strength_fraction: null,
            strength_level_id: null,
            reduction_target: null,
        },
    ];

    return {
        assessment: {
            uuid: 'assessment-uuid',
            currency: 'EUR',
            display_mode: 'mixed',
            amortization_years: 3,
            is_public: false,
        },
        nodes,
        riskAttackLinks,
        attackDefenceLinks,
        results: {
            inherent: emptyResult('inherent'),
            current: emptyResult('current'),
            target: emptyResult('target'),
        },
        abilities: {
            comment: true,
            update: true,
            manage: false,
            transfer: false,
        },
    };
}

function emptyResult(baseline: 'inherent' | 'current' | 'target') {
    return {
        baseline,
        total: 0,
        risks: [],
        attacks: [],
        pairs: [],
        defences: [],
        completeness: {
            isPartial: false,
            pairsTotal: 0,
            pairsValued: 0,
            coverage: 0,
            risksMissingImpact: [],
            attacksMissingLikelihood: [],
            linksMissingStrength: [],
            risksWithoutAttacks: [],
            orphanAttacks: [],
            orphanDefences: [],
            sharedAttacks: [],
            implausibleStrength: [],
        },
        warnings: [],
    };
}

beforeEach(() => {
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn(
            (name: string, params: unknown) =>
                `/${name}/${JSON.stringify(params)}`,
        );
    (window as unknown as { axios: unknown }).axios = {
        patch: vi.fn().mockResolvedValue({ data: { node: {} } }),
        post: vi.fn().mockResolvedValue({ data: { node: { uuid: 'new' } } }),
        delete: vi.fn().mockResolvedValue({}),
    };
});

/** Mounts the composable inside a real component so its onMounted/onUnmounted lifecycle hooks run. */
function mountOutline() {
    const store = createAssessmentStore(makeInit());
    let outline!: UseKeyboardOutline;
    const container = ref<HTMLElement | null>(null);
    const onAddChild = vi.fn();

    const wrapper = mount(
        defineComponent({
            setup() {
                outline = useKeyboardOutline({
                    store,
                    container,
                    onAddChild,
                });
                return () => h('div', { ref: container });
            },
        }),
        { attachTo: document.body },
    );

    return { store, outline, wrapper, onAddChild };
}

describe('useKeyboardOutline: visiblePaths', () => {
    it('walks root -> risks -> attacks -> defences in the same order the store exposes', () => {
        const { outline } = mountOutline();

        expect(outline.visiblePaths()).toEqual([
            'root',
            'root/riskA',
            'root/riskA/attack1',
            'root/riskA/attack1/defence1',
            'root/riskB',
            'root/riskB/attack1',
            'root/riskB/attack1/defence1',
        ]);
    });

    it('skips descendants of a collapsed occurrence, matching what TreeNode.vue would render', () => {
        const { store, outline } = mountOutline();

        store.toggleExpanded('root/riskA');

        expect(outline.visiblePaths()).toEqual([
            'root',
            'root/riskA',
            'root/riskB',
            'root/riskB/attack1',
            'root/riskB/attack1/defence1',
        ]);
    });
});

describe('useKeyboardOutline: focus movement', () => {
    it('ArrowDown/ArrowUp move focus through the flattened list, clamped at the ends', () => {
        const { outline } = mountOutline();

        outline.setFocus('root');
        outline.handleKeydown(
            new KeyboardEvent('keydown', { key: 'ArrowDown' }),
        );
        expect(outline.focusedPath.value).toBe('root/riskA');

        outline.handleKeydown(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
        expect(outline.focusedPath.value).toBe('root');

        outline.handleKeydown(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
        expect(outline.focusedPath.value).toBe('root'); // clamped, not wrapped
    });

    it('ArrowLeft collapses an expanded node, then moves to the parent once already collapsed', () => {
        const { store, outline } = mountOutline();

        outline.setFocus('root/riskA');
        expect(store.isExpanded('root/riskA')).toBe(true);

        outline.handleKeydown(
            new KeyboardEvent('keydown', { key: 'ArrowLeft' }),
        );
        expect(store.isExpanded('root/riskA')).toBe(false);
        expect(outline.focusedPath.value).toBe('root/riskA');

        outline.handleKeydown(
            new KeyboardEvent('keydown', { key: 'ArrowLeft' }),
        );
        expect(outline.focusedPath.value).toBe('root');
    });

    it('Escape clears focus', () => {
        const { outline } = mountOutline();
        outline.setFocus('root/riskA');

        outline.handleKeydown(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(outline.focusedPath.value).toBeNull();
    });
});

describe('useKeyboardOutline: structural actions', () => {
    // 2.5.1: adding opens a draft in the modal; nothing is POSTed until it
    // is confirmed there, so the keyboard hands over the parent and stops.
    it('Enter on a risk opens a draft sibling risk under the root, not a child of the risk', () => {
        const { outline, onAddChild } = mountOutline();
        outline.setFocus('root/riskA');

        outline.handleKeydown(new KeyboardEvent('keydown', { key: 'Enter' }));

        expect(onAddChild).toHaveBeenCalledWith('root', 'root', 'root');
        expect(window.axios.post).not.toHaveBeenCalled();
    });

    it('Tab on a risk opens a draft child attack', () => {
        const { outline, onAddChild } = mountOutline();
        outline.setFocus('root/riskA');

        outline.handleKeydown(new KeyboardEvent('keydown', { key: 'Tab' }));

        expect(onAddChild).toHaveBeenCalledWith('risk', 'riskA', 'root/riskA');
        expect(window.axios.post).not.toHaveBeenCalled();
    });

    it('ignores Enter and Tab pressed inside a dialog (2.5.1)', () => {
        const { outline, onAddChild } = mountOutline();
        outline.setFocus('root/riskA');

        const dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        const button = document.createElement('button');
        dialog.appendChild(button);
        document.body.appendChild(dialog);

        for (const key of ['Enter', 'Tab']) {
            const event = new KeyboardEvent('keydown', { key, bubbles: true });
            Object.defineProperty(event, 'target', { value: button });
            outline.handleKeydown(event);
        }

        expect(onAddChild).not.toHaveBeenCalled();
        dialog.remove();
    });

    it('Tab is a no-op on a defence, which has no childType', async () => {
        const { outline, onAddChild } = mountOutline();
        outline.setFocus('root/riskA/attack1/defence1');

        outline.handleKeydown(new KeyboardEvent('keydown', { key: 'Tab' }));
        await Promise.resolve();

        expect(window.axios.post).not.toHaveBeenCalled();
        expect(onAddChild).not.toHaveBeenCalled();
    });

    it('Delete removes the focused node via the store', async () => {
        const { outline } = mountOutline();
        outline.setFocus('root/riskA/attack1/defence1');

        outline.handleKeydown(new KeyboardEvent('keydown', { key: 'Delete' }));
        await Promise.resolve();

        expect(window.axios.delete).toHaveBeenCalledWith(
            expect.stringContaining('assessments.nodes.destroy'),
        );
    });

    it('Space opens the node modal for the focused occurrence', () => {
        const onOpenModal = vi.fn();
        const store = createAssessmentStore(makeInit());
        const container = ref<HTMLElement | null>(null);
        let outline!: UseKeyboardOutline;

        mount(
            defineComponent({
                setup() {
                    outline = useKeyboardOutline({
                        store,
                        container,
                        onOpenModal,
                    });
                    return () => h('div', { ref: container });
                },
            }),
            { attachTo: document.body },
        );

        outline.setFocus('root/riskA');
        outline.handleKeydown(new KeyboardEvent('keydown', { key: ' ' }));

        expect(onOpenModal).toHaveBeenCalledWith('riskA', 'root/riskA');
    });
});

describe('useKeyboardOutline: global bindings', () => {
    it('Ctrl+Z triggers undo, Shift+Ctrl+Z triggers redo, regardless of focus', () => {
        const onUndo = vi.fn();
        const onRedo = vi.fn();
        const store = createAssessmentStore(makeInit());
        const container = ref<HTMLElement | null>(null);
        let outline!: UseKeyboardOutline;

        mount(
            defineComponent({
                setup() {
                    outline = useKeyboardOutline({
                        store,
                        container,
                        onUndo,
                        onRedo,
                    });
                    return () => h('div', { ref: container });
                },
            }),
            { attachTo: document.body },
        );

        outline.handleKeydown(
            new KeyboardEvent('keydown', { key: 'z', ctrlKey: true }),
        );
        expect(onUndo).toHaveBeenCalledTimes(1);
        expect(onRedo).not.toHaveBeenCalled();

        outline.handleKeydown(
            new KeyboardEvent('keydown', {
                key: 'z',
                ctrlKey: true,
                shiftKey: true,
            }),
        );
        expect(onRedo).toHaveBeenCalledTimes(1);
    });

    it('"/" focuses search unless the user is typing in a field', () => {
        const onFocusSearch = vi.fn();
        const store = createAssessmentStore(makeInit());
        const container = ref<HTMLElement | null>(null);
        let outline!: UseKeyboardOutline;

        mount(
            defineComponent({
                setup() {
                    outline = useKeyboardOutline({
                        store,
                        container,
                        onFocusSearch,
                    });
                    return () => h('div', { ref: container });
                },
            }),
            { attachTo: document.body },
        );

        outline.handleKeydown(new KeyboardEvent('keydown', { key: '/' }));
        expect(onFocusSearch).toHaveBeenCalledTimes(1);

        const input = document.createElement('input');
        document.body.appendChild(input);
        const event = new KeyboardEvent('keydown', { key: '/' });
        Object.defineProperty(event, 'target', { value: input });
        outline.handleKeydown(event);
        expect(onFocusSearch).toHaveBeenCalledTimes(1); // still 1, not 2
        input.remove();
    });
});
