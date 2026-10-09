/**
 * The attack_defence edge is one row, and W31's claim is that it now has one
 * editor reachable from both of its ends. The tests that matter are
 * therefore the symmetry ones: the same field, edited from the defence, must
 * produce the same request as it does from the attack — anything less and the
 * defence side is a second, subtly different implementation of the same
 * feature, which is how the two drift.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LinksTab from '../../../resources/js/Components/NodeModal/tabs/LinksTab.vue';
import {
    createAssessmentStore,
    type AssessmentStoreInit,
    type AttackDefenceLink,
    type NodeRecord,
} from '../../../resources/js/stores/assessmentStore';

const SCALE_LEVELS = [
    {
        id: 31,
        scale_id: 3,
        kind: 'strength' as const,
        unit: 'fraction',
        code: 'partial',
        label: 'Partial (40%)',
        ordinal: 1,
        lower_bound: 0.2,
        upper_bound: 0.6,
        representative: 0.4,
    },
];

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            scaleLevels: SCALE_LEVELS,
            scales: [
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

function makeInit(link: Partial<AttackDefenceLink> = {}): AssessmentStoreInit {
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
            node({ uuid: 'risk1', type: 'risk', title: 'Data breach' }),
            node({ uuid: 'attack1', type: 'attack', title: 'Phishing' }),
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
                ...link,
            },
        ],
        scaleLevels: SCALE_LEVELS,
        scales: [
            { id: 3, kind: 'strength', unit: 'fraction', is_system: false },
        ],
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

let patch: ReturnType<typeof vi.fn>;

function mountTab(uuid: string, init: AssessmentStoreInit = makeInit()) {
    const store = createAssessmentStore(init);
    return mount(LinksTab, { props: { store, uuid } });
}

/** The last PATCH body, minus the two uuids that identify the edge. */
function lastPatchFields() {
    const [, body] = patch.mock.calls[patch.mock.calls.length - 1];
    const { attack_uuid, defence_uuid, ...fields } = body as Record<
        string,
        unknown
    >;
    expect(attack_uuid).toBe('attack1');
    expect(defence_uuid).toBe('defence1');
    return fields;
}

beforeEach(() => {
    patch = vi.fn().mockResolvedValue({
        data: {
            link: {
                attack_uuid: 'attack1',
                defence_uuid: 'defence1',
                position: 0,
                strength_mode: 'quantitative',
                strength_fraction: 0.8,
                strength_level_id: null,
                reduction_target: 'likelihood',
                rationale: null,
            },
        },
    });
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => `/${name}`);
    (window as unknown as { axios: unknown }).axios = {
        get: vi.fn().mockResolvedValue({ data: {} }),
        patch,
        post: vi.fn(),
        delete: vi.fn(),
    };
});

describe('the same edge, edited from either end', () => {
    it('writes the same strength patch from the defence as from the attack', async () => {
        const fromAttack = mountTab('attack1');
        await fromAttack.get('input[type="number"]').setValue('0.8');
        const attackFields = lastPatchFields();

        patch.mockClear();

        const fromDefence = mountTab('defence1');
        await fromDefence.get('input[type="number"]').setValue('0.8');

        expect(lastPatchFields()).toEqual(attackFields);
        expect(attackFields).toEqual({
            strength_mode: 'quantitative',
            strength_fraction: 0.8,
        });
    });

    it('writes the reduction target from the defence side', async () => {
        const wrapper = mountTab('defence1');

        const selects = wrapper.findAll('select');
        const target = selects[selects.length - 1];
        await target.setValue('impact');

        expect(lastPatchFields()).toEqual({ reduction_target: 'impact' });
    });

    it('offers the strength scale when the defence side switches to qualitative', async () => {
        const wrapper = mountTab(
            'defence1',
            makeInit({ strength_mode: 'qualitative', strength_fraction: null }),
        );

        expect(wrapper.text()).toContain('Partial (40%)');
    });
});

describe('the Strength tab puts strength first (W72 item 9)', () => {
    it('reads strength, then reduction target, then rationale, in document order', () => {
        // Order is the claim, not presence. The tab is named after strength;
        // a reader who has to scroll past the reduction target to reach it is
        // being asked about a refinement before the thing it refines. This
        // asserts the sequence, so reordering the template turns it red —
        // a "the reduction-target select exists" check would not.
        const wrapper = mountTab('defence1');

        const labels = wrapper
            .findAll('label')
            .map((label) => label.text().trim())
            .filter((text) =>
                ['Strength mode', 'Reduction target', 'Rationale'].includes(
                    text,
                ),
            );

        expect(labels).toEqual([
            'Strength mode',
            'Reduction target',
            'Rationale',
        ]);
    });
});

describe('the rationale survives a reload', () => {
    it('renders a rationale that came back from the server with the graph', () => {
        // The regression: AssessmentGraphLoader did not select this column, so
        // the tab kept a local draft map and a saved rationale vanished on the
        // next page load. It is carried on the link now, and read from there.
        const wrapper = mountTab(
            'defence1',
            makeInit({ rationale: 'Vendor-published efficacy study, 2025.' }),
        );

        const rationaleInput = wrapper.get('input[maxlength="500"]');
        expect((rationaleInput.element as HTMLInputElement).value).toBe(
            'Vendor-published efficacy study, 2025.',
        );
    });

    it('saves a rationale typed on the defence side', async () => {
        const wrapper = mountTab('defence1');

        // Set the DOM value and fire one `change`, rather than setValue()'s
        // `input`: the field commits on change (a rationale is a sentence, and
        // one PATCH per keystroke would be absurd), and it is `:value`-bound to
        // the store, so a second event would re-read whatever the store holds.
        const input = wrapper.get('input[maxlength="500"]');
        (input.element as HTMLInputElement).value =
            'Rated low against this attack specifically.';
        await input.trigger('change');

        expect(lastPatchFields()).toEqual({
            rationale: 'Rated low against this attack specifically.',
        });
    });
});

describe('a viewer', () => {
    it('gets the editor read-only rather than hidden — the figures still matter to them', () => {
        const init = makeInit();
        init.abilities = {
            comment: false,
            update: false,
            manage: false,
            transfer: false,
        };
        const wrapper = mountTab('defence1', init);

        expect(wrapper.text()).toContain('Phishing');
        for (const input of wrapper.findAll('input')) {
            expect(input.attributes('disabled')).toBeDefined();
        }
    });
});
