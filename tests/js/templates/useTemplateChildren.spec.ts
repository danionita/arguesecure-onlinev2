/**
 * Add from a template (2.5.1): what each template node offers to do, and what
 * doing it writes.
 *
 * The rule under test is W40's "link, don't duplicate" carried over to
 * templates: a template node whose title already exists here as the same type
 * is offered as **Link** (or "Already here" under this very parent), because
 * copying "A.5.15 Access control" a second time would make two controls with
 * one name and split every figure computed for it. Only a title that is new
 * to the assessment goes through `addChild`.
 */
import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { computed } from 'vue';
import {
    resetTemplateOptionsCache,
    useTemplateChildren,
    type TemplateNodeItem,
} from '../../../resources/js/Components/Node/useTemplateChildren';
import {
    createAssessmentStore,
    type AssessmentStore,
    type NodeRecord,
    type NodeType,
} from '../../../resources/js/stores/assessmentStore';

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

/**
 * Two risks; "Phishing" sits under the first only, "Backups" under Phishing.
 * The card under test is usually the second risk, so an existing "Phishing"
 * is somewhere else in the assessment — the Link case.
 */
function makeStore(): AssessmentStore {
    return createAssessmentStore({
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
            node({
                uuid: 'risk2',
                type: 'risk',
                title: 'Outage',
                position: 1,
            }),
            node({ uuid: 'attack1', type: 'attack', title: 'Phishing' }),
            node({ uuid: 'attack2', type: 'attack', title: 'DDoS' }),
            node({ uuid: 'defence1', type: 'defence', title: 'Backups' }),
        ],
        riskAttackLinks: [
            { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
            { risk_uuid: 'risk2', attack_uuid: 'attack2', position: 0 },
        ],
        attackDefenceLinks: [
            {
                attack_uuid: 'attack1',
                defence_uuid: 'defence1',
                position: 0,
                strength_mode: null,
                strength_fraction: null,
                strength_level_id: null,
                reduction_target: null,
                rationale: null,
            },
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
    });
}

function item(over: Partial<TemplateNodeItem>): TemplateNodeItem {
    return {
        uuid: 'tnode',
        title: '',
        description: null,
        is_transfer: false,
        parents: [],
        ...over,
    };
}

let get: ReturnType<typeof vi.fn>;
let post: ReturnType<typeof vi.fn>;

beforeEach(() => {
    resetTemplateOptionsCache();
    get = vi.fn().mockImplementation((url: string) =>
        Promise.resolve(
            url === '/templates.options'
                ? {
                      data: {
                          templates: [
                              {
                                  uuid: 'tpl1',
                                  title: 'ISO/IEC 27001',
                                  counts: { risk: 3, attack: 4, defence: 93 },
                              },
                          ],
                      },
                  }
                : { data: { nodes: [item({ title: 'Ransomware' })] } },
        ),
    );
    post = vi.fn().mockImplementation((url: string, body: unknown) =>
        Promise.resolve({
            data: {
                link:
                    url === '/assessments.risk-attack-links.store'
                        ? { ...(body as object), position: 1 }
                        : {
                              ...(body as object),
                              position: 1,
                              strength_mode: null,
                              strength_fraction: null,
                              strength_level_id: null,
                              reduction_target: null,
                              rationale: null,
                          },
            },
        }),
    );
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => `/${name}`);
    (window as unknown as { axios: unknown }).axios = {
        get,
        post,
        patch: vi.fn(),
        delete: vi.fn(),
    };
});

function setup(uuid: string, type: NodeType, store = makeStore()) {
    const addChild = vi.spyOn(store, 'addChild').mockResolvedValue('created1');
    const children = useTemplateChildren(
        store,
        computed(() => uuid),
        computed(() => type),
    );

    return { store, addChild, ...children };
}

describe('what a template node offers to do', () => {
    it('offers Add for a title this assessment does not have', () => {
        const { templateItemState } = setup('risk2', 'risk');

        expect(templateItemState(item({ title: 'Ransomware' }))).toBe('add');
    });

    it('offers Link for an attack that exists under another risk, matching title case- and space-insensitively', () => {
        const { templateItemState } = setup('risk2', 'risk');

        expect(templateItemState(item({ title: 'Phishing' }))).toBe('link');
        expect(templateItemState(item({ title: '  phishing ' }))).toBe('link');
    });

    it('says Already here for one that is under this parent', () => {
        const { templateItemState } = setup('risk1', 'risk');

        expect(templateItemState(item({ title: 'Phishing' }))).toBe('here');
    });

    it('matches only nodes of the child type', () => {
        // "Backups" exists — as a defence. Under a risk the child is an
        // attack, so an attack called Backups would be new.
        const { templateItemState } = setup('risk2', 'risk');

        expect(templateItemState(item({ title: 'Backups' }))).toBe('add');
    });

    it('offers Link for a defence that exists under another attack', () => {
        const { templateItemState } = setup('attack2', 'attack');

        expect(templateItemState(item({ title: 'Backups' }))).toBe('link');
    });

    it('never offers Link for a risk, which has one parent to be under', () => {
        const { templateItemState } = setup('root', 'root');

        expect(templateItemState(item({ title: 'data breach' }))).toBe('here');
        expect(templateItemState(item({ title: 'Fraud' }))).toBe('add');
    });
});

