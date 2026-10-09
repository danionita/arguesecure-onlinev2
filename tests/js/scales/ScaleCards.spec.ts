/**
 * The three scale cards, relocated and reduced (W87, item 10; D44-D47).
 *
 * What is asserted here is mostly what is **absent**, which needs saying
 * because an absence assertion is easy to write badly. Each one below names
 * the specific control that went and why, and checks a positive alongside it —
 * "the code input is gone" passing on a component that failed to mount would
 * be worthless, so every test that asserts an absence also asserts that the
 * editor is really there.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { ScaleDetail } from '../../../resources/js/Components/Scales/bands';
import ScaleCards from '../../../resources/js/Components/Scales/ScaleCards.vue';

const put = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        router: {
            put: (...args: unknown[]) => put(...args),
        },
    };
});

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
    return mount(ScaleCards, {
        props: {
            scales: { impact: impact(), likelihood: null, strength: null },
            currency: 'EUR',
            topBandMultiplier: 3,
            saveUrls: {
                impact: '/a/scales/impact',
                likelihood: '/a/scales/likelihood',
                strength: '/a/scales/strength',
            },
            ...overrides,
        },
    });
}

describe('what the editor no longer asks for', () => {
    it('has no code input and renders no level code (D44)', () => {
        const wrapper = cards();

        // Present: the label input is the one text field a band has left.
        //
        // Matched on the attribute alone rather than on `input[aria-label=…]`.
        // The shared Input component puts `aria-label` on its wrapper div, not
        // on the field, so the narrower selector matches nothing even when the
        // control is there — and the absence assertions below would then be
        // passing for the wrong reason.
        expect(wrapper.find('[aria-label="Label for band 1"]').exists()).toBe(
            true,
        );

        // Absent: the code, in every form it could take. `code` is internal —
        // derived from the label on create and frozen on rename — so a user
        // who renames a band must never see the old string reappear.
        expect(wrapper.find('[aria-label^="Code for"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('negligible');
        expect(wrapper.text()).not.toContain('moderate');
        expect(wrapper.html()).not.toContain('negligible');
    });

    it('has no colour input (D45)', () => {
        const wrapper = cards();

        expect(wrapper.find('input[type="color"]').exists()).toBe(false);
        expect(wrapper.find('[aria-label^="Colour for"]').exists()).toBe(false);
        // …and the band table is still rendered, so this is not passing
        // because nothing mounted.
        expect(wrapper.find('table').exists()).toBe(true);
    });

    it('has no live-preview calculator at the foot of a card', () => {
        const wrapper = cards();

        // The "a loss of ___ falls in moderate, worth 31,623" row. It answered
        // a question the table of ranges above it already answers, and it was
        // the only reason a colour reached the client at all.
        expect(wrapper.find('[data-testid="preview-impact"]').exists()).toBe(
            false,
        );
        // Not a bare 'falls in': BandTable's own footnote explains that a
        // value on a boundary falls in the higher band, which is a rule about
        // the table and not a preview of a typed number.
        expect(wrapper.text()).not.toContain('A loss of');
        expect(wrapper.text()).not.toContain('in the calculation');
    });

    it('does not warn about using the system defaults', () => {
        const wrapper = cards({ showOrigin: true });

        expect(
            wrapper.find('[data-testid="uncalibrated-warning"]').exists(),
        ).toBe(false);
        expect(wrapper.text()).not.toContain('Still using the system defaults');

        // What the flag actually means is still stated — as a fact about what
        // a save will do, not as a warning that something is wrong.
        expect(wrapper.text()).toContain('Instance default');
    });
});

describe('saving', () => {
    it('puts the bands for one scale at a time, carrying the code untouched', async () => {
        put.mockClear();
        const wrapper = cards();

        await wrapper.find('[data-testid="save-impact"]').trigger('click');

        expect(put).toHaveBeenCalledTimes(1);
        const [url, payload] = put.mock.calls[0];

        expect(url).toBe('/a/scales/impact');
        expect(
            (payload as { bands: { code: string | null }[] }).bands.map(
                (b) => b.code,
            ),
        ).toEqual(['negligible', 'moderate']);
    });

    it('sends a null code for a band the editor has just created', async () => {
        put.mockClear();
        const wrapper = cards();

        // Split the first band. The new one has no row in the database and so
        // has no code; naming it here would be a second implementation of a
        // rule the unique index is the authority on.
        await wrapper
            .find('button[aria-label="Split this band in two"]')
            .trigger('click');
        await wrapper.find('[data-testid="save-impact"]').trigger('click');

        const [, payload] = put.mock.calls[0];
        const codes = (
            payload as { bands: { code: string | null }[] }
        ).bands.map((b) => b.code);

        expect(codes).toContain(null);
        expect(codes).toContain('negligible');
    });

    it('sends no colour, so the column keeps its default', async () => {
        put.mockClear();
        const wrapper = cards();

        await wrapper.find('[data-testid="save-impact"]').trigger('click');

        const [, payload] = put.mock.calls[0];

        for (const band of (payload as { bands: Record<string, unknown>[] })
            .bands) {
            expect(band).not.toHaveProperty('color');
        }
    });

    /*
     * Two saves from **one mounted instance**, which is the case `draft` does
     * not defend against on its own.
     *
     * `draft` is built once, in `reactive(...)`, from the props the component
     * mounted with. A band the editor created carries `code: null`, and the
     * server derives a code for a null — so if the same draft were posted
     * twice, the second post would present that band as new again, be given a
     * *second* derived code, and land as a duplicate band alongside a row
     * whose values had just been cleared.
     *
     * In the application this cannot happen: Inertia's vue3 adapter remounts
     * the page component on a normal visit, so the second save starts from a
     * draft rebuilt out of the server's response, in which the band has a real
     * code. But that safety lives in the adapter rather than in anything here,
     * and it is invisible from this file — so what is pinned is the shape of
     * the payload rather than a promise about remounting. If someone later
     * adds `preserveState` to keep scroll position, or hoists `draft` behind a
     * store, this test is what says the second save is now wrong.
     */
    it('re-sends the same null code when one mounted instance saves twice', async () => {
        put.mockClear();
        const wrapper = cards();

        await wrapper
            .find('button[aria-label="Split this band in two"]')
            .trigger('click');

        await wrapper.find('[data-testid="save-impact"]').trigger('click');

        // The first request's `onFinish` clears `busy`, which is what
        // re-enables the button — the card disables every save while one is in
        // flight. Without the tick the second click lands on a disabled button
        // and the test passes for the wrong reason.
        (put.mock.calls[0][2] as { onFinish: () => void }).onFinish();
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-impact"]').trigger('click');

        expect(put).toHaveBeenCalledTimes(2);

        const codesOf = (call: number) =>
            (
                put.mock.calls[call][1] as { bands: { code: string | null }[] }
            ).bands.map((b) => b.code);

        // Identical, null included. The component has not invented a code for
        // the created band between the two posts — inventing one is the
        // failure this documents, because a client-side guess cannot see the
        // codes of bands it is not editing and `(scale_id, code)` is the
        // index it would collide with.
        expect(codesOf(1)).toEqual(codesOf(0));
        expect(codesOf(1)).toContain(null);
    });
});

