/**
 * The node card is the screen the tool lives or dies by (ui.md §3), and until
 * W29 nothing tested what it renders — `tests/js` covered composables, the
 * store and the engine, all of which can be perfectly correct while the card
 * shows the reader three unlabelled numbers from two different baselines.
 *
 * These tests are about legibility, so they assert on text and on the props
 * that carry the explanations (Tooltip/ValueChip), not on classes.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetTemplateOptionsCache } from '../../../resources/js/Components/Node/useTemplateChildren';
import { useNodeModal } from '../../../resources/js/Components/NodeModal/useNodeModal';
import NodeCard from '../../../resources/js/Components/Tree/NodeCard.vue';
import ValueChip from '../../../resources/js/Components/Tree/ValueChip.vue';
import Bar from '../../../resources/js/Components/UI/Bar.vue';
import Icon from '../../../resources/js/Components/UI/Icon.vue';
import StatusPill from '../../../resources/js/Components/UI/StatusPill.vue';
import Tooltip from '../../../resources/js/Components/UI/Tooltip.vue';
import {
    createEditorView,
    EDITOR_VIEW_KEY,
} from '../../../resources/js/composables/useEditorView';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    occurrencePath,
    type AssessmentStore,
    type AssessmentStoreInit,
    type AttackDefenceLink,
    type NodeRecord,
} from '../../../resources/js/stores/assessmentStore';

/*
 * One strength band, so a qualitative edge has a label to resolve to. Only
 * `usePage` is replaced; the rest of the module is the real one. No other
 * test here names a level id, so this changes nothing for them.
 */
