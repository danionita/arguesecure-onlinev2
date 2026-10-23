/**
 * The Scales section of Assessment settings (W126, D94).
 *
 * Each test is one rule the owner listed: the dropdown lists every admin
 * scale and Custom; choosing a scale overwrites the editors without saving;
 * any edit switches the dropdown to Custom; one Save sends all three kinds.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AssessmentScales from '../../../resources/js/Components/Scales/AssessmentScales.vue';
import type {
    ScaleDetail,
    ScaleSetDetail,
} from '../../../resources/js/Components/Scales/bands';

const put = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        router: { put: (...a: unknown[]) => put(...a), on: () => () => {} },
    };
});

function scale(
    kind: ScaleDetail['kind'],
    unit: ScaleDetail['unit'],
    label: string,
): ScaleDetail {
    return {
        id: 1,
        kind,
        name: kind,
        unit,
        is_system: false,
        bands: [
            {
                ordinal: 1,
                code: 'low',
                label,
                lower_bound: 0,
                upper_bound: 1,
                representative: 0.5,
                suggested_representative: 0.5,
            },
            {
                ordinal: 2,
                code: 'high',
                label: `${label} high`,
                lower_bound: 1,
                upper_bound: null,
                representative: 3,
                suggested_representative: 3,
            },
        ],
    };
}

function trio(label: string): Record<string, ScaleDetail> {
    return {
        impact: scale('impact', 'currency', `${label} impact`),
        likelihood: scale('likelihood', 'rate_per_year', `${label} likelihood`),
        strength: scale('strength', 'fraction', `${label} strength`),
    };
}

const sets: ScaleSetDetail[] = [
    { key: 'default', name: 'Standard', is_builtin: true, scales: trio('Std') },
    {
        key: 'small-organisation',
        name: 'Small organisation',
        is_builtin: true,
        scales: trio('Small'),
    },
    { key: 'acme', name: 'Acme', is_builtin: false, scales: trio('Acme') },
];

function section(scaleSetKey: string | null = 'default') {
    return mount(AssessmentScales, {
        props: {
            scales: trio('Std'),
            scaleSets: sets,
            scaleSetKey,
            saveUrl: '/a/scales',
            currency: 'EUR',
            topBandMultiplier: 3,
            errors: {},
        },
    });
}

function select(wrapper: ReturnType<typeof section>) {
    return wrapper.find('select');
}

function labels(wrapper: ReturnType<typeof section>, kind: string) {
    return wrapper
        .findAll(`[data-scale="${kind}"] [aria-label^="Label for band"] input`)
        .map((i) => (i.element as HTMLInputElement).value);
}

beforeEach(() => put.mockClear());

describe('the dropdown', () => {
    it('lists every admin scale, then Custom last', () => {
        const options = select(section())
            .findAll('option')
            .map((o) => o.text());

        expect(options).toEqual([
            'Standard',
            'Small organisation',
            'Acme',
            'Custom',
        ]);
    });

    it('starts on the scale the assessment was last set to', () => {
        expect(select(section('small-organisation')).element.value).toBe(
            'small-organisation',
        );
    });

    it('starts on Custom when there is no stored scale, or the stored one no longer exists', () => {
        expect(select(section(null)).element.value).toBe('custom');
        expect(select(section('deleted-scale')).element.value).toBe('custom');
    });

    it('switches to Custom on any edit, however small', async () => {
        const wrapper = section();
        expect(select(wrapper).element.value).toBe('default');

        await wrapper
            .find('[data-scale="impact"] [aria-label="Label for band 1"] input')
            .setValue('Renamed');

        expect(select(wrapper).element.value).toBe('custom');
    });

    it('switches to Custom when a band is added', async () => {
        const wrapper = section();

        await wrapper
            .find(
                '[data-scale="likelihood"] button[aria-label="Split this band in two"]',
            )
            .trigger('click');

        expect(select(wrapper).element.value).toBe('custom');
    });
});

describe('choosing a scale', () => {
    it('overwrites the editors with that scale and sends nothing', async () => {
        const wrapper = section();

        await select(wrapper).setValue('acme');

        expect(labels(wrapper, 'impact')[0]).toBe('Acme impact');
        expect(labels(wrapper, 'likelihood')[0]).toBe('Acme likelihood');
        expect(labels(wrapper, 'strength')[0]).toBe('Acme strength');
        expect(select(wrapper).element.value).toBe('acme');
        expect(put).not.toHaveBeenCalled();
    });

    it('says it is not saved yet, and stops saying so once the stored bands are back', async () => {
        const wrapper = section();
        expect(wrapper.find('[data-testid="unsaved"]').exists()).toBe(false);

        await select(wrapper).setValue('acme');
        expect(wrapper.find('[data-testid="unsaved"]').exists()).toBe(true);

        await select(wrapper).setValue('default');
        expect(wrapper.find('[data-testid="unsaved"]').exists()).toBe(false);
    });

    it('offers no Reset to default', () => {
        const wrapper = section();

        expect(wrapper.text()).not.toContain('Reset to default');
        expect(wrapper.find('[data-testid^="reset"]').exists()).toBe(false);
    });
});

describe('saving', () => {
    it('sends all three kinds in one request, carrying codes', async () => {
        const wrapper = section();
        await select(wrapper).setValue('acme');

        await wrapper.find('[data-testid="save-scales"]').trigger('click');

        expect(put).toHaveBeenCalledTimes(1);
        const [url, payload] = put.mock.calls[0];
        const scales = (
            payload as {
                scales: Record<
                    string,
                    { bands: { code: string; label: string }[] }
                >;
            }
        ).scales;

        expect(url).toBe('/a/scales');
        expect(Object.keys(scales).sort()).toEqual([
            'impact',
            'likelihood',
            'strength',
        ]);
        expect(scales.impact.bands.map((b) => b.label)).toEqual([
            'Acme impact',
            'Acme impact high',
        ]);
        expect(scales.strength.bands.map((b) => b.code)).toEqual([
            'low',
            'high',
        ]);
    });

    it('sends the edited bands, not the chosen scale, once an edit has been made', async () => {
        const wrapper = section();
        await select(wrapper).setValue('acme');
        await wrapper
            .find('[data-scale="impact"] [aria-label="Label for band 1"] input')
            .setValue('Mine');

        await wrapper.find('[data-testid="save-scales"]').trigger('click');

        const payload = put.mock.calls[0][1] as {
            scales: Record<string, { bands: { label: string }[] }>;
        };
        expect(payload.scales.impact.bands[0].label).toBe('Mine');
        expect(payload.scales.likelihood.bands[0].label).toBe(
            'Acme likelihood',
        );
    });

    it('shows the server error for a rejected save', () => {
        const wrapper = mount(AssessmentScales, {
            props: {
                scales: trio('Std'),
                scaleSets: sets,
                scaleSetKey: 'default',
                saveUrl: '/a/scales',
                currency: 'EUR',
                topBandMultiplier: 3,
                errors: { bands: 'Bands must be contiguous.' },
            },
        });

        expect(wrapper.find('[data-testid="band-errors"]').text()).toBe(
            'Bands must be contiguous.',
        );
    });
});
