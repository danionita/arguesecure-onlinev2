import { describe, expect, it } from 'vitest';
import {
    bandFor,
    moveBoundary,
    removeBand,
    splitBand,
    suggestRepresentative,
    type EditableBand,
} from '../../../resources/js/Components/Scales/bands';

/**
 * The band-editing rules behind the calibration screen (W65, ui.md §8).
 *
 * ui.md's instruction is the design: "the invariants are non-negotiable, so
 * **make them impossible to violate rather than validating after the fact**."
 * So what is asserted here is not that bad input is rejected — there is no
 * rejection path to test — but that every operation the screen offers *leaves*
 * the set valid. A user cannot reach a scale with a gap in it, because no
 * button produces one.
 *
 * `ScaleValidator` remains the authority server-side. It should never fire from
 * this screen; if it does, the defect is here.
 */

const MULTIPLIER = 3;

/** The seeded impact scale, which is what a first calibration always starts from. */
function impactBands(): EditableBand[] {
    return [
        {
            code: 'negligible',
            label: 'Negligible',
            lower_bound: 0,
            upper_bound: 1_000,
            representative: 250,
        },
        {
            code: 'minor',
            label: 'Minor',
            lower_bound: 1_000,
            upper_bound: 10_000,
            representative: 3_162,
        },
        {
            code: 'moderate',
            label: 'Moderate',
            lower_bound: 10_000,
            upper_bound: 100_000,
            representative: 31_623,
        },
        {
            code: 'severe',
            label: 'Severe',
            lower_bound: 100_000,
            upper_bound: null,
            representative: 300_000,
        },
    ];
}

function strengthBands(): EditableBand[] {
    return [
        {
            code: 'weak',
            label: 'Weak',
            lower_bound: 0,
            upper_bound: 0.5,
            representative: 0.25,
        },
        {
            code: 'strong',
            label: 'Strong',
            lower_bound: 0.5,
            upper_bound: 1,
            representative: 0.75,
        },
    ];
}

/** The five invariants of schema.md §3.2, as one assertion. */
function expectValid(bands: EditableBand[], unit: 'currency' | 'fraction') {
    expect(bands[0].lower_bound).toBe(0);

    for (let i = 0; i < bands.length - 1; i++) {
        expect(bands[i].upper_bound).toBe(bands[i + 1].lower_bound);
    }

    const top = bands[bands.length - 1];
    expect(top.upper_bound).toBe(unit === 'fraction' ? 1 : null);

    for (const band of bands) {
        expect(band.representative).toBeGreaterThanOrEqual(band.lower_bound);
        if (band.upper_bound !== null) {
            const inclusive = band === top && unit === 'fraction';
            if (inclusive) {
                expect(band.representative).toBeLessThanOrEqual(
                    band.upper_bound,
                );
            } else {
                expect(band.representative).toBeLessThan(band.upper_bound);
            }
        }
    }

    // Only the carried codes. A band this module created carries `null` and is
    // named server-side (W87, D44), and several nulls collapsing to one set
    // entry is not a collision — it is the absence of a code.
    const carried = bands.map((b) => b.code).filter((c) => c !== null);
    expect(new Set(carried).size).toBe(carried.length);
}

describe('suggestRepresentative — schema.md §3.2.1', () => {
    it('uses the geometric mean for a currency band', () => {
        // 31,623 is what the seeder stores for 10k–100k, and it is the middle
        // of that range as someone reasoning in orders of magnitude reads it.
        // The arithmetic midpoint would be 55,000.
        expect(
            suggestRepresentative('currency', 10_000, 100_000, MULTIPLIER),
        ).toBeCloseTo(31_623, 0);
    });

    it('uses a quarter of the upper bound when the band starts at zero', () => {
        // √(0 × 1000) is 0, which would sit exactly on the lower bound and make
        // every value in the lowest band worth nothing at all.
        expect(suggestRepresentative('currency', 0, 1_000, MULTIPLIER)).toBe(
            250,
        );
    });

    it('multiplies the lower bound for the open top band', () => {
        expect(
            suggestRepresentative('currency', 1_000_000, null, MULTIPLIER),
        ).toBe(3_000_000);
    });

    it('uses the arithmetic midpoint for a fraction', () => {
        // A fraction is bounded both sides and roughly uniform inside a band; a
        // geometric mean would collapse toward zero for the low bands where
        // most controls sit.
        expect(
            suggestRepresentative('fraction', 0.5, 0.8, MULTIPLIER),
        ).toBeCloseTo(0.65, 10);
    });

    it('never suggests a value outside its own band', () => {
        for (const band of [...impactBands(), ...strengthBands()]) {
            const unit = band.upper_bound === 1 ? 'fraction' : 'currency';
            const suggested = suggestRepresentative(
                unit,
                band.lower_bound,
                band.upper_bound,
                MULTIPLIER,
            );

            expect(suggested).toBeGreaterThanOrEqual(band.lower_bound);
            if (band.upper_bound !== null) {
                expect(suggested).toBeLessThanOrEqual(band.upper_bound);
            }
        }
    });
});