vi.mock('@inertiajs/vue3', async (original) => ({
    ...(await original<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({
        props: {
            scaleLevels: [
                {
                    id: 42,
                    scale_id: 3,
                    kind: 'strength',
                    unit: 'fraction',
                    code: 'limited',
                    label: 'Limited',
                    ordinal: 2,
                    lower_bound: 0.2,
                    upper_bound: 0.5,
                    representative: 0.35,
                },
            ],
            scales: [],
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

const edge: AttackDefenceLink = {
    attack_uuid: 'attack1',
    defence_uuid: 'defence1',
    position: 0,
    strength_mode: 'quantitative',
    strength_fraction: 0.4,
    strength_level_id: null,
    reduction_target: 'likelihood',
};

/**
 * One risk, one attack under it, one *planned* defence under that attack —
 * the shape where current and target genuinely differ, which is the whole
 * point of the selector this package adds.
 */
function makeInit(): AssessmentStoreInit {
    return {
        assessment: {
            uuid: 'assessment-uuid',
            currency: 'EUR',
            display_mode: 'mixed',
            amortization_years: 3,
            is_public: false,
        },
        nodes: [
            node({ uuid: 'root', type: 'root', title: 'Web shop' }),
            node({
                uuid: 'risk1',
                type: 'risk',
                title: 'Customer data breach',
                impact_mode: 'quantitative',
                impact_amount: 50000,
            }),
            node({
                uuid: 'attack1',
                type: 'attack',
                title: 'Phishing',
                likelihood_mode: 'quantitative',
                likelihood_aro: 0.5,
            }),
            node({
                uuid: 'defence1',
                type: 'defence',
                title: 'MFA',
                status: 'todo',
                opex_annual_amount: 1200,
            }),
        ],
        riskAttackLinks: [
            { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
        ],
        attackDefenceLinks: [edge],
        results: {
            // W36: inherent was 25,000 here too, identical to current. Three
            // totals now render side by side, and a fixture where two of them
            // match would let a swapped pair pass unnoticed. Distinct values,
            // descending, as a real assessment's would be.
            inherent: result('inherent', 50000, 0.5, null),
            current: result('current', 25000, 0.5, null),
            target: result('target', 15000, 0.3, 10000),
        },
        abilities: {
            comment: true,
            update: true,
            manage: false,
            transfer: false,
        },
    };
}

function result(
    baseline: 'inherent' | 'current' | 'target',
    ale: number,
    aroResidual: number,
    defenceSaving: number | null,
) {
    return {
        baseline,
        total: ale,
        risks: [
            {
                uuid: 'risk1',
                ale,
                impactValue: 50000,
                impactLevelCode: null,
                annualProbability: 0.39,
                pairsValued: 1,
                pairsTotal: 1,
            },
        ],
        attacks: [
            {
                uuid: 'attack1',
                ale,
                aroBase: 0.5,
                aroResidual,
                aroLevelCode: null,
                factor: 1,
                riskCount: 1,
            },
        ],
        pairs: [],
        defences:
            defenceSaving === null
                ? []
                : [
                      {
                          uuid: 'defence1',
                          status: 'todo',
                          mc: defenceSaving,
                          mcAdd: defenceSaving,
                          attribution: 1,
                          annualCost: 1200,
                          cbr: 0.12,
                          rosi: 7.3,
                          netBenefit: 8800,
                          costBucket: 'priced' as const,
                      },
                  ],
        completeness: {
            isPartial: false,
            pairsTotal: 4,
            pairsValued: 3,
            coverage: 0.75,
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

function mountCard(
    store: AssessmentStore,
    options: {
        uuid: string;
        ancestors?: string[];
        edge?: AttackDefenceLink;
        withView?: boolean;
        expanded?: boolean;
        childCount?: number;
    },
) {
    const provide: Record<symbol, unknown> = {
        [ASSESSMENT_STORE_KEY as symbol]: store,
    };

    let view: ReturnType<typeof createEditorView> | null = null;
    if (options.withView !== false) {
        view = createEditorView(store, {
            assessmentUuid: 'assessment-uuid',
            userId: 7,
        });
        provide[EDITOR_VIEW_KEY as symbol] = view;
    }

    const wrapper = mount(NodeCard, {
        props: {
            uuid: options.uuid,
            pathKey: occurrencePath(...(options.ancestors ?? []), options.uuid),
            edge: options.edge,
            expanded: options.expanded ?? false,
            hasChildren: (options.childCount ?? 0) > 0,
            childCount: options.childCount,
        },
        global: { provide },
    });

    return { wrapper, view };
}

/** The labelled figures on the card, as "Label: value" pairs. */
function labelledValues(wrapper: ReturnType<typeof mountCard>['wrapper']) {
    return wrapper.findAllComponents(ValueChip).map((chip) => {
        const label = chip.props('label') as string;
        // The rendered chip already contains its own label; strip it so the
        // assertions below read as the pair, not as the label twice.
        return `${label}: ${chip.text().replace(`${label}:`, '').trim()}`;
    });
}

function tooltipFor(
    wrapper: ReturnType<typeof mountCard>['wrapper'],
    label: string,
): string {
    const chip = wrapper
        .findAllComponents(ValueChip)
        .find((c) => c.props('label') === label);

    return chip ? (chip.props('tooltip') as string) : '';
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
 * W36 — the list card carries what a person entered, and the engine's output
 * only at the root.
 *
 * These are exact-equality assertions on the whole labelled set rather than
 * "does not contain 'Annual loss'". The package's content is a *removal*, and
 * a containment check would stay green if a figure came back under a new
 * label, which is precisely how one would come back.
 */
describe('the card shows the values a human entered', () => {
    it('gives a risk its impact and nothing computed', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        expect(labelledValues(wrapper)).toEqual(['Impact: €50,000']);
    });

    it('gives an attack its likelihood, as a rate per year, and nothing computed', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'attack1',
            ancestors: ['root', 'risk1'],
        });

        expect(labelledValues(wrapper)).toEqual(['Likelihood: 0.50/yr']);
    });

    it('gives a defence its status and its strength, and no money at all', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
            edge,
        });

        // W70: the last row that rendered a bare sentence chip instead of a
        // labelled value. Exact equality, like every other assertion in this
        // describe: a containment check would stay green if the cost or the
        // saving came back beside it.
        expect(labelledValues(wrapper)).toEqual(['Reduces likelihood: 40%']);
        expect(wrapper.text()).not.toContain('€');
    });

    it('carries no contribution or completeness bar on a risk', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        expect(wrapper.findAllComponents(Bar)).toHaveLength(0);
    });
});

describe('the root card, which is the exception', () => {
    it('shows all three totals at once, named for a reader rather than for the engine', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, { uuid: 'root' });

        // The comparison the removed selector kept asking people to make one
        // baseline at a time (PLAN-2.2.md D8).
        expect(labelledValues(wrapper)).toEqual([
            'Base: €50,000',
            'Current: €25,000(50% lower)',
            'Target ALE: €15,000(70% lower)',
        ]);
    });

    /**
     * CLAUDE.md: computed results "render as currency with a reduction
     * percentage". W36 moved these three figures off the docked panel onto
     * this card and dropped the percentage on the way — nothing here noticed,
     * because the assertion above pinned the currency and the currency was
     * still right. Scenario 6 caught it the first time the e2e suite was run
     * afterwards, which was eight packages later.
     */
    it('says how far below the baseline each figure is, not only what it is', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, { uuid: 'root' });

        // 50,000 → 25,000 → 15,000.
        expect(wrapper.text()).toContain('50% lower');
        expect(wrapper.text()).toContain('70% lower');
    });

    it('offers no reduction against the baseline itself', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, { uuid: 'root' });

        // Base is what the other two are measured against, so "0% lower" on
        // it would be noise rather than a fact — and a reader scanning three
        // percentages would take it for a fourth figure.
        const base = wrapper
            .findAllComponents(ValueChip)
            .find((chip) => chip.props('label') === 'Base');

        expect(base?.text()).not.toContain('lower');
    });

    it('explains that Base is the inherent figure, so the two words are one idea', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, { uuid: 'root' });

        expect(tooltipFor(wrapper, 'Base')).toMatch(/inherent/i);
    });

    it('announces the totals politely: a collaborator changes them without this reader acting', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, { uuid: 'root' });

        const region = wrapper.get('[data-testid="assessment-totals"]');
        expect(region.attributes('role')).toBe('status');
        expect(region.attributes('aria-live')).toBe('polite');
    });

    it('keeps a reduction percentage off the card entirely', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, { uuid: 'root' });

        expect(
            labelledValues(wrapper).some((v) => v.startsWith('Reduction:')),
        ).toBe(false);
    });

    /**
     * W70, item 22. The completeness bar lived here from W29 to W68.
     *
     * A bar states a fraction of a whole, and the whole it stated a fraction
     * of — the number of risk/attack pairs in the assessment — was never on
     * this row and could not be worked out from it, so the fill was a shape
     * with no scale. The figure did not go anywhere: the Statistics tab and
     * the report both state it as a count of pairs with the counts beside it.
     *
     * Asserted as zero bars on the *root*, which is the only row that ever
     * had one, and separately as zero on a risk above.
     */
    it('carries no bar at all: a fraction whose whole is not on the row', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, { uuid: 'root' });

        expect(wrapper.findAllComponents(Bar)).toHaveLength(0);
    });

    /**
     * The bar going is not the hint going somewhere else on the row. Removing
     * a component and leaving its explanation attached to a neighbouring chip
     * would leave a sentence about coverage hanging off a total, which is the
     * kind of half-removal that reads as a bug rather than as a decision.
     */
    it('takes the completeness sentence off the row with it', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, { uuid: 'root' });

        const hints = wrapper
            .findAllComponents(Tooltip)
            .map((t) => t.props('text') as string)
            .join(' | ');

        expect(hints).not.toMatch(/risk\/attack pairs/);
    });
});

