/**
 * The admin **AI assistant** tab (2.5.1).
 *
 * Replaces four specs that mounted Instance settings to test the assistant
 * panel at its foot (W50's toggle, W75's permanent panel, W87's per-feature
 * switches, and D36's calibration select, which is gone). The rules they
 * pinned still hold and are re-asserted here, against the tab they moved to:
 *
 * - **No control where there is no runtime** (W50, D25). A toggle that cannot
 *   work is worse than no toggle.
 * - **The page still says the feature exists** (W75, D37) — now in one line,
 *   "AI service not installed", with a tooltip pointing to the README rather
 *   than a paragraph of docker commands. Installation is for whoever holds the
 *   compose file.
 * - **Each feature switch only subtracts** (W87, D48): disabled while the
 *   assistant itself is off.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Assistant from '../../../resources/js/Pages/Admin/Assistant.vue';
import Settings from '../../../resources/js/Pages/Admin/Settings.vue';

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Head: { template: '<div><slot /></div>' },
        useForm: (data: Record<string, unknown>) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                put: vi.fn(),
            }),
    };
});

const SETTINGS = {
    assistant_enabled: false,
    assistant_help_enabled: true,
    assistant_suggestions_enabled: true,
    assistant_summary_enabled: true,
};

interface RunningModel {
    file: string;
    preset: string | null;
    label: string | null;
    memory_gib: number | null;
}

function page(
    assistant: {
        configured: boolean;
        state: string | null;
        model: RunningModel | null;
        presets: {
            name: string;
            label: string;
            memory_gib: number;
            download_gb: number;
        }[];
    },
    settings: Partial<typeof SETTINGS> = {},
) {
    return mount(Assistant, {
        props: { settings: { ...SETTINGS, ...settings }, assistant },
        global: {
            stubs: { AdminLayout: { template: '<div><slot /></div>' } },
        },
    });
}

/** docker/assistant/models.conf, as the controller sends it (D52). */
const PRESETS = [
    {
        name: 'small',
        label: 'Gemma 4 E4B (4-bit, quantization-aware)',
        memory_gib: 6,
        download_gb: 4.2,
    },
    {
        name: 'large',
        label: 'Gemma 4 26B-A4B (4-bit, quantization-aware, mixture of experts)',
        memory_gib: 28,
        download_gb: 14.2,
    },
];

const SMALL: RunningModel = {
    file: 'gemma-4-E4B-it-qat-UD-Q4_K_XL.gguf',
    preset: 'small',
    label: 'Gemma 4 E4B (4-bit, quantization-aware)',
    memory_gib: 6,
};

const UNCONFIGURED = {
    configured: false,
    state: null,
    model: null,
    presets: [],
};
const CONFIGURED = {
    configured: true,
    state: 'disabled',
    model: SMALL,
    presets: PRESETS,
};

function prose(wrapper: ReturnType<typeof page>): string {
    return wrapper.text().replace(/\s+/g, ' ');
}

describe('a deployment with no inference runtime', () => {
    it('says "AI service not installed", and nothing about docker', () => {
        const text = prose(page(UNCONFIGURED));

        expect(text).toContain('AI service not installed');
        expect(text).not.toContain('docker');
        expect(text).not.toContain('ASSISTANT_ENABLED');
    });

    it('points to the README in a tooltip', async () => {
        const wrapper = page(UNCONFIGURED);
        const hint = wrapper.get('[data-testid="assistant-install-hint"]');

        expect(hint.attributes('aria-label')).toContain('README.md');

        await hint.trigger('focusin');
        expect(wrapper.get('[role="tooltip"]').text()).toContain('README.md');
    });

    it('offers no control at all (W50, D25)', () => {
        const wrapper = page(UNCONFIGURED);

        expect(wrapper.find('[role="switch"]').exists()).toBe(false);
        expect(prose(wrapper)).not.toContain('Enable the assistant');
    });

    it('still says what the feature is (W75, D37)', () => {
        const text = prose(page(UNCONFIGURED));

        expect(text).toContain('suggests risks, attacks and defences');
        expect(text).toContain('sends nothing off the machine');
        expect(text).toContain(
            'nothing it writes is used until a person accepts it',
        );
    });
});

