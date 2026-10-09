/**
 * Adding a node (2.5.1): nothing exists until the draft is confirmed.
 *
 * The reported defect: pressing "Add child" opened the modal, and about a
 * second later the Details tab blanked, an untitled node appeared behind the
 * modal, and collaborators were told "a node" had been added. The cause was
 * that the button *created* an empty node first — the broadcast, the stray
 * card and the blanking all followed from that write (the last via the
 * author's own `node.created` echo replacing the record the tab was bound
 * to, without `tags`). So the assertions that matter here are about *writes*:
 * opening a draft sends nothing, abandoning it sends nothing, and only Add
 * creates the node — once, with the title that was typed.
 *
 * This file replaces the placeholder-era specs (ChartSuggestions,
 * NodeCardSuggestions, detailsAssistant): their subject — suggestions on an
 * already-persisted untitled node — no longer exists, and the behaviours they
 * protected are re-asserted here against the draft.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetTemplateOptionsCache } from '../../../resources/js/Components/Node/useTemplateChildren';
import NodeModal from '../../../resources/js/Components/NodeModal/NodeModal.vue';
import { useNodeModal } from '../../../resources/js/Components/NodeModal/useNodeModal';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    occurrencePath,
    type AssessmentStore,
    type AssessmentStoreInit,
    type NodeRecord,
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

function makeStore(over: Partial<AssessmentStoreInit> = {}): AssessmentStore {
    const init: AssessmentStoreInit = {
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
        ],
        riskAttackLinks: [],
        attackDefenceLinks: [],
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
        ...over,
    };

    return createAssessmentStore(init);
}

const SUGGESTIONS = [
    {
        title: 'Credential stuffing',
        description: 'Reused passwords replayed against the login form.',
    },
    {
        title: 'Invoice fraud by email',
        description: 'A supplier impersonated to redirect a payment.',
    },
];

let get: ReturnType<typeof vi.fn>;
let post: ReturnType<typeof vi.fn>;
let patch: ReturnType<typeof vi.fn>;
let del: ReturnType<typeof vi.fn>;

const TEMPLATE_NODES = [
    {
        uuid: 'tnode1',
        title: 'Ransomware',
        description: 'Files encrypted for a ransom.',
        is_transfer: false,
        parents: ['Data loss'],
    },
    {
        uuid: 'tnode2',
        title: 'Cyber insurance',
        description: 'A policy that pays out after an incident.',
        is_transfer: true,
        parents: [],
    },
];

const modal = useNodeModal();

beforeEach(() => {
    resetTemplateOptionsCache();
    get = vi.fn().mockImplementation((url: string) => {
        switch (url) {
            case '/assessments.nodes.search':
                return Promise.resolve({
                    data: {
                        nodes: [
                            {
                                uuid: 'attack1',
                                title: 'Phishing',
                                description: 'Credential theft by email',
                                occurrences: 2,
                            },
                        ],
                    },
                });
            case '/templates.options':
                return Promise.resolve({
                    data: {
                        templates: [
                            {
                                uuid: 'tpl1',
                                title: 'ISO/IEC 27001',
                                counts: { risk: 3, attack: 4, defence: 93 },
                            },
                        ],
                    },
                });
            case '/templates.nodes':
                return Promise.resolve({ data: { nodes: TEMPLATE_NODES } });
            default:
                return Promise.resolve({
                    data: {
                        running: false,
                        queued: false,
                        tokens: 0,
                        expected_tokens: 0,
                    },
                });
        }
    });
    post = vi.fn().mockImplementation((url: string, body: unknown) => {
        if (url === '/assessments.nodes.store') {
            const b = body as Record<string, unknown>;
            return Promise.resolve({
                data: {
                    node: node({
                        uuid: 'created1',
                        type: b.type as NodeRecord['type'],
                        title: String(b.title ?? ''),
                        description: (b.description as string) ?? null,
                        status: (b.status as NodeRecord['status']) ?? null,
                        is_transfer: b.is_transfer === true,
                    }),
                },
            });
        }
        if (url === '/assessments.assistant.suggestions') {
            return Promise.resolve({
                data: { child_type: 'attack', suggestions: SUGGESTIONS },
            });
        }
        return Promise.resolve({
            data: {
                link: {
                    risk_uuid: 'risk1',
                    attack_uuid: 'attack1',
                    position: 0,
                },
            },
        });
    });
    patch = vi.fn().mockResolvedValue({ data: {} });
    del = vi.fn().mockResolvedValue({ data: {} });

    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => `/${name}`);
    (window as unknown as { axios: unknown }).axios = {
        get,
        post,
        patch,
        delete: del,
    };
});

let wrapper: VueWrapper | null = null;

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    modal.close();
    document.body.innerHTML = '';
});

function render(store: AssessmentStore) {
    wrapper = mount(NodeModal, {
        attachTo: document.body,
        global: { provide: { [ASSESSMENT_STORE_KEY as symbol]: store } },
    });
}

function el<T extends HTMLElement = HTMLElement>(testid: string): T | null {
    return document.querySelector<T>(`[data-testid="${testid}"]`);
}

function titleInput(): HTMLInputElement {
    return el('new-node-title')!.querySelector('input')!;
}

async function type(value: string) {
    const input = titleInput();
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await flushPromises();
}

function nodeStores(): unknown[][] {
    return post.mock.calls.filter(
        (call) => String(call[0]) === '/assessments.nodes.store',
    );
}

describe('opening a draft writes nothing', () => {
    it('shows the form, and neither the store nor the server gains a node', async () => {
        const store = makeStore();
        render(store);
        const before = Object.keys(store.nodes).length;

        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();

        expect(el('new-node-form')).not.toBeNull();
        expect(document.body.textContent).toContain('New attack');
        expect(post).not.toHaveBeenCalled();
        expect(Object.keys(store.nodes)).toHaveLength(before);
    });

    it('abandoning it — Cancel or closing — writes nothing either', async () => {
        const store = makeStore();
        render(store);

        modal.openDraft('root', 'root', 'root');
        await flushPromises();
        await type('Half a thought');
        el('new-node-cancel')!.click();
        await flushPromises();

        expect(modal.draft.value).toBeNull();
        expect(el('new-node-form')).toBeNull();
        expect(post).not.toHaveBeenCalled();
        expect(del).not.toHaveBeenCalled();
    });

    it('will not add a node without a title', async () => {
        render(makeStore());
        modal.openDraft('root', 'root', 'root');
        await flushPromises();

        expect(el<HTMLButtonElement>('new-node-submit')!.disabled).toBe(true);
        titleInput().dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await flushPromises();

        expect(post).not.toHaveBeenCalled();
    });
});

describe('Add creates the node once, with what was typed', () => {
    it('posts title and description together, and links it under the parent', async () => {
        const store = makeStore();
        render(store);

        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        await type('Credential stuffing');
        const description = el<HTMLTextAreaElement>('new-node-description')!;
        description.value = 'Reused passwords';
        description.dispatchEvent(new Event('input'));
        await flushPromises();

        el('new-node-submit')!.click();
        await flushPromises();

        expect(nodeStores()).toHaveLength(1);
        expect(nodeStores()[0][1]).toEqual({
            type: 'attack',
            title: 'Credential stuffing',
            description: 'Reused passwords',
            risk_uuid: 'risk1',
        });
        expect(store.nodes['created1'].title).toBe('Credential stuffing');
        expect(store.attacksUnderRisk('risk1')).toContain('created1');
    });

    it('then shows the new node, so its values can be filled in next', async () => {
        render(makeStore());
        modal.openDraft('root', 'root', 'root');
        await flushPromises();
        await type('Data loss');
        el('new-node-submit')!.click();
        await flushPromises();

        expect(modal.draft.value).toBeNull();
        expect(modal.openUuid.value).toBe('created1');
        expect(el('new-node-form')).toBeNull();
        expect(document.body.textContent).toContain('Risk: Data loss');
    });

    it('Enter in the title adds, the same as the button', async () => {
        render(makeStore());
        modal.openDraft('root', 'root', 'root');
        await flushPromises();
        await type('Data loss');
        titleInput().dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await flushPromises();

        expect(nodeStores()).toHaveLength(1);
    });

    it("carries a defence's status and transfer flag", async () => {
        const store = makeStore({
            riskAttackLinks: [
                { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
            ],
        });
        render(store);
        modal.openDraft(
            'attack',
            'attack1',
            occurrencePath('root', 'risk1', 'attack1'),
        );
        await flushPromises();
        await type('Cyber insurance');
        (
            document.querySelector('[role="switch"]') as HTMLButtonElement
        ).click();
        await flushPromises();
        el('new-node-submit')!.click();
        await flushPromises();

        expect(nodeStores()[0][1]).toMatchObject({
            type: 'defence',
            title: 'Cyber insurance',
            status: 'todo',
            is_transfer: true,
            attack_uuid: 'attack1',
        });
    });

    it('a failed create keeps the form and what was typed', async () => {
        post.mockRejectedValueOnce({
            response: { data: { message: 'The title field is too long.' } },
        });
        render(makeStore());
        modal.openDraft('root', 'root', 'root');
        await flushPromises();
        await type('Data loss');
        el('new-node-submit')!.click();
        await flushPromises();

        expect(el('new-node-error')!.textContent).toContain('too long');
        expect(titleInput().value).toBe('Data loss');
        expect(modal.draft.value).not.toBeNull();
    });
});

describe("link, don't duplicate (W40/W79) on the draft", () => {
    it('offers existing attacks not already under this risk', async () => {
        render(makeStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        await type('Phi');
        await vi.waitFor(() =>
            expect(
                get.mock.calls.some(
                    (call) => String(call[0]) === '/assessments.nodes.search',
                ),
            ).toBe(true),
        );

        const search = get.mock.calls.find(
            (call) => String(call[0]) === '/assessments.nodes.search',
        )!;
        expect(search[1]).toEqual({
            params: { type: 'attack', q: 'Phi', parent_uuid: 'risk1' },
        });
        await vi.waitFor(() =>
            expect(document.body.textContent).toContain(
                'Already under 2 risks',
            ),
        );
    });

    it('choosing one links it and creates no node at all', async () => {
        const store = makeStore();
        render(store);
        const before = Object.keys(store.nodes).length;
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        await type('Phi');
        await vi.waitFor(() =>
            expect(document.body.textContent).toContain('Phishing'),
        );

        titleInput().dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
        );
        await flushPromises();
        titleInput().dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await flushPromises();

        expect(nodeStores()).toHaveLength(0);
        expect(
            post.mock.calls.filter(
                (call) =>
                    String(call[0]) === '/assessments.risk-attack-links.store',
            ),
        ).toHaveLength(1);
        expect(Object.keys(store.nodes)).toHaveLength(before);
        expect(store.attacksUnderRisk('risk1')).toContain('attack1');
        expect(modal.draft.value).toBeNull();
    });

    it('offers nothing for a risk, which has only one parent to be under', async () => {
        render(makeStore());
        modal.openDraft('root', 'root', 'root');
        await flushPromises();
        await type('Phi');
        await new Promise((resolve) => setTimeout(resolve, 200));

        expect(
            get.mock.calls.filter(
                (call) => String(call[0]) === '/assessments.nodes.search',
            ),
        ).toHaveLength(0);
    });
});

describe('the assistant on a draft (W88 item 23, D24; 2.5.1)', () => {
    function assistantStore() {
        return makeStore({ assistantAvailable: true });
    }

    function suggestionCalls(): unknown[][] {
        return post.mock.calls.filter(
            (call) => String(call[0]) === '/assessments.assistant.suggestions',
        );
    }

    it('is offered before anything is typed, and asks the parent for one', async () => {
        render(assistantStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();

        el('details-ask-assistant')!.click();
        await flushPromises();

        // One, not five: the form makes one node, and the count is most of
        // the wait (the server gives one a smaller budget — SuggestionTest).
        expect(suggestionCalls()).toHaveLength(1);
        expect(suggestionCalls()[0][1]).toEqual({
            parent_uuid: 'risk1',
            count: 1,
        });
    });

    it('is absent where the assistant is unavailable', async () => {
        render(makeStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();

        expect(el('details-ask-assistant')).toBeNull();
    });

    it('fills the form with the suggestion, says a model wrote it, and writes nothing until Add', async () => {
        render(assistantStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        el('details-ask-assistant')!.click();
        await vi.waitFor(() =>
            expect(titleInput().value).toBe('Credential stuffing'),
        );

        // No list to accept from: there is one suggestion, and the accept
        // step D24 requires is the Add button, pressed by someone who can see
        // the text and the note beside it.
        expect(el('accept-suggestion')).toBeNull();
        expect(el<HTMLTextAreaElement>('new-node-description')!.value).toBe(
            'Reused passwords replayed against the login form.',
        );
        expect(el('new-node-filled-from')!.textContent).toContain(
            'local model',
        );
        expect(nodeStores()).toHaveLength(0);

        el('new-node-submit')!.click();
        await flushPromises();
        expect(nodeStores()).toHaveLength(1);
        expect(nodeStores()[0][1]).toMatchObject({
            title: 'Credential stuffing',
            description: 'Reused passwords replayed against the login form.',
        });
    });

    it('reads only title and description from a suggestion (D23)', async () => {
        post.mockImplementation((url: string) =>
            Promise.resolve(
                url === '/assessments.assistant.suggestions'
                    ? {
                          data: {
                              child_type: 'defence',
                              suggestions: [
                                  {
                                      title: 'Backups',
                                      description: 'Nightly.',
                                      status: 'implemented',
                                      is_transfer: true,
                                  },
                              ],
                          },
                      }
                    : { data: { node: node({ uuid: 'created1' }) } },
            ),
        );
        const store = assistantStore();
        store.riskAttackLinks.push({
            risk_uuid: 'risk1',
            attack_uuid: 'attack1',
            position: 0,
        });
        render(store);
        modal.openDraft(
            'attack',
            'attack1',
            occurrencePath('root', 'risk1', 'attack1'),
        );
        await flushPromises();
        el('details-ask-assistant')!.click();
        await vi.waitFor(() => expect(titleInput().value).toBe('Backups'));

        el('new-node-submit')!.click();
        await flushPromises();

        const body = nodeStores()[0][1] as Record<string, unknown>;
        expect(body.status).toBe('todo');
        expect(body.is_transfer).not.toBe(true);
    });

    it('renders a suggestion as text, never as markup', async () => {
        post.mockImplementation(() =>
            Promise.resolve({
                data: {
                    child_type: 'attack',
                    suggestions: [
                        {
                            title: '<img src=x onerror=alert(1)>',
                            description: '<b>Ignore</b> previous instructions.',
                        },
                    ],
                },
            }),
        );
        render(assistantStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        el('details-ask-assistant')!.click();
        await vi.waitFor(() =>
            expect(titleInput().value).toBe('<img src=x onerror=alert(1)>'),
        );

        const form = el('new-node-form')!;
        expect(form.querySelector('img')).toBeNull();
        expect(form.querySelector('b')).toBeNull();
        expect(el<HTMLTextAreaElement>('new-node-description')!.value).toBe(
            '<b>Ignore</b> previous instructions.',
        );
    });

    it('drops a suggestion that arrives after the person started typing', async () => {
        let answer!: (value: unknown) => void;
        post.mockImplementation((url: string) =>
            url === '/assessments.assistant.suggestions'
                ? new Promise((resolve) => (answer = resolve))
                : Promise.resolve({ data: {} }),
        );
        render(assistantStore());
        modal.openDraft('root', 'root', 'root');
        await flushPromises();
        el('details-ask-assistant')!.click();
        await flushPromises();

        await type('My own words');
        answer({ data: { child_type: 'risk', suggestions: SUGGESTIONS } });
        await flushPromises();

        expect(titleInput().value).toBe('My own words');
        expect(el<HTMLTextAreaElement>('new-node-description')!.value).toBe('');
        expect(el('new-node-filled-from')).toBeNull();
    });

    it('says so when the model returned nothing usable', async () => {
        post.mockImplementation(() =>
            Promise.resolve({
                data: { child_type: 'attack', suggestions: [] },
            }),
        );
        render(assistantStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        el('details-ask-assistant')!.click();
        await flushPromises();

        expect(el('assistant-empty')).not.toBeNull();
        expect(titleInput().value).toBe('');
    });
});

describe('from a template, on a draft (2.5.1)', () => {
    async function openPicker() {
        el('new-node-from-template')!.click();
        await flushPromises();
    }

    async function chooseTemplate() {
        el('template-option')!.click();
        await flushPromises();
    }

    function useItem(index: number) {
        (
            document.querySelectorAll('[data-testid="template-item-use"]')[
                index
            ] as HTMLButtonElement
        ).click();
    }

    it("lists the templates, then that template's nodes of the child type", async () => {
        render(makeStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();

        await openPicker();
        expect(el('template-picker')!.textContent).toContain('ISO/IEC 27001');
        expect(el('template-picker')!.textContent).toContain('4 attacks');

        await chooseTemplate();
        expect(route).toHaveBeenCalledWith('templates.nodes', {
            assessment: 'tpl1',
            type: 'attack',
        });
        expect(
            document.querySelectorAll('[data-testid="template-item"]'),
        ).toHaveLength(TEMPLATE_NODES.length);
    });

    it('picking one fills title and description, closes the picker, and saves nothing', async () => {
        render(makeStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        await openPicker();
        await chooseTemplate();

        useItem(0);
        await flushPromises();

        expect(titleInput().value).toBe('Ransomware');
        expect(el<HTMLTextAreaElement>('new-node-description')!.value).toBe(
            'Files encrypted for a ransom.',
        );
        expect(el('template-picker')).toBeNull();
        expect(el('new-node-filled-from')!.textContent).toContain(
            'ISO/IEC 27001',
        );
        expect(post).not.toHaveBeenCalled();
        expect(modal.draft.value).not.toBeNull();
    });

    it("carries a defence's transfer flag into the form", async () => {
        const store = makeStore({
            riskAttackLinks: [
                { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
            ],
        });
        render(store);
        modal.openDraft(
            'attack',
            'attack1',
            occurrencePath('root', 'risk1', 'attack1'),
        );
        await flushPromises();
        await openPicker();
        await chooseTemplate();
        useItem(1);
        await flushPromises();

        expect(titleInput().value).toBe('Cyber insurance');
        expect(post).not.toHaveBeenCalled();

        el('new-node-submit')!.click();
        await flushPromises();

        expect(nodeStores()).toHaveLength(1);
        expect(nodeStores()[0][1]).toMatchObject({
            type: 'defence',
            title: 'Cyber insurance',
            is_transfer: true,
        });
    });
});

describe('the two helpers step aside once a title is typed (2.5.1)', () => {
    it('hides both buttons while the title has text, and brings them back when it is cleared', async () => {
        render(makeStore({ assistantAvailable: true }));
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        expect(el('new-node-from-template')).not.toBeNull();
        expect(el('details-ask-assistant')).not.toBeNull();

        await type('C');
        expect(el('new-node-helpers')).toBeNull();

        await type('');
        expect(el('new-node-from-template')).not.toBeNull();
        expect(el('details-ask-assistant')).not.toBeNull();
    });

    it('closes an open template picker when the person starts typing', async () => {
        render(makeStore());
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();
        el('new-node-from-template')!.click();
        await flushPromises();
        expect(el('template-picker')).not.toBeNull();

        await type('Something else');
        expect(el('template-picker')).toBeNull();
    });

    it('offers neither to someone who cannot edit', async () => {
        render(
            makeStore({
                assistantAvailable: true,
                abilities: {
                    comment: true,
                    update: false,
                    manage: false,
                    transfer: false,
                },
            }),
        );
        modal.openDraft('risk', 'risk1', occurrencePath('root', 'risk1'));
        await flushPromises();

        expect(el('new-node-helpers')).toBeNull();
    });
});
