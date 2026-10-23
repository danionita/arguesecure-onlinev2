/**
 * W72 item 10 — the target of assessment gets a Scales tab.
 *
 * The brief's one hard constraint is that surfacing these settings must not
 * create a second write path to the same columns, so the assertions that
 * matter here are about *which endpoint* is used, not about the markup: the
 * tab must read `assessments.settings.edit` and write
 * `assessments.settings.update`, the same controller and the same FormRequest
 * the settings page has used since W44. A test that only checked "a currency
 * select renders" would pass just as happily against a parallel endpoint,
 * which is the failure this file exists to make impossible.
 */
import { hasUnsavedChanges } from '@/composables/useUnsavedChanges';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import NodeModal from '../../../resources/js/Components/NodeModal/NodeModal.vue';
import ScalesTab from '../../../resources/js/Components/NodeModal/tabs/ScalesTab.vue';
import { useNodeModal } from '../../../resources/js/Components/NodeModal/useNodeModal';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    type AssessmentStore,
} from '../../../resources/js/stores/assessmentStore';
import { makeComputedStore, makeInit, SCALE_LEVELS, SCALES } from './fixture';

vi.mock('@inertiajs/vue3', () => ({
    router: { on: () => () => {} },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({
        props: { scaleLevels: SCALE_LEVELS, scales: SCALES },
        version: 'asset-version',
    }),
}));

/** What AssessmentSettingsController::edit sends, in its own shape. */
const SETTINGS_PROPS = {
    assessment: {
        uuid: 'assessment-uuid',
        title: 'Web shop',
        description: 'The public storefront.',
        currency: 'EUR',
        amortization_years: 3,
        risk_tolerance: null,
    },
    currencies: { EUR: 'Euro', USD: 'US dollar' },
    maxAmortizationYears: 10,
    /*
     * The shape `AppDomainScalesScaleDescriber` actually emits (W87).
     *
     * This fixture declared `levels: { code, label }[]` — what the endpoint
     * sent before W87 replaced it with `ScaleDescriber`. The server now sends
     * `bands`, with the bounds and the representative, so the old fixture was
     * a fiction: the tab crashed on a live payload while every assertion here
     * passed against data no server produces. A fixture that has drifted from
     * the server is worse than no test, so this one mirrors the real payload
     * key for key, `code` included — it is in the payload, as the round-trip
     * token that freezes a code across a rename, and it is drawn nowhere.
     */
    scales: {
        impact: {
            id: 1,
            kind: 'impact',
            name: 'Impact',
            unit: 'currency',
            is_system: false,
            bands: [
                {
                    ordinal: 1,
                    code: 'sev',
                    label: 'Severe',
                    lower_bound: 0,
                    upper_bound: null,
                    representative: 1000,
                    suggested_representative: 1000,
                },
            ],
        },
        likelihood: null,
        strength: {
            id: 3,
            kind: 'strength',
            name: 'Defence strength',
            unit: 'fraction',
            is_system: true,
            bands: [
                {
                    ordinal: 1,
                    code: 'partial',
                    label: 'Partial',
                    lower_bound: 0,
                    upper_bound: 1,
                    representative: 0.5,
                    suggested_representative: 0.5,
                },
            ],
        },
    },
};

let get: ReturnType<typeof vi.fn>;
let patch: ReturnType<typeof vi.fn>;

/**
 * `route()` reaches a template as a Vue *global property* — that is what
 * ZiggyVue installs in app.ts — so setting `globalThis.route` alone leaves the
 * compiled template resolving `_ctx.route`. Both are set: the script block
 * reads the bare global, the template reads the global property.
 */
const route = (name: string, param?: string) => `/${name}/${param ?? ''}`;

/** The modal teleports to `body`; unmount before clearing it, or Vue patches
 *  a detached tree on the next tick and throws where no test can see it. */
let mounted: VueWrapper[] = [];

afterEach(() => {
    for (const wrapper of mounted) wrapper.unmount();
    mounted = [];
    document.body.innerHTML = '';
});

beforeEach(() => {
    useNodeModal().close();
    window.history.replaceState(null, '', '/assessments/abc');

    get = vi.fn().mockResolvedValue({ data: { props: SETTINGS_PROPS } });
    patch = vi.fn().mockResolvedValue({ data: {} });

    (globalThis as unknown as { route: typeof route }).route = route;
    (window as unknown as { axios: unknown }).axios = {
        get,
        patch,
        post: vi.fn(),
        delete: vi.fn(),
    };
});

function mountTab(store: AssessmentStore = makeComputedStore()) {
    const wrapper = mount(ScalesTab, {
        props: { store, uuid: 'root' },
        global: { config: { globalProperties: { route } } },
    });
    mounted.push(wrapper);

    return wrapper;
}

describe('the Scales tab guards unsaved edits (W142)', () => {
    it('is unsaved once a field differs from the store, and clean again after Save', async () => {
        const wrapper = mountTab();
        await flushPromises();
        expect(hasUnsavedChanges()).toBe(false);

        await wrapper.get('#scales-tab-currency').setValue('USD');
        expect(hasUnsavedChanges()).toBe(true);

        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(hasUnsavedChanges()).toBe(false);
    });
});

describe('the Scales tab dirty check is not fooled by number formatting', () => {
    it('is clean after saving a tolerance typed as 5000.0', async () => {
        const wrapper = mountTab();
        await flushPromises();

        const inputs = wrapper.findAll('input');
        await inputs[inputs.length - 1].setValue('5000.0');
        expect(hasUnsavedChanges()).toBe(true);
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(hasUnsavedChanges()).toBe(false);
    });
});

