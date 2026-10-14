/**
 * W85: The node card rendering tests for the chart view.
 *
 * Focus: the assistant trigger visibility with three separate gates, and that
 * clicking it actually does something — D50's complaint was a control that
 * was mounted once (on the tree card) and wired nowhere else. The wiring
 * tests here go through `useAssistantChildren.ts`, the composable extracted
 * from `NodeCard.vue` so both cards share one implementation and one D24
 * guardrail rather than the chart card growing a second copy.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import ChartCard from '../../../resources/js/Components/Chart/ChartCard.vue';
import { resetTemplateOptionsCache } from '../../../resources/js/Components/Node/useTemplateChildren';
import Icon from '../../../resources/js/Components/UI/Icon.vue';
import IconButton from '../../../resources/js/Components/UI/IconButton.vue';
import { DRAG_DROP_KEY } from '../../../resources/js/composables/useDragDrop';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    type AssessmentStore,
    type AssessmentStoreInit,
    type NodeRecord,
} from '../../../resources/js/stores/assessmentStore';

/**
 * ChartCard reads the assessment's calibration through `useScales()`, which
 * reads Inertia's page props.
 */
const SCALE_LEVELS = [
    {
        id: 12,
        scale_id: 1,
        kind: 'impact' as const,
        unit: 'currency',
        code: 'major',
        label: 'Major',
        ordinal: 4,
        lower_bound: 100_000,
        upper_bound: 1_000_000,
        representative: 316_228,
    },
];

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            scaleLevels: SCALE_LEVELS,
            scales: [
                {
                    id: 1,
                    kind: 'impact',
                    unit: 'currency',
                    is_system: false,
                },
            ],
        },
    }),
}));

const NOW = '2026-01-01T00:00:00.000000Z';

function node(over: Partial<NodeRecord>): NodeRecord {
    return {
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
        updated_at: NOW,
        ...over,
    };
}

/**
 * The three baselines differ only in `baseline` for this fixture — nothing
 * here exercises inherent/current/target diverging, so one shape parametrised
 * by name replaces three copy-pasted blocks (matching the `result()` helper
 * `tests/js/tree/NodeCard.spec.ts` already uses for the same reason).
 */
