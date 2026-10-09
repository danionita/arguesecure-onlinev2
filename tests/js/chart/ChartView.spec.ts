/**
 * The chart view's promises, as asserted behaviour rather than as a
 * screenshot: four tiers of real `treeitem`s, cards carrying the shape of the
 * problem and not its figures, and the same modal the list view opens.
 */
import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ChartView from '../../../resources/js/Components/Chart/ChartView.vue';
import { useNodeModal } from '../../../resources/js/Components/NodeModal/useNodeModal';
import Bar from '../../../resources/js/Components/UI/Bar.vue';
import Tooltip from '../../../resources/js/Components/UI/Tooltip.vue';
import {
    createEditorView,
    EDITOR_VIEW_KEY,
} from '../../../resources/js/composables/useEditorView';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    type AssessmentStoreInit,
    type NodeRecord,
} from '../../../resources/js/stores/assessmentStore';

/**
 * ChartCard reads the assessment's calibration through `useScales()`, which
 * reads Inertia's page props (see useScales.ts for why it does not go through
 * the store). Both pages that mount this view send `scaleLevels`; this is that
 * prop, so the qualitative branch has a level to resolve.
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
    {
        id: 34,
        scale_id: 3,
        kind: 'strength' as const,
        unit: 'fraction',
        code: 'very_strong',
        label: 'Very strong',
        ordinal: 5,
        lower_bound: 0.95,
        upper_bound: 1,
        representative: 0.975,
    },
];

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            scaleLevels: SCALE_LEVELS,
            scales: [
                { id: 1, kind: 'impact', unit: 'currency', is_system: false },
                { id: 3, kind: 'strength', unit: 'fraction', is_system: false },
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
                description:
                    'Attacker reaches stored customer names, addresses and card tokens.',
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
                status: 'implemented',
            }),
        ],
        riskAttackLinks: [
            { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
        ],
        attackDefenceLinks: [
            {
                attack_uuid: 'attack1',
                defence_uuid: 'defence1',
                position: 0,
                strength_mode: 'quantitative',
                strength_fraction: 0.4,
                strength_level_id: null,
                reduction_target: 'likelihood',
                rationale: null,
            },
        ],
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
        },
    };
}

/** Deliberately three different numbers, so the ToS bar has two real segments. */
const TOTALS = { inherent: 100_000, current: 40_000, target: 25_000 } as const;