describe('nothing on the card reads a baseline selection', () => {
    /**
     * W36 asserted this by moving the selector and watching the card not
     * change. W37 deleted `useBaseline` outright, so the stronger statement is
     * available and is the one worth pinning: the editor view has no baseline
     * to read. A future package re-adding one would have to pass this test
     * deliberately rather than by accident.
     */
    it('the editor view exposes no baseline at all', () => {
        const store = createAssessmentStore(makeInit());
        const { view } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        expect(view).not.toBeNull();
        expect('baseline' in view!).toBe(false);
    });

    it('explains a likelihood without naming a baseline it cannot show', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'attack1',
            ancestors: ['root', 'risk1'],
        });

        const hint = tooltipFor(wrapper, 'Likelihood');
        expect(hint).toMatch(/entered/);
        expect(hint).not.toMatch(/baseline/i);
    });
});

describe('missing values say which kind of missing they are', () => {
    it('explains that a missing impact excludes the risk from the total', () => {
        const init = makeInit();
        init.nodes = init.nodes.map((n) =>
            n.uuid === 'risk1'
                ? { ...n, impact_mode: null, impact_amount: null }
                : n,
        );
        init.results.current.risks[0].impactValue = null;
        const store = createAssessmentStore(init);

        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        expect(wrapper.text()).toContain('?');
        expect(tooltipFor(wrapper, 'Impact')).toMatch(
            /excluded from the total/,
        );
        expect(tooltipFor(wrapper, 'Impact')).toMatch(/not counted as zero/);
    });

    it('explains that a missing strength is treated as no reduction at all', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
            edge: { ...edge, strength_mode: null, strength_fraction: null },
        });

        // The missing `?` chip, not a blank and not "0%": a missing strength
        // is treated as no reduction at all, and an absent chip would be
        // indistinguishable from a strength of zero.
        expect(labelledValues(wrapper)).toEqual(['Reduces likelihood: ?']);

        const hint = wrapper
            .findAllComponents(Tooltip)
            .map((t) => t.props('text') as string)
            .join(' | ');
        expect(hint).toMatch(/no reduction/);
    });
    /*
     * 2.5.1: the list read only `strength_fraction`, so every qualitative
     * edge — a band and no fraction — showed the missing `?` while the chart
     * card showed the band and the engine counted it. Every control in the
     * standards templates is qualitative, which made a whole template look
     * unrated in the list.
     */
    it('shows a qualitative strength as its band, not as missing', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
            edge: {
                ...edge,
                strength_mode: 'qualitative',
                strength_fraction: null,
                strength_level_id: 42,
            },
        });

        expect(labelledValues(wrapper)).toEqual([
            'Reduces likelihood: Limited',
        ]);

        const hint = wrapper
            .findAllComponents(Tooltip)
            .map((t) => t.props('text') as string)
            .join(' | ');
        expect(hint).toMatch(/removes a “Limited” share of the likelihood/);
        expect(hint).not.toMatch(/no reduction/);
    });
});