describe('reset', () => {
    it('offers a reset per card when it is given somewhere to reset to', () => {
        const wrapper = cards({
            resetUrls: {
                impact: '/a/scales/impact/reset',
                likelihood: '/a/scales/likelihood/reset',
                strength: '/a/scales/strength/reset',
            },
        });

        expect(wrapper.find('[data-testid="reset-impact"]').exists()).toBe(
            true,
        );
    });

    it('offers none where there is nothing above it to reset to', () => {
        // The admin Defaults tab *is* the default. A reset there would need a
        // default behind the default, which does not exist.
        const wrapper = cards();

        expect(wrapper.find('[data-testid="reset-impact"]').exists()).toBe(
            false,
        );
        // The save is still offered, so the card is not simply missing.
        expect(wrapper.find('[data-testid="save-impact"]').exists()).toBe(true);
    });

    it('puts to the reset url rather than sending the bands on screen', async () => {
        put.mockClear();
        const wrapper = cards({
            resetUrls: {
                impact: '/a/scales/impact/reset',
                likelihood: '/a/scales/likelihood/reset',
                strength: '/a/scales/strength/reset',
            },
        });

        await wrapper.find('[data-testid="reset-impact"]').trigger('click');

        const [url, payload] = put.mock.calls[0];

        // The server reads the instance default; sending the edited bands
        // would make "reset" mean "save what is on screen".
        expect(url).toBe('/a/scales/impact/reset');
        expect(payload).toEqual({});
    });
});

describe('origin', () => {
    it('says an assessment scale is its own once it has been calibrated', () => {
        const wrapper = cards({
            scales: {
                impact: impact(false),
                likelihood: null,
                strength: null,
            },
            showOrigin: true,
        });

        expect(wrapper.text()).toContain('This assessment’s own');
        expect(wrapper.text()).not.toContain('Instance default');
    });

    it('says nothing about origin where every scale is shared by definition', () => {
        // The admin tab. A chip saying "instance default" on all three cards
        // would be noise.
        const wrapper = cards({ showOrigin: false });

        expect(wrapper.text()).not.toContain('Instance default');
        expect(wrapper.text()).not.toContain('This assessment’s own');
    });
});