function result(baseline: 'inherent' | 'current' | 'target') {
    return {
        baseline,
        total: TOTALS[baseline],
        risks: [
            {
                uuid: 'risk1',
                ale: 25000,
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
                ale: 25000,
                aroBase: 0.5,
                aroResidual: 0.3,
                aroLevelCode: null,
                factor: 1,
                riskCount: 1,
            },
        ],
        pairs: [],
        defences: [
            {
                uuid: 'defence1',
                status: 'implemented',
                mc: 10000,
                mcAdd: 10000,
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

function mountChart(init: AssessmentStoreInit = makeInit()) {
    const store = createAssessmentStore(init);
    const view = createEditorView(store, {
        assessmentUuid: 'assessment-uuid',
        userId: 7,
    });

    const wrapper = mount(ChartView, {
        global: {
            provide: {
                [ASSESSMENT_STORE_KEY as symbol]: store,
                [EDITOR_VIEW_KEY as symbol]: view,
            },
        },
    });

    return { wrapper, store, view };
}

/** The fixture's titles, so the helpers below can find a card by its node. */
const TITLES: Record<string, string> = {
    root: 'Web shop',
    risk1: 'Customer data breach',
    attack1: 'Phishing',
    defence1: 'MFA',
};

/**
 * One card's root element. The `<li>` wraps the whole subtree, so it is the
 * card div that is exactly one occurrence — the same distinction ChartCard's
 * drag handle depends on.
 */
function cardOf(wrapper: VueWrapper, uuid: string) {
    return wrapper
        .findAll('[class*="group/chartcard"]')
        .find((card) => card.text().includes(TITLES[uuid]))!;
}

function indicatorText(wrapper: VueWrapper, uuid: string): string {
    return cardOf(wrapper, uuid).find('[data-values-set]').text();
}

function indicatorLabel(wrapper: VueWrapper, uuid: string): string {
    return (
        cardOf(wrapper, uuid)
            .find('[data-values-set]')
            .attributes('aria-label') ?? ''
    );
}

beforeEach(() => {
    localStorage.clear();
    useNodeModal().close();
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => `/${name}`);
    (window as unknown as { axios: unknown }).axios = {
        get: vi.fn().mockResolvedValue({ data: {} }),
        patch: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    };
});

describe('the tree survives being drawn as a chart', () => {
    it('keeps real treeitems at one aria-level per tier', () => {
        const { wrapper } = mountChart();

        const levels = wrapper
            .findAll('[role="treeitem"]')
            .map((el) => el.attributes('aria-level'));

        expect(levels).toEqual(['1', '2', '3', '4']);
    });

    it('keeps the nesting, so a screen reader still reads the structure', () => {
        const { wrapper } = mountChart();

        expect(wrapper.find('[role="tree"]').exists()).toBe(true);
        expect(wrapper.findAll('[role="group"]')).toHaveLength(3);
    });

    /**
     * W37 deleted the tier gutter to buy 84px of horizontal width, which is
     * the resource this view is short of. The tier is still announced — it
     * moved into each card's accessible name — so this asserts the
     * *replacement*, not merely the absence. Dropping the gutter without it
     * would have been a real accessibility regression rather than a trade.
     */
    it('announces each card by its tier, now that the tier gutter is gone', () => {
        const { wrapper } = mountChart();

        const names = wrapper
            .findAll('button[aria-label]')
            .map((el) => el.attributes('aria-label') ?? '')
            .filter((name) => name.includes(':'));

        expect(names).toContain('Risk: Customer data breach');
        expect(names).toContain('Attack: Phishing');
        expect(names.some((n) => n.startsWith('Defence:'))).toBe(true);
    });
});

describe('what a chart card shows', () => {
    it('shows the title and a snippet of the description', () => {
        const { wrapper } = mountChart();

        expect(wrapper.text()).toContain('Customer data breach');
        expect(wrapper.text()).toContain('Attacker reaches stored customer');
    });

    /**
     * W37 kept every figure off this card. W69 puts exactly one back
     * (PLAN-2.4.md D26): the node's **own** value, abbreviated, in the bottom
     * row. What stays off is the *computed* readout — a card still never shows
     * an annual loss, a contribution or a residual, because those are answers
     * the list, the modal and the report give. So the assertion is not "no
     * currency" any more; it is "the typed-in figure, and nothing derived".
     */
    it('shows the risk’s own impact, abbreviated, and no computed loss', () => {
        const { wrapper } = mountChart();

        expect(indicatorText(wrapper, 'risk1')).toBe('€50k');
        // The full figure, and the fact that it is per occurrence rather than
        // per year, are in the tooltip's accessible name.
        expect(indicatorLabel(wrapper, 'risk1')).toContain('per occurrence');
        expect(wrapper.text()).not.toContain('Annual loss');
    });

    it('shows the attack’s ARO as a rate per year, which may exceed 1', () => {
        const { wrapper } = mountChart();

        expect(indicatorText(wrapper, 'attack1')).toBe('0.5/yr');
    });

    it("shows the defence's strength on this edge, as a percentage", () => {
        const { wrapper } = mountChart();

        expect(indicatorText(wrapper, 'defence1')).toBe('40%');
    });

    /**
     * Strength lives on the `attack_defence` pivot (CLAUDE.md), so the same
     * defence under two attacks is two figures. A card reading the strength
     * off the node instead of off the edge would show one of them twice, and
     * the shape of that bug is a single number appearing where two differ.
     */
    it('shows a shared defence at its own strength under each attack', () => {
        const init = makeInit();
        init.nodes.push(
            node({ uuid: 'attack2', type: 'attack', title: 'Malware' }),
        );
        init.riskAttackLinks.push({
            risk_uuid: 'risk1',
            attack_uuid: 'attack2',
            position: 1,
        });
        init.attackDefenceLinks.push({
            attack_uuid: 'attack2',
            defence_uuid: 'defence1',
            position: 0,
            strength_mode: 'quantitative',
            strength_fraction: 0.9,
            strength_level_id: null,
            reduction_target: 'likelihood',
            rationale: null,
        });

        const { wrapper } = mountChart(init);

        const figures = wrapper
            .findAll('[data-values-set]')
            .filter((el) =>
                (el.attributes('aria-label') ?? '').startsWith('Strength'),
            )
            .map((el) => el.text());

        expect(figures.sort()).toEqual(['40%', '90%']);
    });

    /**
     * A qualitative value has a `*_level_id` and no amount. `ScaleMapper` is
     * the only thing that turns one into a number and it does that for the
     * engine — printing a representative here would put a figure on the board
     * that the person who chose "Major" never typed.
     */
    it("renders a qualitative value as the level's label, never as an invented figure", () => {
        const init = makeInit();
        init.nodes = init.nodes.map((n) =>
            n.uuid === 'risk1'
                ? {
                      ...n,
                      impact_mode: 'qualitative' as const,
                      impact_amount: null,
                      impact_level_id: 12,
                  }
                : n,
        );

        const { wrapper } = mountChart(init);

        // The **label** (W87, D44). This card used to render
        // `formatLevelCode(level.code)`, which was invisible only because the
        // seeded codes match the seeded labels: `scale_levels.code` is derived
        // from the label once and then frozen, so renaming "Major" to
        // "Significant" left every card saying "major" for ever — an internal
        // key, shown to a user, disagreeing with the band table they had just
        // edited.
        expect(indicatorText(wrapper, 'risk1')).toBe('Major');
        expect(wrapper.text()).not.toContain('major');
        // 316,228 is the level's representative — the engine's number, not
        // the card's.
        expect(wrapper.text()).not.toContain('316');
        expect(wrapper.text()).not.toContain('€316k');
    });

    it('renders a qualitative strength as its label too, never the underscored code', () => {
        const init = makeInit();
        init.attackDefenceLinks[0].strength_mode = 'qualitative';
        init.attackDefenceLinks[0].strength_fraction = null;
        init.attackDefenceLinks[0].strength_level_id = 34;

        const { wrapper } = mountChart(init);

        expect(indicatorText(wrapper, 'defence1')).toBe('Very strong');
        // `very_strong` is the code. It reached the card as "very strong" —
        // de-underscored, and indistinguishable from a label until somebody
        // renamed the band.
        expect(wrapper.text()).not.toContain('very_strong');
        expect(wrapper.text()).not.toContain('very strong');
    });

    /**
     * The one computed figure that belongs on a card, on the one node it
     * describes: the whole assessment's answer. A bar rather than a number,
     * precisely so it is not read as one of the typed-in figures above.
     */
    it('gives the ToS a two-segment bar, not a number', () => {
        const { wrapper } = mountChart();

        const bar = wrapper.find('[data-tos-bar]');
        expect(bar.exists()).toBe(true);

        const width = (segment: string) =>
            bar.find(`[data-tos-segment="${segment}"]`).attributes('style');

        // 40,000 and 25,000 against an inherent 100,000.
        expect(width('current')).toContain('width: 40%');
        expect(width('target')).toContain('width: 25%');

        expect(bar.attributes('aria-label')).toContain('€100,000');
        expect(bar.attributes('aria-label')).toContain('€40,000');
        expect(bar.attributes('aria-label')).toContain('€25,000');
        // The root owns no value of its own, so it still has no indicator and
        // still has no tab to open.
        expect(bar.attributes('data-values-set')).toBeUndefined();
    });

    it('drops the ToS bar entirely when nothing is valued yet', () => {
        const init = makeInit();
        init.results.inherent.total = 0;
        init.results.current.total = 0;
        init.results.target.total = 0;

        const { wrapper } = mountChart(init);

        // No `?` for the root: unlike the other tiers there is nothing a
        // person could go and fill in, so a placeholder would prompt for
        // something impossible.
        expect(wrapper.find('[data-tos-bar]').exists()).toBe(false);
    });

    it('marks a node whose value is missing, rather than showing it as zero', () => {
        const init = makeInit();
        init.nodes = init.nodes.map((n) =>
            n.uuid === 'risk1'
                ? { ...n, impact_mode: null, impact_amount: null }
                : n,
        );
        init.results.current.risks[0].impactValue = null;

        const { wrapper } = mountChart(init);

        expect(wrapper.text()).toContain('?');
    });

    /**
     * W37: the values indicator is present in **both** states, always. If it
     * only appeared when something was missing, filling a value in would
     * change the card's layout, and the control that navigates to the values
     * would exist only while the values were absent — which is backwards.
     */
    it('carries a values indicator on every valued node, set or not', () => {
        const init = makeInit();
        init.nodes = init.nodes.map((n) =>
            n.uuid === 'attack1'
                ? { ...n, likelihood_mode: null, likelihood_aro: null }
                : n,
        );

        const { wrapper } = mountChart(init);
        const states = wrapper
            .findAll('[data-values-set]')
            .map((el) => el.attributes('data-values-set'));

        // Risk (impact set), attack (cleared above), defence (strength on the
        // edge). The root has none — it owns no values and has no tab to open.
        expect(states).toEqual(['true', 'false', 'true']);
    });

    it('carries no bars, ramps or counters — the cues went with the figures', () => {
        const { wrapper } = mountChart();

        expect(wrapper.findAllComponents(Bar)).toHaveLength(0);
        expect(wrapper.find('[data-cue-ramp]').exists()).toBe(false);
    });

    it('names a defence status in its accessible name, not only by colour', () => {
        const { wrapper } = mountChart();

        const statusLabels = wrapper
            .findAll('[role="img"][aria-label]')
            .map((el) => el.attributes('aria-label') ?? '');

        expect(statusLabels.some((l) => l.startsWith('Status:'))).toBe(true);
    });
});

describe('editing from the chart', () => {
    /**
     * 2.5.1: the list and the chart edit a node the same way — a single click
     * on the title or the description opens the modal on Details.
     */
    it('opens the modal on Details from a single click on the title', async () => {
        const { wrapper } = mountChart();
        const modal = useNodeModal();

        const riskTitle = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Customer data breach'))!;
        await riskTitle.trigger('click');

        expect(modal.openUuid.value).toBe('risk1');
        expect(modal.activeTab.value).toBe('details');
    });

    it('opens it from the description too', async () => {
        const { wrapper } = mountChart();
        const modal = useNodeModal();

        await cardOf(wrapper, 'risk1')
            .get('[data-testid="node-description"]')
            .trigger('click');

        expect(modal.openUuid.value).toBe('risk1');
        expect(modal.activeTab.value).toBe('details');
    });

    it('marks what opens the editor with a pointer cursor', () => {
        const { wrapper } = mountChart();
        const card = cardOf(wrapper, 'risk1');

        expect(card.get('[data-testid="node-title"]').classes()).toContain(
            'cursor-pointer',
        );
        expect(
            card.get('[data-testid="node-description"]').classes(),
        ).toContain('cursor-pointer');
        expect(card.get('[data-values-set]').classes()).toContain(
            'cursor-pointer',
        );
    });

    it('still selects a card clicked anywhere else — pointing at it while talking', async () => {
        const { wrapper } = mountChart();
        const modal = useNodeModal();

        await cardOf(wrapper, 'risk1').trigger('click');

        expect(modal.openUuid.value).toBeNull();
        expect(
            wrapper
                .findAll('[role="treeitem"]')
                .filter((el) => el.attributes('aria-selected') === 'true'),
        ).toHaveLength(1);
    });

    /**
     * The reported defect (2.5.1): "Add child" created an empty node before
     * the modal opened, so an untitled card appeared behind it and every
     * collaborator was notified. It now opens a draft and writes nothing.
     */
    it('opens a draft for a child under this parent and creates nothing', async () => {
        const { wrapper, store } = mountChart();
        const modal = useNodeModal();
        const post = vi.fn();
        (
            window as unknown as { axios: { post: ReturnType<typeof vi.fn> } }
        ).axios.post = post;
        const before = Object.keys(store.nodes).length;

        const addOnRisk = cardOf(wrapper, 'risk1')
            .findAll('button')
            .find((b) => b.attributes('aria-label') === 'Add child')!;
        await addOnRisk.trigger('click');

        expect(modal.draft.value).toEqual({
            parentType: 'risk',
            parentUuid: 'risk1',
            parentPath: 'root/risk1',
            childType: 'attack',
        });
        expect(modal.openUuid.value).toBeNull();
        expect(post).not.toHaveBeenCalled();
        expect(Object.keys(store.nodes)).toHaveLength(before);
    });

    it('offers add-child only where the schema allows a child', () => {
        const { wrapper } = mountChart();

        const addButtons = wrapper
            .findAll('button')
            .filter((b) => b.attributes('aria-label') === 'Add child');

        // root, risk and attack — not the defence.
        expect(addButtons).toHaveLength(3);
    });

    it('opens the modal on the tab that fixes the missing value', async () => {
        const { wrapper } = mountChart();
        const modal = useNodeModal();

        const indicators = wrapper.findAll('[data-values-set]');
        // Risk first: its value is the impact, which the Values tab edits.
        await indicators[0].trigger('click');

        expect(modal.openUuid.value).toBe('risk1');
        expect(modal.activeTab.value).toBe('values');
    });

    it("sends a defence to the tab that owns its strength, which is the edge's", async () => {
        const { wrapper } = mountChart();
        const modal = useNodeModal();

        const indicators = wrapper.findAll('[data-values-set]');
        await indicators[2].trigger('click');

        expect(modal.openUuid.value).toBe('defence1');
        // Strength lives on the attack_defence pivot (CLAUDE.md), so the tab
        // that edits the pivot is the one that fixes it — not Values.
        expect(modal.activeTab.value).toBe('links');
    });

    /**
     * W37 moved the hover panel to the bottom edge and cut it to two actions.
     * It used to sit exactly where the permanent values indicator now lives,
     * covering the one thing the card must always show.
     */
    it('keeps only collapse and add-child in the hover panel', () => {
        const { wrapper } = mountChart();

        const panelLabels = wrapper
            .findAll('button')
            .map((b) => b.attributes('aria-label') ?? '');

        expect(panelLabels).not.toContain('Delete');
        expect(panelLabels).not.toContain('Open details');
    });
});

/**
 * W69 item 11. "Appears in 3 places" was a statement a reader could do nothing
 * with — it counted the places and would not say which. The store already
 * knows both directions of the DAG's edges, so the counter names them.
 *
 * W72 builds the interactive popover on top of this (D31); W69 ships the
 * read-only tooltip.
 */
describe('the parent counter names the places', () => {
    /** One attack under `count` risks, so the counter is showing. */
    function sharedAttack(count: number): AssessmentStoreInit {
        const init = makeInit();

        for (let i = 1; i < count; i++) {
            init.nodes.push(
                node({
                    uuid: `risk${i + 1}`,
                    type: 'risk',
                    title: `Risk ${i}`,
                }),
            );
            init.riskAttackLinks.push({
                risk_uuid: `risk${i + 1}`,
                attack_uuid: 'attack1',
                position: 0,
            });
        }

        return init;
    }

    /**
     * One defence under `count` attacks.
     *
     * The naming tooltip is a *defence* affordance now. At integration the
     * attack's counter became W72's interactive `AppearsUnderPopover` (item
     * 12, D31) — detaching an attack from one of several risks is the thing
     * D31 is about, and the popover renders nothing for other types. So a
     * shared defence is what still gets the read-only tooltip, and these
     * assertions moved onto it rather than being deleted: the behaviour they
     * pin is unchanged, only the type that exercises it.
     */
    function sharedDefence(count: number): AssessmentStoreInit {
        const init = makeInit();

        for (let i = 1; i < count; i++) {
            init.nodes.push(
                node({
                    uuid: `attack${i + 1}`,
                    type: 'attack',
                    title: `Attack ${i}`,
                }),
            );
            init.riskAttackLinks.push({
                risk_uuid: 'risk1',
                attack_uuid: `attack${i + 1}`,
                position: i,
            });
            init.attackDefenceLinks.push({
                attack_uuid: `attack${i + 1}`,
                defence_uuid: 'defence1',
                position: 0,
                strength_mode: 'quantitative',
                strength_fraction: 0.9,
                strength_level_id: null,
                reduction_target: 'likelihood',
                rationale: null,
            });
        }

        return init;
    }

    /**
     * The shuffle counter's `Tooltip`, hovered. The bubble is a *sibling* of
     * the trigger inside that component, not a descendant of it, so the
     * assertions have to reach for the component rather than for the icon.
     */
    async function counterBubble(wrapper: VueWrapper) {
        const tooltip = wrapper.findAllComponents(Tooltip).find((t) => {
            const trigger = t.find('[role="img"]');

            return (
                trigger.exists() &&
                (trigger.attributes('aria-label') ?? '').startsWith(
                    'Appears in',
                )
            );
        })!;
        await tooltip.trigger('mouseenter');

        return tooltip;
    }

    it('lists the parent titles, one per line', async () => {
        const { wrapper } = mountChart(sharedDefence(3));
        const counter = await counterBubble(wrapper);

        expect(
            counter.findAll('[data-parent-name]').map((el) => el.text()),
        ).toEqual(['Phishing', 'Attack 1', 'Attack 2']);
    });

    /**
     * The other half of the integration split, asserted so the two cannot
     * quietly become one: an attack's counter is the interactive popover, not
     * the read-only tooltip. Asserting the trigger's testid rather than "some
     * component is present" — a tooltip would satisfy the looser claim.
     */
    it('gives a shared attack the interactive popover instead of the tooltip', () => {
        const { wrapper } = mountChart(sharedAttack(3));

        expect(
            wrapper.find('[data-testid="appears-under-trigger"]').exists(),
        ).toBe(true);
        expect(
            wrapper
                .findAll('[role="img"]')
                .some((el) =>
                    (el.attributes('aria-label') ?? '').startsWith(
                        'Appears in',
                    ),
                ),
        ).toBe(false);
    });

    it('names the attacks a shared defence sits under, not the risks', async () => {
        const init = makeInit();
        init.nodes.push(
            node({ uuid: 'attack2', type: 'attack', title: 'Malware' }),
        );
        init.riskAttackLinks.push({
            risk_uuid: 'risk1',
            attack_uuid: 'attack2',
            position: 1,
        });
        init.attackDefenceLinks.push({
            attack_uuid: 'attack2',
            defence_uuid: 'defence1',
            position: 0,
            strength_mode: 'quantitative',
            strength_fraction: 0.9,
            strength_level_id: null,
            reduction_target: 'likelihood',
            rationale: null,
        });

        const { wrapper } = mountChart(init);
        const counter = await counterBubble(wrapper);

        expect(
            counter.findAll('[data-parent-name]').map((el) => el.text()),
        ).toEqual(['Phishing', 'Malware']);
    });

    /**
     * The cap is a specified number, not "some". Someone whose attack sits
     * under fifteen risks is not reading the tooltip to find one of them.
     */
    it('caps the list at eight and counts the rest', async () => {
        const { wrapper } = mountChart(sharedDefence(12));
        const counter = await counterBubble(wrapper);

        expect(counter.findAll('[data-parent-name]')).toHaveLength(8);
        expect(counter.text()).toContain('and 4 more');
    });

    it('says nothing about "more" when everything fits', async () => {
        const { wrapper } = mountChart(sharedDefence(8));
        const counter = await counterBubble(wrapper);

        expect(counter.findAll('[data-parent-name]')).toHaveLength(8);
        expect(counter.text()).not.toContain('more');
    });

    /**
     * The bubble is `pointer-events-none` decoration a screen reader never
     * reaches, so the names have to be on the trigger's accessible name too or
     * this is a sighted-only feature.
     */
    it('puts the same names in the accessible name', async () => {
        const { wrapper } = mountChart(sharedDefence(3));
        const counter = await counterBubble(wrapper);

        expect(counter.find('[role="img"]').attributes('aria-label')).toBe(
            'Appears in 3 places: Phishing, Attack 1, Attack 2',
        );
    });

    it('falls back to a placeholder rather than an empty line for an untitled parent', async () => {
        const init = sharedDefence(2);
        init.nodes = init.nodes.map((n) =>
            n.uuid === 'attack2' ? { ...n, title: '' } : n,
        );

        const { wrapper } = mountChart(init);
        const counter = await counterBubble(wrapper);

        expect(
            counter.findAll('[data-parent-name]').map((el) => el.text()),
        ).toEqual(['Phishing', 'Untitled attack']);
    });
});

describe('collapsing', () => {
    it('drops the subtree from the chart, which is how a big assessment is made to fit', async () => {
        const { wrapper, view } = mountChart();

        expect(wrapper.findAll('[role="treeitem"]')).toHaveLength(4);

        view.collapse.toggle('root/risk1');
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll('[role="treeitem"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Customer data breach');
        expect(wrapper.text()).not.toContain('Phishing');
    });
});

/**
 * W84: the chart's baseline moved from one shared `CANVAS_BASE_SCALE` number
 * to a per-view record, and CLAUDE.md is explicit that `scaledSize` and
 * `fitToWidth` have to keep using the *same* multiplier the chart's own
 * transform uses or fit-to-width silently lies. These pin that the three
 * still agree, and that D51's baseline change (chart unchanged at 1.1, only
 * the list moved) did not accidentally touch the chart's own arithmetic.
 */
describe('W84: the chart baseline stays consistent across canvasStyle, scaledSize and fitToWidth', () => {
    /**
     * The layout's own width, in layout units. The connectors' svg carries
     * it verbatim (`:width="layout.width"`) and is the only svg carrying
     * `pointer-events-none absolute inset-0`. A bare `wrapper.find('svg')`,
     * or a filter on `aria-hidden="true"` alone, instead false-matches an
     * unlabelled Icon.vue svg (fixed at 12-16px) — Icon.vue sets the same
     * attribute — which is what happened the first time this test was
     * written and is why the class is pinned here rather than the ARIA
     * attribute.
     */
    function layoutWidthOf(wrapper: VueWrapper): number {
        const svg = wrapper
            .findAll('svg')
            .find((el) =>
                (el.attributes('class') ?? '').includes(
                    'pointer-events-none absolute inset-0',
                ),
            )!;
        return Number(svg.attributes('width'));
    }

    it('fitToWidth divides by the *painted* width (layout width × 1.1), not the bare layout width', async () => {
        const { wrapper, view } = mountChart();

        const layoutWidth = layoutWidthOf(wrapper);
        expect(layoutWidth).toBeGreaterThan(0);

        const container = wrapper
            .findAll('div')
            .find((el) =>
                (el.attributes('class') ?? '').includes('overflow-auto'),
            )!.element as HTMLElement;
        Object.defineProperty(container, 'clientWidth', {
            value: 700,
            configurable: true,
        });

        await (
            wrapper.vm as unknown as { fitToWidth: () => void }
        ).fitToWidth();

        // 1.1 is CANVAS_BASE_SCALE.chart, asserted as its own exact value in
        // useCanvas.spec.ts — this test is about fitToWidth using the *same*
        // number canvasStyle does, not about what that number is.
        const expectedZoom = 700 / (layoutWidth * 1.1);
        expect(view.canvas.chart.zoom.value).toBeCloseTo(expectedZoom, 6);
    });

    it('scaledSize paints at layout width × zoom × 1.1, the same factor canvasStyle applies', async () => {
        const { wrapper, view } = mountChart();
        const layoutWidth = layoutWidthOf(wrapper);

        view.canvas.chart.setZoom(1.5);
        await wrapper.vm.$nextTick();

        const sizer = wrapper.find('.mx-auto');
        expect(sizer.exists()).toBe(true);
        expect(sizer.attributes('style')).toContain(
            `width: ${layoutWidth * 1.5 * 1.1}px`,
        );
    });

    it('centres with mx-auto rather than a conditional class, per the docblock', () => {
        const { wrapper } = mountChart();

        expect(wrapper.find('.mx-auto').exists()).toBe(true);
    });
});