describe('the defence row', () => {
    /**
     * W29 merged strength and reduction target into one phrase because as two
     * bare chips ("40%" beside "Reduces likelihood") the reader had to infer
     * that the number belonged to the words. W70 keeps them one fact and gives
     * them the label-colon-value shape the rest of the row uses: the target is
     * the label, the strength is the value, and the colon does the joining.
     */
    it('names the reduction target in the label and the strength as the value', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
            edge,
        });

        expect(labelledValues(wrapper)).toEqual(['Reduces likelihood: 40%']);
        // Never two chips again: the percentage must not stand on its own.
        expect(wrapper.findAllComponents(ValueChip)).toHaveLength(1);
    });

    it('says "impact" when that is what the link reduces', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
            edge: { ...edge, reduction_target: 'impact' },
        });

        expect(labelledValues(wrapper)).toEqual(['Reduces impact: 40%']);
    });

    /**
     * A null reduction target is the **default**, not a missing value: it
     * means the reduction applies to the overall loss, which is what the link
     * editor's placeholder says and what the engine applies. A `?` here would
     * invent a gap where the user made no choice because none was needed —
     * and it would sit next to the `?` on the strength, which does mean
     * missing, so the row would show two identical marks meaning two
     * different things.
     */
    it('treats an unset reduction target as "overall loss", never as missing', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
            edge: { ...edge, reduction_target: null },
        });

        expect(labelledValues(wrapper)).toEqual(['Reduces overall loss: 40%']);
    });

    /**
     * Strength lives on the `attack_defence` pivot, so one control may be 80%
     * effective against one attack and 20% against another and the card must
     * show whichever edge this occurrence sits on. Reading the node, or the
     * first link that mentions the defence, would render one of the two
     * figures under both attacks — silently, and wrongly, in the direction
     * nothing else would catch.
     */
    it('reads the strength from this edge, not from the defence', () => {
        const store = createAssessmentStore(makeInit());
        const weaker: AttackDefenceLink = {
            ...edge,
            attack_uuid: 'attack2',
            strength_fraction: 0.2,
        };

        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack2'],
            edge: weaker,
        });

        expect(labelledValues(wrapper)).toEqual(['Reduces likelihood: 20%']);
    });

    /**
     * The status pill is the whole of what this row knows about whether the
     * control counts. W29 dimmed the reduction chip and explained "not applied
     * in this baseline"; that answered *is this counting in what I am looking
     * at?*, and the card no longer shows a figure it could be counted into.
     * The consequence lives in the Statistics tab and the report, against all
     * three baselines at once.
     */
    it('states the status plainly and drops the not-applied dimming with the selector', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
            edge,
        });

        const hints = wrapper
            .findAllComponents(Tooltip)
            .map((t) => t.props('text') as string)
            .join(' | ');

        expect(hints).not.toMatch(/Planned, not implemented/);
        expect(hints).not.toMatch(/not applied/i);
        expect(wrapper.findComponent(StatusPill).exists()).toBe(true);
    });

    it('shows neither cost nor saving', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
            edge,
        });

        // The strength chip is the whole of the defence row's figures; cost
        // and saving are engine output and live in the Statistics tab.
        expect(labelledValues(wrapper)).toEqual(['Reduces likelihood: 40%']);
        expect(wrapper.text()).not.toContain('/yr');
    });
});