describe('a deployment with a runtime', () => {
    it('offers the assistant switch and the three feature switches', () => {
        const text = prose(page(CONFIGURED));

        expect(text).toContain('Enable the assistant');
        expect(text).toContain('Answer questions on the help pages');
        expect(text).toContain('Suggest risks, attacks and defences');
        expect(text).toContain("Draft the report's executive summary");
    });

    it('names the model the runtime reports, and which preset it is (D55)', () => {
        const text = prose(page(CONFIGURED));

        expect(text).toContain(
            'Installed model: Gemma 4 E4B (4-bit, quantization-aware) (the small preset)',
        );
    });

    it('lists both presets with the memory each needs, marking the running one', () => {
        const list = page(CONFIGURED).get('[data-testid="assistant-presets"]');
        const [small, large] = list.findAll('li').map((li) => prose(li));

        expect(small).toContain('needs about 6 GiB of memory');
        expect(small).toContain('running');
        expect(large).toContain('needs about 28 GiB of memory');
        expect(large).not.toContain('running');
    });

    it('names a custom model by its file, and says it is not a preset', () => {
        const wrapper = page({
            ...CONFIGURED,
            model: {
                file: 'gemma-3-4b-it-Q4_K_M.gguf',
                preset: null,
                label: null,
                memory_gib: null,
            },
        });

        expect(prose(wrapper)).toContain(
            'Installed model: gemma-3-4b-it-Q4_K_M.gguf',
        );
        expect(
            wrapper.find('[data-testid="assistant-model-custom"]').exists(),
        ).toBe(true);
        expect(prose(wrapper)).not.toContain('running');
    });

    it('claims no model when the runtime has not answered', () => {
        const text = prose(page({ ...CONFIGURED, model: null }));

        expect(text).toContain(
            'Installed model: not known until the AI service responds',
        );
        expect(text).not.toContain(
            'Gemma 4 E4B (4-bit, quantization-aware) (the',
        );
    });

    it('points to the README for switching models, with no docker commands', () => {
        const wrapper = page(CONFIGURED);
        const hint = wrapper.get('[data-testid="assistant-switch-hint"]');

        expect(hint.attributes('aria-label')).toContain('README.md');
        expect(prose(wrapper)).not.toContain('docker');
        expect(prose(wrapper)).not.toContain('ASSISTANT_MODEL');
    });

    it('disables the three features while the assistant itself is off (D48)', () => {
        const fieldset = page(CONFIGURED).get(
            '[data-testid="assistant-features"]',
        );

        expect(fieldset.attributes('disabled')).toBeDefined();
    });

    it('enables them once the assistant is on', () => {
        const fieldset = page(CONFIGURED, { assistant_enabled: true }).get(
            '[data-testid="assistant-features"]',
        );

        expect(fieldset.attributes('disabled')).toBeUndefined();
    });

    it('warns when the AI service is not responding', () => {
        const wrapper = page(
            { ...CONFIGURED, state: 'unreachable' },
            { assistant_enabled: true },
        );

        expect(
            wrapper.find('[data-testid="assistant-unreachable"]').exists(),
        ).toBe(true);
    });
});

describe('Instance settings (2.5.1)', () => {
    it('no longer carries the assistant or a scale calibration', () => {
        const wrapper = mount(Settings, {
            props: {
                settings: {
                    public_assessments_allowed: true,
                    session_lifetime_minutes: 480,
                },
            },
            global: {
                stubs: { AdminLayout: { template: '<div><slot /></div>' } },
            },
        });
        const text = wrapper.text().toLowerCase();

        expect(text).not.toContain('assistant');
        expect(text).not.toContain('calibration');
        expect(text).toContain('allow public assessments');
    });
});