function result(baseline: 'inherent' | 'current' | 'target') {
    return {
        baseline,
        total: 50000,
        risks: [
            {
                uuid: 'risk1',
                ale: 50000,
                impactValue: 50000,
                impactLevelCode: null,
                annualProbability: 0.39,
                pairsValued: 1,
                pairsTotal: 1,
            },
        ],
        attacks: [],
        pairs: [],
        defences: [],
        completeness: {
            isPartial: false,
            pairsTotal: 1,
            pairsValued: 1,
            coverage: 1,
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

function makeInit(
    overrideAbilities: Partial<AssessmentStoreInit['abilities']> = {},
): AssessmentStoreInit {
    return {
        assessment: {
            uuid: 'assessment-uuid',
            currency: 'EUR',
            display_mode: 'mixed',
            amortization_years: 3,
            is_public: false,
        },
        nodes: [
            node({ uuid: 'root', type: 'root', title: 'Test Assessment' }),
            node({
                uuid: 'risk1',
                type: 'risk',
                title: 'Test Risk',
                impact_mode: 'quantitative',
                impact_amount: 50000,
            }),
        ],
        riskAttackLinks: [],
        attackDefenceLinks: [],
        results: {
            inherent: result('inherent'),
            current: result('current'),
            target: result('target'),
        },
        abilities: {
            comment: true,
            update: true,
            manage: false,
            transfer: false,
            ...overrideAbilities,
        },
    };
}

/**
 * Mount a ChartCard. Every gate is set to its "available" value **before**
 * `mount()` — `assistantAvailable` is a bare, non-reactive property on the
 * plain object `createAssessmentStore` returns (assessmentStore.ts:282-286),
 * so assigning it after mount changes nothing the already-rendered component
 * will ever see. A negative test that wants to isolate one gate overrides
 * only that gate's argument and leaves the rest at their available default,
 * so a passing test proves that one gate, not two at once.
 */
function mountCard(
    store: AssessmentStore,
    options: {
        assistantAvailable?: boolean;
        collapsed?: boolean;
        uuid?: string;
        pathKey?: string;
        hasChildren?: boolean;
    } = {},
) {
    store.assistantAvailable = options.assistantAvailable ?? true;

    return mount(ChartCard, {
        props: {
            uuid: options.uuid ?? 'risk1',
            pathKey: options.pathKey ?? 'root/risk1',
            hasChildren: options.hasChildren ?? false,
            collapsed: options.collapsed ?? false,
            selected: false,
            dragPayload: null,
        },
        global: {
            provide: {
                [ASSESSMENT_STORE_KEY as symbol]: store,
            },
        },
    });
}

function findAssistantButton(wrapper: ReturnType<typeof mount>) {
    return wrapper
        .findAllComponents(IconButton)
        .find((btn) => btn.attributes('data-testid') === 'ask-assistant');
}

beforeEach(() => {
    localStorage.clear();
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => `/${name}`);
    (window as unknown as { axios: unknown }).axios = {
        get: vi.fn().mockResolvedValue({ data: {} }),
        patch: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    };
});

/**
 * W85 item 19 / D50: the assistant trigger on the chart card.
 *
 * Three gates control its visibility:
 * 1. showAddChild — the node type must support children (risk or attack)
 * 2. abilities.update — the user must have edit permission
 * 3. store.assistantAvailable — the assistant must be configured
 *
 * The acceptance criterion requires three separate negative cases: the trigger
 * must be absent when **each one** is false in turn. A single "renders when
 * available" test would pass even if two gates were deleted, which is exactly
 * the failure this criterion exists to prevent — and exactly the failure
 * Finding 2 caught in the previous version of this file, where the
 * `showAddChild` case set `assistantAvailable` *after* `mount()` and so
 * tested nothing: `canEdit && store.assistantAvailable` was already false
 * from the unset default, and the test passed whether or not `showAddChild`
 * was even part of the guard.
 */
describe('ChartCard: assistant trigger visibility (W85 D50)', () => {
    it('renders the trigger when all three gates are true', () => {
        const store = createAssessmentStore(makeInit());
        const wrapper = mountCard(store);

        const assistantButton = findAssistantButton(wrapper);

        expect(assistantButton).toBeDefined();
        expect(assistantButton?.attributes('data-testid')).toBe(
            'ask-assistant',
        );
    });

    /**
     * MUTATION TEST 1: showAddChild gate.
     *
     * Mounts a *defence* node — `hasChildType('defence') === false`, so
     * `showAddChild` is false — while `canEdit` and `store.assistantAvailable`
     * are both true, isolating this one gate.
     *
     * Actually run: delete `showAddChild &&` from ChartCard.vue's trigger
     * guard, re-run `npx vitest run tests/js/chart/ChartCard.spec.ts`. See the
     * task report for the command and the red output.
     */
    it('does not render the trigger when showAddChild is false', () => {
        const init = makeInit();
        init.nodes.push(
            node({
                uuid: 'defence1',
                type: 'defence',
                title: 'Test Defence',
                status: 'todo',
            }),
        );

        const store = createAssessmentStore(init);
        const wrapper = mountCard(store, {
            uuid: 'defence1',
            pathKey: 'root/risk1/attack1/defence1',
            assistantAvailable: true,
        });

        expect(findAssistantButton(wrapper)).toBeUndefined();
    });

    /**
     * MUTATION TEST 2: abilities.update gate.
     *
     * Actually run: delete `&& canEdit` from ChartCard.vue's trigger guard,
     * re-run the suite. See the task report.
     */
    it('does not render the trigger when abilities.update is false', () => {
        const store = createAssessmentStore(makeInit({ update: false }));
        const wrapper = mountCard(store);

        expect(findAssistantButton(wrapper)).toBeUndefined();
    });

    /**
     * MUTATION TEST 3: store.assistantAvailable gate.
     *
     * Actually run: delete `&& store.assistantAvailable` from ChartCard.vue's
     * trigger guard, re-run the suite. See the task report.
     */
    it('does not render the trigger when assistantAvailable is false', () => {
        const store = createAssessmentStore(makeInit());
        const wrapper = mountCard(store, { assistantAvailable: false });

        expect(findAssistantButton(wrapper)).toBeUndefined();
    });

    /**
     * The label must match the tree card's exactly (D50: "the same trigger"),
     * which is what caught the previous version's hardcoded "Suggest
     * children" against NodeCard.vue's `` `Suggest ${assistantChildType}s` ``.
     */
    it('has the correct testid, icon and label', () => {
        const store = createAssessmentStore(makeInit());
        const wrapper = mountCard(store);

        const assistantButton = findAssistantButton(wrapper);

        expect(assistantButton).toBeDefined();
        expect(assistantButton?.props('icon')).toBe('square-plus');
        expect(assistantButton?.props('label')).toBe('Suggest attacks');
    });
});

/**
 * W85 item 19 / D50, Finding 1: the trigger must actually do something. The
 * prior version emitted `assistantRequest` to a parent (`ChartNode.vue`) that
 * never listened for it — clicking it did nothing. The fix pulls the tree
 * card's assistant logic into `useAssistantChildren.ts` and has both cards
 * call it directly, so this suite asserts the click reaches the store and
 * that nothing lands in the graph before a person accepts it (D24).
 */
describe('ChartCard: assistant wiring (W85 D50 Finding 1)', () => {
    it('clicking the trigger asks the store for suggestions', async () => {
        const store = createAssessmentStore(makeInit());
        const suggestChildren = vi
            .spyOn(store, 'suggestChildren')
            .mockResolvedValue({
                childType: 'attack',
                suggestions: [
                    {
                        title: 'Phishing',
                        description: 'A social-engineering attack.',
                    },
                ],
                cancelled: false,
            });

        const wrapper = mountCard(store);
        await findAssistantButton(wrapper)?.trigger('click');
        await flushPromises();

        // The card asks for a list to pick from; only the new-node form asks
        // for one (2.5.1).
        expect(suggestChildren).toHaveBeenCalledWith('risk1', 5);
        expect(wrapper.find('[data-testid="suggestions-panel"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-testid="suggestion"]').text()).toContain(
            'Phishing',
        );
    });

    it('does not add the suggestion to the graph until it is accepted', async () => {
        const store = createAssessmentStore(makeInit());
        vi.spyOn(store, 'suggestChildren').mockResolvedValue({
            childType: 'attack',
            suggestions: [
                {
                    title: 'Phishing',
                    description: 'A social-engineering attack.',
                },
            ],
            cancelled: false,
        });
        const addChild = vi
            .spyOn(store, 'addChild')
            .mockResolvedValue('created1');

        const wrapper = mountCard(store);
        await findAssistantButton(wrapper)?.trigger('click');
        await flushPromises();

        // The suggestion is on screen but the guardrail (D24) means it has
        // not touched the graph yet.
        expect(addChild).not.toHaveBeenCalled();

        await wrapper
            .find('[data-testid="accept-suggestion"]')
            .trigger('click');
        await flushPromises();

        expect(addChild).toHaveBeenCalledWith('risk', 'risk1', {
            title: 'Phishing',
            description: 'A social-engineering attack.',
        });
        expect(wrapper.find('[data-testid="suggestion-added"]').exists()).toBe(
            true,
        );
    });

    it('shows the server error when the assistant cannot be reached', async () => {
        const store = createAssessmentStore(makeInit());
        vi.spyOn(store, 'suggestChildren').mockRejectedValue({
            response: { data: { message: 'The assistant is switched off.' } },
        });

        const wrapper = mountCard(store);
        await findAssistantButton(wrapper)?.trigger('click');
        await flushPromises();

        expect(
            wrapper.find('[data-testid="assistant-suggest-error"]').text(),
        ).toBe('The assistant is switched off.');
    });
});

/**
 * W85 item 1 / D31: The occurrence tag — the attack counter in
 * AppearsUnderPopover now renders as an icon + number chip, matching the
 * defence counter's appearance on both ChartCard and NodeCard.
 */
describe('ChartCard: occurrence tag (W85 item 1)', () => {
    /**
     * Create a test fixture with attack(s) linked to multiple risks so we can
     * verify the AppearsUnderPopover trigger appears with the correct appearance.
     * The occurrence count must be > 1 to show the trigger.
     */
    function makeInitWithAttack(): AssessmentStoreInit {
        const init = makeInit();
        init.nodes.push(
            node({
                uuid: 'risk2',
                type: 'risk',
                title: 'Test Risk 2',
                position: 1,
                impact_mode: 'quantitative',
                impact_amount: 30000,
            }),
            node({
                uuid: 'attack1',
                type: 'attack',
                title: 'Test Attack',
                likelihood_aro: 0.5,
            }),
        );
        init.riskAttackLinks.push(
            { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
            { risk_uuid: 'risk2', attack_uuid: 'attack1', position: 0 },
        );
        return init;
    }

    it('renders the occurrence tag trigger with shuffle icon and number for attacks', () => {
        const store = createAssessmentStore(makeInitWithAttack());
        const wrapper = mount(ChartCard, {
            props: {
                uuid: 'attack1',
                pathKey: 'root/risk1/attack1',
                hasChildren: false,
                collapsed: false,
                selected: false,
                dragPayload: null,
            },
            global: {
                provide: {
                    [ASSESSMENT_STORE_KEY as symbol]: store,
                },
            },
        });

        // Find the AppearsUnderPopover trigger button
        const appearsUnderButton = wrapper.find(
            '[data-testid="appears-under-trigger"]',
        );

        expect(appearsUnderButton.exists()).toBe(true);

        // Check that it contains the shuffle icon
        const icon = appearsUnderButton.findComponent(Icon);
        expect(icon.exists()).toBe(true);
        expect(icon.props('name')).toBe('shuffle');
        expect(icon.props('size')).toBe(10);

        // Check that it contains the occurrence count number
        // The attack appears under 2 risks in the fixture
        const text = appearsUnderButton.text();
        expect(text).toMatch(/^2$/);

        // Check that the accessible name is preserved
        const ariaLabel = appearsUnderButton.attributes('aria-label');
        expect(ariaLabel).toContain('Appears under');
        expect(ariaLabel).toContain('2');
        expect(ariaLabel).toContain('risks');
    });

    it('does not render the occurrence tag trigger for non-attack types', () => {
        const store = createAssessmentStore(makeInit());
        const wrapper = mount(ChartCard, {
            props: {
                uuid: 'risk1',
                pathKey: 'root/risk1',
                hasChildren: false,
                collapsed: false,
                selected: false,
                dragPayload: null,
            },
            global: {
                provide: {
                    [ASSESSMENT_STORE_KEY as symbol]: store,
                },
            },
        });

        // AppearsUnderPopover should not render for risk nodes
        const appearsUnderButton = wrapper.find(
            '[data-testid="appears-under-trigger"]',
        );

        expect(appearsUnderButton.exists()).toBe(false);
    });

    it('trigger renders at the same size as the defence counter', () => {
        const store = createAssessmentStore(makeInitWithAttack());
        const wrapper = mount(ChartCard, {
            props: {
                uuid: 'attack1',
                pathKey: 'root/risk1/attack1',
                hasChildren: false,
                collapsed: false,
                selected: false,
                dragPayload: null,
            },
            global: {
                provide: {
                    [ASSESSMENT_STORE_KEY as symbol]: store,
                },
            },
        });

        const button = wrapper.find('[data-testid="appears-under-trigger"]');

        // Check text size matches defence counter (text-[10px])
        const classes = button.classes();
        expect(classes).toContain('text-[10px]');

        // Check gap size matches defence counter (gap-0.5)
        expect(classes).toContain('gap-0.5');

        // Check icon size is 10 (to match defence counter)
        const icon = button.findComponent(Icon);
        expect(icon.props('size')).toBe(10);
    });
});

/**
 * 2.5.1: add from a template. The card half of the feature — the picker's
 * own behaviour is in tests/js/templates/. What only the chart can get wrong
 * is that the picker floats *inside* the card: a click in it must not select
 * the card, and a press-and-move on it must not pick the card up.
 */
describe('ChartCard: add from a template (2.5.1)', () => {
    function withTemplates() {
        resetTemplateOptionsCache();
        (window as unknown as { axios: { get: unknown } }).axios.get = vi
            .fn()
            .mockImplementation((url: string) =>
                Promise.resolve(
                    url === '/templates.options'
                        ? {
                              data: {
                                  templates: [
                                      {
                                          uuid: 'tpl1',
                                          title: 'ISO/IEC 27001',
                                          counts: {
                                              risk: 3,
                                              attack: 4,
                                              defence: 93,
                                          },
                                      },
                                  ],
                              },
                          }
                        : { data: { nodes: [] } },
                ),
            );
    }

    function mountDraggable(store: AssessmentStore) {
        return mount(ChartCard, {
            props: {
                uuid: 'risk1',
                pathKey: 'root/risk1',
                hasChildren: false,
                collapsed: false,
                selected: false,
                dragPayload: {
                    uuid: 'risk1',
                    path: 'root/risk1',
                    scope: 'nodes',
                    parentUuid: null,
                    accepts: 'risk_attack',
                },
            },
            global: {
                provide: {
                    [ASSESSMENT_STORE_KEY as symbol]: store,
                    [DRAG_DROP_KEY as symbol]: {
                        dragProps: () => ({ draggable: true }),
                        dropTarget: ref(null),
                        draggingPath: ref(null),
                    },
                },
            },
        });
    }

    it('offers the trigger to an editor, labelled with the child type', () => {
        const wrapper = mountCard(createAssessmentStore(makeInit()));
        const trigger = wrapper
            .findAllComponents(IconButton)
            .find((b) => b.attributes('data-testid') === 'add-from-template');

        expect(trigger?.props('label')).toBe('Add attacks from a template');
    });

    it('offers nothing to someone who cannot edit', () => {
        const wrapper = mountCard(
            createAssessmentStore(makeInit({ update: false })),
        );

        expect(wrapper.find('[data-testid="add-from-template"]').exists()).toBe(
            false,
        );
    });

    it('opens the picker, and a click inside it does not select the card', async () => {
        withTemplates();
        const wrapper = mountCard(createAssessmentStore(makeInit()));

        await wrapper
            .find('[data-testid="add-from-template"]')
            .trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="template-picker"]').exists()).toBe(
            true,
        );
        // The trigger is on the card, so pressing it selects the card like
        // any other click on it does; only clicks inside the picker must not.
        const selectsBefore = wrapper.emitted('select')?.length ?? 0;
        await wrapper.find('[data-testid="template-option"]').trigger('click');
        await flushPromises();
        await wrapper
            .find('[data-testid="template-picker-filter"]')
            .trigger('click');
        expect(wrapper.emitted('select')?.length ?? 0).toBe(selectsBefore);
    });

    it('is not a drag source while the picker is open, and is again once it closes', async () => {
        withTemplates();
        const wrapper = mountDraggable(createAssessmentStore(makeInit()));
        expect(wrapper.attributes('draggable')).toBe('true');

        await wrapper
            .find('[data-testid="add-from-template"]')
            .trigger('click');
        await flushPromises();
        expect(wrapper.attributes('draggable')).toBe('false');

        await wrapper
            .find('[data-testid="template-picker-dismiss"]')
            .trigger('click');
        expect(wrapper.attributes('draggable')).toBe('true');
    });
});

/**
 * W105: both floating panels sit inside the chart's scroll container, which
 * clips them. Below the bottom tier of a chart taller than the window a panel
 * opened out of sight, so the card scrolls it into view — when it appears,
 * and again when its content grows. jsdom has no layout and no
 * `scrollIntoView`, so this asserts the call and its arguments; that the
 * panel then fits is e2e scenario 15's to show.
 */
describe('ChartCard: floating panels scroll into view (W105)', () => {
    let scrolled: { el: Element; options: unknown }[];

    beforeEach(() => {
        scrolled = [];
        Element.prototype.scrollIntoView = function (
            this: Element,
            options?: unknown,
        ) {
            scrolled.push({ el: this, options });
        };
    });

    it('reveals the template picker when it opens and when a template is chosen', async () => {
        resetTemplateOptionsCache();
        (window as unknown as { axios: { get: unknown } }).axios.get = vi
            .fn()
            .mockImplementation((url: string) =>
                Promise.resolve(
                    url === '/templates.options'
                        ? {
                              data: {
                                  templates: [
                                      {
                                          uuid: 'tpl1',
                                          title: 'ISO/IEC 27001',
                                          counts: {
                                              risk: 3,
                                              attack: 4,
                                              defence: 93,
                                          },
                                      },
                                  ],
                              },
                          }
                        : { data: { nodes: [] } },
                ),
            );
        const wrapper = mountCard(createAssessmentStore(makeInit()));

        await wrapper
            .find('[data-testid="add-from-template"]')
            .trigger('click');
        await flushPromises();

        const panel = wrapper.find('[data-testid="template-picker"]').element
            .parentElement!;
        expect(scrolled.length).toBeGreaterThan(0);
        expect(scrolled.every((s) => s.el === panel)).toBe(true);
        expect(scrolled.at(-1)!.options).toEqual({
            block: 'nearest',
            inline: 'nearest',
        });

        const before = scrolled.length;
        await wrapper.find('[data-testid="template-option"]').trigger('click');
        await flushPromises();
        expect(scrolled.length).toBeGreaterThan(before);
        expect(scrolled.at(-1)!.el).toBe(panel);
    });

    it('reveals the suggestions panel when the suggestions arrive', async () => {
        const store = createAssessmentStore(makeInit());
        vi.spyOn(store, 'suggestChildren').mockResolvedValue({
            childType: 'attack',
            suggestions: [{ title: 'Phishing', description: null }],
            cancelled: false,
        });
        const wrapper = mountCard(store);

        await findAssistantButton(wrapper)?.trigger('click');
        await flushPromises();

        const panel = wrapper.find('[data-testid="suggestions-panel"]').element;
        const last = scrolled.at(-1)!;
        expect(last.el.contains(panel)).toBe(true);
        expect(last.options).toEqual({ block: 'nearest', inline: 'nearest' });
    });
});