describe('fewer actions', () => {
    /**
     * 2.5.1: the gear ("Open details") is gone — the title opens the modal,
     * as on the chart — and so is the inline rename it sat beside.
     */
    it('offers add and delete — and no gear, pencil or link duplicate', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        const labels = wrapper
            .findAll('button')
            .map((b) => b.attributes('aria-label') ?? '')
            .filter(Boolean);

        expect(labels).toContain('Add child');
        expect(labels).toContain('Delete');
        expect(labels).not.toContain('Open details');
        expect(labels).not.toContain('Edit title');
        expect(labels).not.toContain('Links');
    });
});

describe('editing works the same as on the chart (2.5.1)', () => {
    afterEach(() => useNodeModal().close());

    it('a single click on the title opens the modal on Details — no inline field', async () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        await wrapper.get('[data-testid="node-title"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('input').exists()).toBe(false);
        expect(useNodeModal().openUuid.value).toBe('risk1');
        expect(useNodeModal().activeTab.value).toBe('details');
    });

    it('a click on the impact opens it on the tab that holds the impact', async () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        await wrapper.get('[data-testid="node-value"]').trigger('click');

        expect(useNodeModal().openUuid.value).toBe('risk1');
        expect(useNodeModal().activeTab.value).toBe('values');
    });

    it('marks every area that opens the editor with a pointer cursor', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        expect(wrapper.get('[data-testid="node-title"]').classes()).toContain(
            'cursor-pointer',
        );
        expect(wrapper.get('[data-testid="node-value"]').classes()).toContain(
            'cursor-pointer',
        );
    });

    it('a click on the description opens the modal; a double-click edits it in place', async () => {
        vi.useFakeTimers();
        try {
            const init = makeInit();
            init.nodes.find((n) => n.uuid === 'risk1')!.description =
                'Card data leaves the shop';
            const store = createAssessmentStore(init);
            const updateNode = vi
                .spyOn(store, 'updateNode')
                .mockResolvedValue();
            const { wrapper } = mountCard(store, {
                uuid: 'risk1',
                ancestors: ['root'],
                expanded: true,
            });

            // A single click waits out the double-click window, then opens.
            await wrapper
                .get('[data-testid="node-description"]')
                .trigger('click');
            expect(useNodeModal().openUuid.value).toBeNull();
            vi.advanceTimersByTime(300);
            expect(useNodeModal().openUuid.value).toBe('risk1');
            useNodeModal().close();

            // A double-click is two clicks and a dblclick; the modal must not
            // open on top of the field.
            const description = wrapper.get('[data-testid="node-description"]');
            await description.trigger('click');
            await description.trigger('click');
            await description.trigger('dblclick');
            await vi.runAllTimersAsync();

            expect(useNodeModal().openUuid.value).toBeNull();
            const input = wrapper.get<HTMLTextAreaElement>(
                '[data-testid="node-description-input"]',
            );
            expect(input.element.value).toBe('Card data leaves the shop');

            await input.setValue('Card data leaves the shop by email');
            await input.trigger('blur');

            expect(updateNode).toHaveBeenCalledWith('risk1', {
                description: 'Card data leaves the shop by email',
            });
        } finally {
            vi.useRealTimers();
        }
    });

    it('add-child opens a draft and writes nothing', async () => {
        const store = createAssessmentStore(makeInit());
        const post = vi.fn();
        (window as unknown as { axios: Record<string, unknown> }).axios = {
            ...(window as unknown as { axios: Record<string, unknown> }).axios,
            post,
        };
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        await wrapper.get('button[aria-label="Add child"]').trigger('click');

        expect(useNodeModal().draft.value).toMatchObject({
            parentUuid: 'risk1',
            childType: 'attack',
        });
        expect(post).not.toHaveBeenCalled();
    });
});

