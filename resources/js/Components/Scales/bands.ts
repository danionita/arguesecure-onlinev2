/**
 * The band-editing rules the calibration screen enforces as you type (W65,
 * ui.md §8, schema.md §3.2).
 *
 * ui.md's instruction is the design: "editing a bound auto-adjusts the
 * neighbour so bands stay contiguous — the invariants are non-negotiable, so
 * **make them impossible to violate rather than validating after the fact**."
 *
 * So this module has no `validate()`. A band's upper bound and the next band's
 * lower bound are not two numbers that must agree; they are **one** number,
 * held once and rendered twice. Moving it moves both, and there is no
 * intermediate state where they disagree for the user to be told about.
 * `ScaleValidator` still checks all five invariants server-side, because a
 * client is never the guarantee — but on this screen it should never fire, and
 * if it does, that is a defect here rather than a user error.
 *
 * Pure, and deliberately framework-free, so it is unit-testable without
 * mounting anything.
 */

/**
 * A band as the scale editor holds it while being edited.
 *
 * `code` is an opaque round-trip token and is rendered nowhere (W87, D44). An
 * existing band carries back the code it was sent, which is what freezes a
 * code across a rename; a band this module creates carries `null`, and the
 * server derives one from the label. Nothing here ever invents a code: a
 * client-side collision fix could not see the codes of bands it is not
 * editing, and `(scale_id, code)` is the database's index to defend.
 *
 * There is no `color`. D45 stopped it being editable; the column stays with
 * its `#9CA3AF` default and nothing outside `ScaleCalibrator` ever read it.
 */
export interface EditableBand {
    code: string | null;
    label: string;
    lower_bound: number;
    /** null is the open top band. A fraction scale's top band closes at 1 instead. */
    upper_bound: number | null;
    representative: number;
}

export type ScaleUnit = 'currency' | 'rate_per_year' | 'fraction';

/**
 * One scale as `App\Domain\Scales\ScaleDescriber` sends it.
 *
 * Declared here rather than in `ScaleCards.vue` because `<script setup>`
 * cannot export a type, and both the assessment settings page and the admin
 * Defaults tab need to name the shape they are handed.
 */
export interface ScaleDetail {
    id: number;
    kind: 'impact' | 'likelihood' | 'strength';
    name: string;
    unit: ScaleUnit;
    /**
     * Whether this is a shared system scale. On an assessment it decides
     * whether a save clones (`ScaleCalibrator`); on the admin tab it is always
     * true, which is why that screen does not draw it.
     */
    is_system: boolean;
    bands: (EditableBand & {
        ordinal: number;
        suggested_representative: number;
    })[];
}

/**
 * The default representative for a band.
 *
 * Mirrors `App\Domain\Scales\RepresentativeGenerator` exactly — schema.md
 * §3.2.1 — and the multiplier is passed in from the server rather than
 * hardcoded here, because it is configurable and two copies of a configurable
 * number is one copy too many.
 */
export function suggestRepresentative(
    unit: ScaleUnit,
    lower: number,
    upper: number | null,
    topBandMultiplier: number,
): number {
    if (unit === 'fraction') {
        return upper === null
            ? Math.min(1, lower + (1 - lower) / 2)
            : (lower + upper) / 2;
    }

    if (upper === null) {
        return lower * topBandMultiplier;
    }

    if (lower <= 0) {
        // The geometric mean of a band starting at zero is zero, which would
        // sit on the lower bound and make every value in the lowest band
        // resolve to nothing at all.
        return upper / 4;
    }

    return Math.sqrt(lower * upper);
}

/**
 * Moves the boundary between band `index` and the one after it.
 *
 * The single-number rule in practice: this writes `bands[index].upper_bound`
 * and `bands[index + 1].lower_bound` together, so invariant 2 cannot be broken
 * by editing. The boundary is clamped to stay inside its neighbours, because
 * dragging it past one of them would invert a band — a state with no meaning
 * that the user cannot see they have created.
 */
export function moveBoundary(
    bands: EditableBand[],
    index: number,
    value: number,
    unit: ScaleUnit,
    topBandMultiplier: number,
): EditableBand[] {
    const next = bands[index + 1];

    if (next === undefined) {
        return bands;
    }

    const floor = bands[index].lower_bound;
    const ceiling = next.upper_bound;

    // Strictly inside both neighbours. Equal to either would produce an empty
    // band, which passes every invariant — `[10, 10)` is contiguous with what
    // surrounds it — and is meaningless: no value can ever fall in it.
    let clamped = Math.max(value, nextAbove(floor));

    if (ceiling !== null) {
        clamped = Math.min(clamped, nextBelow(ceiling));
    }

    const updated = bands.map((band) => ({ ...band }));

    updated[index].upper_bound = clamped;
    updated[index + 1].lower_bound = clamped;

    // Both bands' representatives follow, because a representative that stayed
    // put while its band moved around it is how invariant 5 gets broken without
    // anyone touching the field. Recomputed rather than clamped: a suggestion
    // the user can see and overwrite beats a number silently nudged to be legal.
    updated[index].representative = suggestRepresentative(
        unit,
        updated[index].lower_bound,
        updated[index].upper_bound,
        topBandMultiplier,
    );
    updated[index + 1].representative = suggestRepresentative(
        unit,
        updated[index + 1].lower_bound,
        updated[index + 1].upper_bound,
        topBandMultiplier,
    );

    return updated;
}