describe('taking a template node', () => {
    it('adds a new one through addChild with its title and description only', async () => {
        const { addChild, takeTemplateItem, templateItemState } = setup(
            'risk2',
            'risk',
        );
        const ransomware = item({
            uuid: 't1',
            title: 'Ransomware',
            description: 'Files encrypted for a ransom.',
        });

        await takeTemplateItem(ransomware);

        expect(addChild).toHaveBeenCalledTimes(1);
        expect(addChild).toHaveBeenCalledWith('risk', 'risk2', {
            title: 'Ransomware',
            description: 'Files encrypted for a ransom.',
        });
        expect(templateItemState(ransomware)).toBe('added');
        expect(post).not.toHaveBeenCalled();
    });

    it("carries a defence's transfer flag", async () => {
        const { addChild, takeTemplateItem } = setup('attack1', 'attack');

        await takeTemplateItem(
            item({ title: 'Cyber insurance', is_transfer: true }),
        );

        expect(addChild).toHaveBeenCalledWith('attack', 'attack1', {
            title: 'Cyber insurance',
            description: null,
            is_transfer: true,
        });
    });

    it('links an existing attack instead of copying it', async () => {
        const { store, addChild, takeTemplateItem } = setup('risk2', 'risk');

        await takeTemplateItem(item({ title: 'Phishing' }));

        expect(addChild).not.toHaveBeenCalled();
        expect(post).toHaveBeenCalledWith(
            '/assessments.risk-attack-links.store',
            { risk_uuid: 'risk2', attack_uuid: 'attack1' },
        );
        expect(store.attacksUnderRisk('risk2')).toContain('attack1');
    });

    it('links an existing defence instead of copying it', async () => {
        const { addChild, takeTemplateItem } = setup('attack2', 'attack');

        await takeTemplateItem(item({ title: 'Backups' }));

        expect(addChild).not.toHaveBeenCalled();
        expect(post).toHaveBeenCalledWith(
            '/assessments.attack-defence-links.store',
            { attack_uuid: 'attack2', defence_uuid: 'defence1' },
        );
    });

    it('does nothing for one that is already here', async () => {
        const { addChild, takeTemplateItem } = setup('risk1', 'risk');

        await takeTemplateItem(item({ title: 'Phishing' }));

        expect(addChild).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
    });

    it('does nothing twice for one it has just added', async () => {
        const { addChild, takeTemplateItem } = setup('risk2', 'risk');
        const ransomware = item({ uuid: 't1', title: 'Ransomware' });

        await takeTemplateItem(ransomware);
        await takeTemplateItem(ransomware);

        expect(addChild).toHaveBeenCalledTimes(1);
    });

    it("shows the server's reason when the write fails", async () => {
        const { addChild, takeTemplateItem, templateError, templateBusy } =
            setup('risk2', 'risk');
        addChild.mockRejectedValueOnce({
            response: { data: { message: 'The title field is too long.' } },
        });

        await takeTemplateItem(item({ title: 'Ransomware' }));

        expect(templateError.value).toBe('The title field is too long.');
        expect(templateBusy.value).toBeNull();
    });
});

describe('loading', () => {
    it('asks for the template list once, however many cards open it', async () => {
        const first = setup('risk1', 'risk');
        const second = setup('risk2', 'risk');

        await first.openTemplates();
        await second.openTemplates();

        expect(
            get.mock.calls.filter((c) => c[0] === '/templates.options'),
        ).toHaveLength(1);
        expect(second.templates.value?.[0].title).toBe('ISO/IEC 27001');
    });

    it("asks a template for its nodes of this card's child type", async () => {
        const { chooseTemplate, templateItems } = setup('attack1', 'attack');

        await chooseTemplate('tpl1');

        expect(route).toHaveBeenCalledWith('templates.nodes', {
            assessment: 'tpl1',
            type: 'defence',
        });
        expect(templateItems.value).toHaveLength(1);
    });

    it('retries the list after a failure rather than caching it', async () => {
        get.mockRejectedValueOnce(new Error('offline'));
        const { openTemplates, templateError, templates } = setup(
            'risk1',
            'risk',
        );

        await openTemplates();
        expect(templateError.value).toBe('The templates could not be loaded.');

        const again = setup('risk1', 'risk');
        await again.openTemplates();
        await flushPromises();
        expect(again.templates.value).toHaveLength(1);
        expect(templates.value).toBeNull();
    });

    it('closing forgets the chosen template and what was added', async () => {
        const {
            chooseTemplate,
            closeTemplates,
            takeTemplateItem,
            templateItemState,
            templateOpen,
            templateUuid,
        } = setup('risk2', 'risk');
        const ransomware = item({ uuid: 't1', title: 'Ransomware' });
        await chooseTemplate('tpl1');
        await takeTemplateItem(ransomware);

        closeTemplates();

        expect(templateOpen.value).toBe(false);
        expect(templateUuid.value).toBeNull();
        expect(templateItemState(ransomware)).toBe('add');
    });
});