describe('mounted without an editor view', () => {
    it('renders its entered values rather than failing to render', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
            withView: false,
        });

        expect(labelledValues(wrapper)).toEqual(['Impact: €50,000']);
    });

    it('still shows the three totals at the root', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'root',
            withView: false,
        });

        expect(labelledValues(wrapper)).toEqual([
            'Base: €50,000',
            'Current: €25,000(50% lower)',
            'Target ALE: €15,000(70% lower)',
        ]);
    });
});

/**
 * The risk tolerance badge (W44, item 12).
 *
 * The fixture's current total is 25,000 against an inherent 50,000, so a
 * tolerance either side of 25,000 exercises both readings.
 */
describe('the risk tolerance badge on the root card', () => {
    function withTolerance(riskTolerance: number | null) {
        const init = makeInit();
        init.assessment = { ...init.assessment, risk_tolerance: riskTolerance };

        return mountCard(createAssessmentStore(init), { uuid: 'root' }).wrapper;
    }

    it('says nothing when no tolerance is set', () => {
        expect(
            withTolerance(null).find('[data-testid="risk-tolerance"]').exists(),
        ).toBe(false);
    });

    it('reports the shortfall when the current total is over', () => {
        const badge = withTolerance(10000).get(
            '[data-testid="risk-tolerance"]',
        );

        // 25,000 current against a 10,000 tolerance.
        expect(badge.text()).toContain('15,000');
        expect(badge.text()).toContain('over tolerance');
    });

    it('says so plainly when the current total is within', () => {
        expect(
            withTolerance(40000).get('[data-testid="risk-tolerance"]').text(),
        ).toContain('Within tolerance');
    });

    it('treats a tolerance of zero as a setting, not as absent', () => {
        // Zero is a statement — every euro of residual loss is worth arguing
        // about — and a current total of 25,000 is over it.
        expect(
            withTolerance(0).get('[data-testid="risk-tolerance"]').text(),
        ).toContain('over tolerance');
    });

    it('compares against current, not target', () => {
        // Target is 15,000 and current is 25,000. A tolerance of 20,000 sits
        // between them: the assessment is over today, and the plan would
        // bring it under. The question a tolerance answers is about today.
        expect(
            withTolerance(20000).get('[data-testid="risk-tolerance"]').text(),
        ).toContain('over tolerance');
    });

    it('states a comparison and never a verdict', () => {
        const text = withTolerance(1000).text();

        // calculation-engine.md forbids band-mapping a computed figure into
        // low/medium/high. A user-supplied threshold does not license it.
        for (const banned of ['High', 'Medium', 'Low', 'Critical']) {
            expect(text).not.toContain(banned);
        }
    });
});