/**
 * Inserts a band by splitting the one at `index` in half.
 *
 * Splitting rather than appending is what keeps the set contiguous with no
 * special case: the new band takes the upper half of an existing one, so the
 * outer bounds of the group never move and invariants 2, 3 and 4 hold by
 * construction. There is nowhere for the user to put a band that would create
 * a gap, which is the point.
 *
 * The midpoint is geometric for the log-scaled units, for the same reason
 * `suggestRepresentative` uses one: the arithmetic midpoint of 1k–1M is
 * 500,500, which splits it into a tiny band and an enormous one.
 */
export function splitBand(
    bands: EditableBand[],
    index: number,
    unit: ScaleUnit,
    topBandMultiplier: number,
): EditableBand[] {
    const band = bands[index];
    const lower = band.lower_bound;
    const upper = band.upper_bound;

    const midpoint =
        upper === null
            ? // The open top band has no midpoint. Its new boundary goes where
              // the representative would have gone, which is the only defensible
              // point the rule already names.
              suggestRepresentative(unit, lower, null, topBandMultiplier)
            : unit === 'fraction' || lower <= 0
              ? (lower + (upper ?? 1)) / 2
              : Math.sqrt(lower * upper);

    const created: EditableBand = {
        // Null, always. A split makes a band that does not exist yet, and the
        // server derives its code from the label — see LevelCodeDeriver. Naming
        // it here would be a second implementation of a rule the unique index
        // is the authority on.
        code: null,
        label: `${band.label} (upper)`,
        lower_bound: midpoint,
        upper_bound: upper,
        representative: suggestRepresentative(
            unit,
            midpoint,
            upper,
            topBandMultiplier,
        ),
    };

    const updated = bands.map((b) => ({ ...b }));

    updated[index].upper_bound = midpoint;
    updated[index].representative = suggestRepresentative(
        unit,
        lower,
        midpoint,
        topBandMultiplier,
    );

    updated.splice(index + 1, 0, created);

    return updated;
}

/**
 * Removes a band, giving its range to the one below it — or, for the lowest
 * band, to the one above.
 *
 * The range has to go somewhere. Dropping it would leave a gap, and a gap makes
 * the reverse lookup partial: a value in the hole would band-map to nothing,
 * which the engine reads as a missing value and silently drops from the total.
 * Absorbing into the neighbour keeps `[0, ∞)` covered, which is the property
 * `ScaleMapper::toLevel()` relies on to be total.
 *
 * Refuses to remove the last band: a scale with no bands cannot map anything.
 */
export function removeBand(
    bands: EditableBand[],
    index: number,
    unit: ScaleUnit,
    topBandMultiplier: number,
): EditableBand[] {
    if (bands.length <= 1) {
        return bands;
    }

    const removed = bands[index];
    const updated = bands.filter((_, i) => i !== index).map((b) => ({ ...b }));

    if (index === 0) {
        // The lowest band's successor inherits its floor, so invariant 3 —
        // the first band starts at 0 — survives.
        updated[0].lower_bound = removed.lower_bound;
    } else {
        // Otherwise the band below swallows the range upward, so the top
        // band's open end (invariant 4) is untouched even when the top band
        // is the one being removed.
        updated[index - 1].upper_bound = removed.upper_bound;
    }

    const touched = index === 0 ? 0 : index - 1;
    updated[touched].representative = suggestRepresentative(
        unit,
        updated[touched].lower_bound,
        updated[touched].upper_bound,
        topBandMultiplier,
    );

    return updated;
}

/**
 * Which band a value falls in, by the `[lower, upper)` convention — inclusive
 * lower, exclusive upper, with the fraction scale's top band closing
 * inclusively at 1 (calculation-engine.md §8.2).
 *
 * This is the live preview's whole job: "a €50,000 impact falls in
 * **moderate**". Written here against the *unsaved* bands rather than reusing
 * `calc/ScaleMapper`, which takes persisted `ScaleLevel` rows with ids and
 * units attached — and the point of the preview is to answer for bands that do
 * not exist yet. The convention is duplicated, so it is stated in both places
 * and asserted in both test suites.
 */
export function bandFor(
    bands: EditableBand[],
    value: number | null,
    unit: ScaleUnit,
): EditableBand | null {
    if (value === null || Number.isNaN(value) || value < 0) {
        return null;
    }

    for (let i = bands.length - 1; i >= 0; i--) {
        if (value >= bands[i].lower_bound) {
            const upper = bands[i].upper_bound;

            if (upper === null) {
                return bands[i];
            }

            // The top band of a fraction scale is [lower, 1] inclusive, so a
            // strength of exactly 1 lands in it rather than nowhere.
            const isTop = i === bands.length - 1;

            if (
                value < upper ||
                (isTop && unit === 'fraction' && value <= upper)
            ) {
                return bands[i];
            }

            return null;
        }
    }

    return null;
}

/**
 * The smallest representable step away from a bound.
 *
 * `Number.EPSILON` is relative to 1, so adding it to 1,000,000 changes nothing.
 * These scale the step to the magnitude, which is what makes the clamp work for
 * a currency scale reaching into the millions and a fraction scale working in
 * hundredths.
 */
function nextAbove(x: number): number {
    return x + Math.max(Number.EPSILON, Math.abs(x) * 1e-9);
}

function nextBelow(x: number): number {
    return x - Math.max(Number.EPSILON, Math.abs(x) * 1e-9);
}