describe('the Scales tab reads and writes the settings controller', () => {
    it('asks the settings controller for its own props rather than a new endpoint', async () => {
        mountTab();
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        const [url, config] = get.mock.calls[0] as [
            string,
            { headers: Record<string, string> },
        ];
        expect(url).toContain('assessments.settings.edit');
        // The Inertia headers are what make that controller answer in JSON
        // instead of a full HTML document.
        expect(config.headers['X-Inertia']).toBe('true');
        expect(config.headers['X-Inertia-Version']).toBe('asset-version');
    });

    it('renders each band by label, and never by its code', async () => {
        const wrapper = mountTab();
        await flushPromises();

        // From `bands`, which is what ScaleDescriber sends. Reading `levels`
        // here — the key this endpoint sent before W87 — is not a missing row
        // but a TypeError in the render function, so these assertions stand in
        // for the whole tab loading at all.
        expect(wrapper.text()).toContain('Severe');
        expect(wrapper.text()).toContain('Partial');
        expect(wrapper.text()).toContain('shared across assessments');
        expect(wrapper.text()).toContain('Not configured.');

        // `code` is in the payload — it is the round-trip token that freezes a
        // code across a rename (D44) — and is drawn nowhere.
        expect(wrapper.text()).not.toContain('sev');
        expect(wrapper.text()).not.toContain('partial');
    });

    it('links to the settings screen, which is where bands are edited now', async () => {
        const wrapper = mountTab();
        await flushPromises();

        // W87/D46 deleted `assessments.scales.edit`. A link left pointing at
        // it does not degrade — `route()` throws on an unknown name, so the
        // whole tab would fail to render.
        expect(
            wrapper.get('a[href*="assessments.settings.edit"]'),
        ).toBeTruthy();
    });

    it('patches the settings route, carrying the fields the FormRequest requires', async () => {
        const wrapper = mountTab();
        await flushPromises();

        await wrapper.get('#scales-tab-currency').setValue('USD');
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(patch).toHaveBeenCalledTimes(1);
        const [url, body] = patch.mock.calls[0] as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toContain('assessments.settings.update');
        expect(body).toEqual({
            // Required by UpdateAssessmentSettingsRequest and taken from the
            // root node this tab is open on, unchanged. Omitting them would
            // fail validation rather than leave them alone.
            title: 'Web shop',
            description: null,
            currency: 'USD',
            amortization_years: 3,
            risk_tolerance: null,
        });
    });

    it('sends an empty tolerance as null, because "none" is not zero', async () => {
        const wrapper = mountTab();
        await flushPromises();

        const inputs = wrapper.findAll('input[type="number"]');
        await inputs[0].setValue('');
        await wrapper.get('button').trigger('click');
        await flushPromises();

        const [, body] = patch.mock.calls[0] as [
            string,
            Record<string, unknown>,
        ];
        expect(body.risk_tolerance).toBeNull();
    });

    it('recomputes when the amortisation period moves, because capex is spread over it', async () => {
        const store = makeComputedStore();
        const wrapper = mountTab(store);
        await flushPromises();

        const inputs = wrapper.findAll('input[type="number"]');
        await inputs[1].setValue('5');
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(store.assessment.amortization_years).toBe(5);
        expect(store.assessment.currency).toBe('EUR');
    });

    it('surfaces the validation message the FormRequest returned', async () => {
        patch.mockRejectedValueOnce({
            response: {
                data: {
                    errors: {
                        amortization_years: [
                            'Amortisation is capped at 10 years.',
                        ],
                    },
                },
            },
        });
        const wrapper = mountTab();
        await flushPromises();

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Amortisation is capped at 10 years.');
    });

    it('offers the settings screen when it cannot load them here', async () => {
        // 403 for a reader, 409 if the asset version moved under a long-open
        // modal. Either way the page still works, and pointing at it beats a
        // blank panel.
        get.mockRejectedValueOnce(new Error('403'));
        const wrapper = mountTab();
        await flushPromises();

        expect(wrapper.text()).toContain('could not be loaded here');
        expect(
            wrapper.get('a[href*="assessments.settings.edit"]'),
        ).toBeTruthy();
    });
});

describe('the tab appears on the root, and only for someone who may manage', () => {
    function renderModal(manage: boolean): void {
        const init = makeInit();
        init.abilities = { ...init.abilities, manage };
        const store = createAssessmentStore(init);
        store.recomputeLocally();
        useNodeModal().open('root');
        const wrapper = mount(NodeModal, {
            global: {
                provide: { [ASSESSMENT_STORE_KEY as symbol]: store },
                stubs: { CommentThread: true, RevisionList: true },
                config: { globalProperties: { route } },
            },
        });
        mounted.push(wrapper);
    }

    function tabLabels(): string[] {
        return Array.from(document.querySelectorAll('[role="tab"]')).map(
            (tab) => (tab.textContent ?? '').replace(/\s*\?\s*$/, '').trim(),
        );
    }

    it('gives a manager the Scales tab, in one place in the strip', () => {
        renderModal(true);

        // The whole set, in order — the same assertion shape the rest of
        // tabs.spec.ts uses, so a strip that failed to render cannot pass.
        expect(tabLabels()).toEqual([
            'Details',
            'Scales',
            'Statistics',
            'References',
            'Assignees',
            'Comments',
            'History',
        ]);
    });

    it('withholds it from someone who may edit but not manage', () => {
        // Courtesy, not authorization: UpdateAssessmentSettingsRequest is
        // gated on `manage` and refuses independently. The value asserted is
        // the ability named in that FormRequest, not "some ability".
        renderModal(false);

        expect(tabLabels()).not.toContain('Scales');
    });
});