/**
 * W85 item 1 / D31: The occurrence tag — the attack counter in
 * AppearsUnderPopover now renders as an icon + number chip, matching the
 * defence counter's appearance on both ChartCard and NodeCard.
 */
describe('NodeCard: occurrence tag (W85 item 1)', () => {
    /**
     * Create a test fixture with attack(s) linked to multiple risks so we can
     * verify the AppearsUnderPopover trigger appears with the correct appearance.
     * The occurrence count must be > 1 to show the trigger.
     */
    function makeInitWithAttack(): AssessmentStoreInit {
        return {
            assessment: {
                uuid: 'assessment-uuid',
                currency: 'EUR',
                display_mode: 'mixed',
                amortization_years: 3,
                is_public: false,
            },
            nodes: [
                node({ uuid: 'root', type: 'root', title: 'Web shop' }),
                node({
                    uuid: 'risk1',
                    type: 'risk',
                    title: 'Customer data breach',
                    impact_mode: 'quantitative',
                    impact_amount: 50000,
                }),
                node({
                    uuid: 'risk2',
                    type: 'risk',
                    title: 'Second Risk',
                    impact_mode: 'quantitative',
                    impact_amount: 30000,
                }),
                node({
                    uuid: 'attack1',
                    type: 'attack',
                    title: 'Phishing',
                    likelihood_mode: 'quantitative',
                    likelihood_aro: 0.5,
                }),
            ],
            riskAttackLinks: [
                { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
                { risk_uuid: 'risk2', attack_uuid: 'attack1', position: 0 },
            ],
            attackDefenceLinks: [],
            results: {
                inherent: result('inherent', 50000, 0.5, null),
                current: result('current', 25000, 0.5, null),
                target: result('target', 15000, 0.3, 10000),
            },
            abilities: {
                comment: true,
                update: true,
                manage: false,
                transfer: false,
            },
        };
    }

    function result(
        baseline: 'inherent' | 'current' | 'target',
        ale: number,
        aroResidual: number,
        defenceSaving: number | null,
    ) {
        return {
            baseline,
            total: ale,
            risks: [
                {
                    uuid: 'risk1',
                    ale,
                    impactValue: 50000,
                    impactLevelCode: null,
                    annualProbability: 0.39,
                    pairsValued: 1,
                    pairsTotal: 1,
                },
            ],
            attacks: [
                {
                    uuid: 'attack1',
                    ale,
                    aroBase: 0.5,
                    aroResidual,
                    aroLevelCode: null,
                    factor: 1,
                    riskCount: 1,
                },
            ],
            pairs: [],
            defences: defenceSaving === null ? [] : [],
            completeness: {
                isPartial: false,
                pairsTotal: 4,
                pairsValued: 3,
                coverage: 0.75,
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

    it('renders the occurrence tag trigger with shuffle icon and number for attacks on tree card', () => {
        const store = createAssessmentStore(makeInitWithAttack());
        const { wrapper } = mountCard(store, {
            uuid: 'attack1',
            ancestors: ['root', 'risk1'],
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
        // The attack appears under 2 risks
        const text = appearsUnderButton.text();
        expect(text).toMatch(/^2$/);

        // Check that the accessible name is preserved
        const ariaLabel = appearsUnderButton.attributes('aria-label');
        expect(ariaLabel).toContain('Appears under');
        expect(ariaLabel).toContain('2');
        expect(ariaLabel).toContain('risks');
    });

    it('does not render the occurrence tag trigger for non-attack types on tree card', () => {
        const store = createAssessmentStore(makeInitWithAttack());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        // AppearsUnderPopover should not render for risk nodes
        const appearsUnderButton = wrapper.find(
            '[data-testid="appears-under-trigger"]',
        );

        expect(appearsUnderButton.exists()).toBe(false);
    });
});

/**
 * 2.5.1: add from a template, on the list card. The picker's behaviour is
 * tested on its own (tests/js/templates/); this is the trigger and the panel
 * it opens, and that the card stops being a drag source while it is open.
 */
describe('add from a template (2.5.1)', () => {
    beforeEach(() => {
        resetTemplateOptionsCache();
        (window as unknown as { axios: { get: unknown } }).axios.get = vi
            .fn()
            .mockResolvedValue({
                data: {
                    templates: [
                        {
                            uuid: 'tpl1',
                            title: 'NIS2',
                            counts: { risk: 5, attack: 12, defence: 40 },
                        },
                    ],
                },
            });
    });

    it('is offered on a card that can have children, named for the child type', () => {
        const { wrapper } = mountCard(createAssessmentStore(makeInit()), {
            uuid: 'attack1',
            ancestors: ['root', 'risk1'],
        });

        expect(
            wrapper
                .find('[data-testid="add-from-template"]')
                .attributes('aria-label'),
        ).toBe('Add defences from a template');
    });

    it('is not offered on a defence, which has no children', () => {
        const { wrapper } = mountCard(createAssessmentStore(makeInit()), {
            uuid: 'defence1',
            ancestors: ['root', 'risk1', 'attack1'],
        });

        expect(wrapper.find('[data-testid="add-from-template"]').exists()).toBe(
            false,
        );
    });

    it('opens the picker on the template list', async () => {
        const { wrapper } = mountCard(createAssessmentStore(makeInit()), {
            uuid: 'risk1',
            ancestors: ['root'],
        });

        await wrapper
            .find('[data-testid="add-from-template"]')
            .trigger('click');
        await flushPromises();

        const picker = wrapper.find('[data-testid="template-picker"]');
        expect(picker.exists()).toBe(true);
        expect(picker.text()).toContain('NIS2');
        expect(picker.text()).toContain('12 attacks');
    });
});

/**
 * W127/D95: a collapsed card says how many children it hides. Asserted as the
 * exact number and the exact accessible name; presence alone would stay green
 * if the badge showed on expanded cards or counted the wrong thing.
 */
describe('a collapsed card shows how many children it hides (W127)', () => {
    const ancestors = ['root'];

    it('shows the count while collapsed, named for a screen reader', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors,
            childCount: 3,
        });
        const badge = wrapper.find('[data-testid="hidden-count"]');
        expect(badge.text()).toBe('3');
        expect(badge.attributes('aria-label')).toBe(
            'Expand, 3 hidden children',
        );
    });

    it('uses the singular for one child', () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors,
            childCount: 1,
        });
        expect(
            wrapper
                .find('[data-testid="hidden-count"]')
                .attributes('aria-label'),
        ).toBe('Expand, 1 hidden child');
    });

    it('shows nothing when expanded or when there are no children', () => {
        const store = createAssessmentStore(makeInit());
        const expanded = mountCard(store, {
            uuid: 'risk1',
            ancestors,
            childCount: 3,
            expanded: true,
        });
        expect(
            expanded.wrapper.find('[data-testid="hidden-count"]').exists(),
        ).toBe(false);
        const leaf = mountCard(store, {
            uuid: 'risk1',
            ancestors,
            childCount: 0,
        });
        expect(leaf.wrapper.find('[data-testid="hidden-count"]').exists()).toBe(
            false,
        );
    });

    it('expands when clicked', async () => {
        const store = createAssessmentStore(makeInit());
        const { wrapper } = mountCard(store, {
            uuid: 'risk1',
            ancestors,
            childCount: 2,
        });
        await wrapper.find('[data-testid="hidden-count"]').trigger('click');
        expect(wrapper.emitted('toggle')).toHaveLength(1);
    });
});