describe('moveBoundary — one number, rendered twice', () => {
    it('moves both sides of the boundary together', () => {
        const moved = moveBoundary(
            impactBands(),
            1,
            20_000,
            'currency',
            MULTIPLIER,
        );

        // Invariant 2 cannot be broken by editing, because there is only one
        // number here. The screen shows it once and the row above reads it.
        expect(moved[1].upper_bound).toBe(20_000);
        expect(moved[2].lower_bound).toBe(20_000);
        expectValid(moved, 'currency');
    });

    it('clamps a boundary dragged past its neighbour', () => {
        // 5 is below the band's own floor of 1,000. Unclamped this inverts the
        // band into [1000, 5), which passes no invariant and which the user
        // cannot see they have created.
        const moved = moveBoundary(impactBands(), 1, 5, 'currency', MULTIPLIER);

        expect(moved[1].upper_bound).toBeGreaterThan(1_000);
        expectValid(moved, 'currency');
    });

    it('clamps a boundary dragged past the band above', () => {
        const moved = moveBoundary(
            impactBands(),
            1,
            5_000_000,
            'currency',
            MULTIPLIER,
        );

        expect(moved[1].upper_bound).toBeLessThan(100_000);
        expectValid(moved, 'currency');
    });

    it('recomputes both representatives so neither is left outside its band', () => {
        // The failure this prevents: a representative that stays put while its
        // band moves around it. That is invariant 5 broken without anyone
        // touching the representative field.
        const moved = moveBoundary(
            impactBands(),
            0,
            500,
            'currency',
            MULTIPLIER,
        );

        expect(moved[0].representative).toBeLessThan(500);
        expectValid(moved, 'currency');
    });
});

describe('splitBand', () => {
    it('splits in half without moving the outer bounds', () => {
        const split = splitBand(impactBands(), 1, 'currency', MULTIPLIER);

        expect(split).toHaveLength(5);
        // Splitting rather than appending is what makes a gap unreachable: the
        // new band takes the upper half of an existing one, so the group's
        // outer bounds never move.
        expect(split[1].lower_bound).toBe(1_000);
        expect(split[2].upper_bound).toBe(10_000);
        expectValid(split, 'currency');
    });

    it('splits the open top band at a real number', () => {
        const split = splitBand(impactBands(), 3, 'currency', MULTIPLIER);

        expect(split[3].upper_bound).not.toBeNull();
        // The new top band stays open, so invariant 4 survives.
        expect(split[4].upper_bound).toBeNull();
        expectValid(split, 'currency');
    });

    it('keeps the fraction top band closed at 1', () => {
        const split = splitBand(strengthBands(), 1, 'fraction', MULTIPLIER);

        expectValid(split, 'fraction');
    });

    it('names no code at all for a band it creates', () => {
        let bands = impactBands();
        const before = bands.length;

        for (let i = 0; i < 4; i++) {
            bands = splitBand(bands, 0, 'currency', MULTIPLIER);
        }

        // W87, D44. This module used to mint `${code}_2` and nudge it until it
        // was unused. It cannot do that job correctly: it can only see the
        // bands being edited, while `scale_levels_code_unique (scale_id, code)`
        // is a fact about the whole scale — including bands this save removes,
        // whose codes must not be handed to a new band. So a created band
        // carries null and `LevelCodeDeriver` names it server-side, next to
        // the index it is defending.
        const created = bands.filter((b) => b.code === null);

        expect(created).toHaveLength(bands.length - before);

        // The bands that already existed keep the codes they arrived with —
        // splitting one must not renumber its neighbours.
        expect(bands.filter((b) => b.code !== null).map((b) => b.code)).toEqual(
            impactBands()
                .map((b) => b.code)
                .filter((code) => bands.some((b) => b.code === code)),
        );
    });
});

