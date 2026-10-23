/**
 * The three band editors, relocated and reduced (W87, item 10; D44-D47) and
 * made a controlled component in W126 (D94): they edit a draft the parent
 * owns and save nothing themselves.
 *
 * What is asserted here is mostly what is **absent**, which needs saying
 * because an absence assertion is easy to write badly. Each one below names
 * the specific control that went and why, and checks a positive alongside it —
 * "the code input is gone" passing on a component that failed to mount would
 * be worthless, so every test that asserts an absence also asserts that the
 * editor is really there.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import {
    draftFrom,
    type ScaleDetail,
} from '../../../resources/js/Components/Scales/bands';
import ScaleCards from '../../../resources/js/Components/Scales/ScaleCards.vue';

function impact(isSystem = true): ScaleDetail {
    return {
        id: 1,
        kind: 'impact',
        name: 'Impact',
        unit: 'currency',
        is_system: isSystem,
        bands: [
            {
                ordinal: 1,
                code: 'negligible',
                label: 'Negligible',
                lower_bound: 0,
                upper_bound: 1_000,
                representative: 250,
                suggested_representative: 250,
            },
            {
                ordinal: 2,
                code: 'moderate',
                label: 'Moderate',
                lower_bound: 1_000,
                upper_bound: null,
                representative: 3_000,
                suggested_representative: 3_000,
            },
        ],
    };
}

function cards(overrides: Record<string, unknown> = {}) {
    const scales = {
        impact: impact(),
        likelihood: null,
        strength: null,
    };

    return mount(ScaleCards, {
        props: {
            scales,
            modelValue: draftFrom(scales),
            currency: 'EUR',
            topBandMultiplier: 3,
            ...overrides,
        },
    });
}

describe('what the editor no longer asks for', () => {
    it('has no code input and renders no level code (D44)', () => {
        const wrapper = cards();

        // Matched on the attribute alone: the shared Input component puts
        // `aria-label` on its wrapper div, not on the field, so a narrower
        // selector would match nothing even when the control is there.
        expect(wrapper.find('[aria-label="Label for band 1"]').exists()).toBe(
            true,
        );

        expect(wrapper.find('[aria-label^="Code for"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('negligible');
        expect(wrapper.text()).not.toContain('moderate');
        expect(wrapper.html()).not.toContain('negligible');
    });

    it('has no colour input (D45)', () => {
        const wrapper = cards();

        expect(wrapper.find('input[type="color"]').exists()).toBe(false);
        expect(wrapper.find('[aria-label^="Colour for"]').exists()).toBe(false);
        expect(wrapper.find('table').exists()).toBe(true);
    });

    it('has no live-preview calculator at the foot of a card', () => {
        const wrapper = cards();

        expect(wrapper.find('[data-testid="preview-impact"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).not.toContain('A loss of');
        expect(wrapper.text()).not.toContain('in the calculation');
    });

    it('has no warning, no origin chip and no save or reset button of its own (W126)', () => {
        const wrapper = cards();

        expect(
            wrapper.find('[data-testid="uncalibrated-warning"]').exists(),
        ).toBe(false);
        expect(wrapper.text()).not.toContain('Instance default');
        expect(wrapper.text()).not.toContain('own');
        // Saving is the parent's one button for all three kinds.
        expect(wrapper.find('[data-testid="save-impact"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="reset-impact"]').exists()).toBe(
            false,
        );
        // …and the editor is really there.
        expect(wrapper.find('table').exists()).toBe(true);
    });
});

describe('editing', () => {
    it('emits the whole draft with the edited kind replaced, carrying codes untouched', async () => {
        const wrapper = cards();

        await wrapper
            .find('[aria-label="Label for band 1"] input')
            .setValue('Trivial');

        const emitted = wrapper.emitted('update:modelValue');
        expect(emitted).toHaveLength(1);

        const draft = emitted![0][0] as Record<
            string,
            { code: string | null; label: string }[]
        >;
        expect(draft.impact.map((b) => b.label)).toEqual([
            'Trivial',
            'Moderate',
        ]);
        expect(draft.impact.map((b) => b.code)).toEqual([
            'negligible',
            'moderate',
        ]);
        // The kinds it was not editing come back as they were.
        expect(draft.likelihood).toEqual([]);
    });

    it('gives a band the editor has just created a null code, never an invented one', async () => {
        const wrapper = cards();

        await wrapper
            .find('button[aria-label="Split this band in two"]')
            .trigger('click');

        const draft = wrapper.emitted('update:modelValue')![0][0] as Record<
            string,
            { code: string | null }[]
        >;
        const codes = draft.impact.map((b) => b.code);

        expect(codes).toContain(null);
        expect(codes).toContain('negligible');
        for (const band of draft.impact) {
            expect(band).not.toHaveProperty('color');
        }
    });
});