describe('removeBand — the range has to go somewhere', () => {
    it('gives a removed band’s range to the one below it', () => {
        const removed = removeBand(impactBands(), 2, 'currency', MULTIPLIER);

        expect(removed).toHaveLength(3);
        expect(removed[1].upper_bound).toBe(100_000);
        expectValid(removed, 'currency');
    });

    it('keeps the scale starting at zero when the lowest band goes', () => {
        const removed = removeBand(impactBands(), 0, 'currency', MULTIPLIER);

        // Invariant 3. Its successor inherits the floor, rather than the scale
        // starting at 1,000 and a €500 loss mapping to nothing.
        expect(removed[0].lower_bound).toBe(0);
        expectValid(removed, 'currency');
    });

    it('keeps the top band open when the top band goes', () => {
        const removed = removeBand(impactBands(), 3, 'currency', MULTIPLIER);

        expect(removed[removed.length - 1].upper_bound).toBeNull();
        expectValid(removed, 'currency');
    });

    it('refuses to remove the last band', () => {
        const single = [impactBands()[0]];

        // A scale with no bands cannot map anything, and every qualitative
        // value on the assessment would resolve to nothing.
        expect(removeBand(single, 0, 'currency', MULTIPLIER)).toHaveLength(1);
    });

    it('leaves no gap, so every value still lands somewhere', () => {
        const removed = removeBand(impactBands(), 1, 'currency', MULTIPLIER);

        // The point of absorbing rather than dropping the range: a hole makes
        // the reverse lookup partial, and a value falling in it reads as
        // missing and silently leaves the total.
        for (const probe of [0, 500, 1_500, 9_999, 50_000, 10_000_000]) {
            expect(bandFor(removed, probe, 'currency')).not.toBeNull();
        }
    });
});

describe('bandFor — the live preview', () => {
    it('puts a value exactly on a boundary in the higher band', () => {
        // calculation-engine.md §8.2's `[lower, upper)` convention, and the one
        // users will test by typing exactly 100,000.
        expect(bandFor(impactBands(), 100_000, 'currency')?.code).toBe(
            'severe',
        );
        expect(bandFor(impactBands(), 99_999, 'currency')?.code).toBe(
            'moderate',
        );
    });

    it('answers the spec’s own example', () => {
        expect(bandFor(impactBands(), 50_000, 'currency')?.label).toBe(
            'Moderate',
        );
    });

    it('puts anything above the top boundary in the open band', () => {
        expect(bandFor(impactBands(), 500_000_000, 'currency')?.code).toBe(
            'severe',
        );
    });

    it('includes exactly 1 in a fraction scale’s top band', () => {
        // Invariant 4's exception: [lower, 1] inclusive, so a control that
        // removes the whole risk lands in "strong" rather than nowhere.
        expect(bandFor(strengthBands(), 1, 'fraction')?.code).toBe('strong');
    });

    it('returns null rather than the lowest band for a missing value', () => {
        // ⊥ → null. Rendered as a grey "?" chip everywhere else in the app, and
        // never as "negligible" — that would invent a value the user never gave.
        expect(bandFor(impactBands(), null, 'currency')).toBeNull();
        expect(bandFor(impactBands(), Number.NaN, 'currency')).toBeNull();
        expect(bandFor(impactBands(), -1, 'currency')).toBeNull();
    });
});

describe('the round trip stays lossless', () => {
    it('maps every suggested representative back to its own band', () => {
        // calculation-engine.md §8.4 pitfall 1, and the reason invariant 5
        // exists at all: toLevel(toNumber(ℓ)) === ℓ. Without it "moderate"
        // could resolve to a number that bands back as "major".
        for (const [bands, unit] of [
            [impactBands(), 'currency'],
            [strengthBands(), 'fraction'],
        ] as const) {
            for (const band of bands) {
                const suggested = suggestRepresentative(
                    unit,
                    band.lower_bound,
                    band.upper_bound,
                    MULTIPLIER,
                );

                expect(bandFor(bands, suggested, unit)?.code).toBe(band.code);
            }
        }
    });

    it('still round-trips after an edit', () => {
        const edited = splitBand(
            moveBoundary(impactBands(), 1, 25_000, 'currency', MULTIPLIER),
            0,
            'currency',
            MULTIPLIER,
        );

        for (const band of edited) {
            expect(bandFor(edited, band.representative, 'currency')?.code).toBe(
                band.code,
            );
        }
    });
});
