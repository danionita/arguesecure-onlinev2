/**
 * The copy module is where the tool explains its own arithmetic to someone
 * who has not read calculation-engine.md. Two things in here are worth
 * pinning with a test rather than trusting to review:
 *
 * 1. The two kinds of missing value say different things, because they *are*
 *    different — a missing impact drops the pair from the total, a missing
 *    strength is treated as no reduction and so overstates residual risk.
 *    A future edit that "simplifies" both into one sentence would make the
 *    tool quietly dishonest about which way it is wrong.
 * 2. The reduction chip merges a percentage and a target into one phrase.
 *    A missing strength must still read as a strength that is missing —
 *    "Reduces overall loss by ?" — never as 0%.
 */
import { describe, expect, it } from 'vitest';
import {
    BASELINE_HINTS,
    BASELINE_LABELS,
    MISSING_HINTS,
    ROOT_TOTALS_HINT,
    ROOT_TOTAL_LABELS,
    notAppliedHint,
    reductionChipHint,
    reductionChipLabel,
} from '../../../resources/js/constants/valueHints';

describe('missing-value copy', () => {
    it('says a missing impact or likelihood excludes the pair, and never says zero', () => {
        for (const hint of [MISSING_HINTS.impact, MISSING_HINTS.likelihood]) {
            expect(hint).toMatch(/excluded from the total/);
            expect(hint).toMatch(/not counted as zero/);
        }
    });

    it('says a missing strength is treated as no reduction, which is the pessimistic direction', () => {
        expect(MISSING_HINTS.strength).toMatch(/no reduction/);
        expect(MISSING_HINTS.strength).toMatch(/pessimistic/);
        expect(MISSING_HINTS.strength).not.toMatch(/excluded from the total/);
    });
});

describe('the merged reduction chip', () => {
    it('reads as one sentence, not a percentage next to a phrase', () => {
        expect(reductionChipLabel('likelihood', '40%')).toBe(
            'Reduces likelihood by 40%',
        );
        expect(reductionChipLabel('impact', '25%')).toBe(
            'Reduces impact by 25%',
        );
    });

    it('treats a blank reduction target as "overall loss", the documented default', () => {
        expect(reductionChipLabel(null, '80%')).toBe(
            'Reduces overall loss by 80%',
        );
    });

    it('renders a missing strength as "?" inside the sentence, never as 0%', () => {
        expect(reductionChipLabel('likelihood', null)).toBe(
            'Reduces likelihood by ?',
        );
        expect(reductionChipLabel(null, null)).not.toMatch(/0%/);
        expect(reductionChipHint('likelihood', null)).toBe(
            MISSING_HINTS.strength,
        );
    });

    it('repeats the note that prevents the bug report — an explicit target does not move the total', () => {
        expect(reductionChipHint('likelihood', '40%')).toMatch(
            /not the assessment total/,
        );
        // The default carries no such choice to explain.
        expect(reductionChipHint(null, '40%')).not.toMatch(
            /not the assessment total/,
        );
    });
});

describe('a defence that is on screen but not counting', () => {
    it('explains inherent as the before-any-defence baseline', () => {
        expect(notAppliedHint('implemented', 'inherent')).toMatch(
            /before any defence/,
        );
    });

    it('explains a planned defence under current, and counts it under target', () => {
        expect(notAppliedHint('todo', 'current')).toMatch(/Planned/);
        expect(notAppliedHint('todo', 'target')).toBeNull();
    });

    it('explains shelved as never counted, in any baseline', () => {
        expect(notAppliedHint('shelved', 'current')).toMatch(/never/);
        expect(notAppliedHint('shelved', 'target')).toMatch(/never/);
    });

    it('says nothing about an implemented defence in a baseline that counts it', () => {
        expect(notAppliedHint('implemented', 'current')).toBeNull();
        expect(notAppliedHint('implemented', 'target')).toBeNull();
    });
});

/**
 * `BASELINE_OPTIONS` fed the toolbar's baseline selector and is deleted with
 * it (W37; PLAN-2.2.md D8). The three *descriptions* survive as
 * `BASELINE_HINTS`, because the Statistics tab and the report still have to
 * explain what the three baselines mean — the vocabulary outlives the control.
 */
describe('baseline vocabulary, which outlives the removed selector', () => {
    it('still describes all three, distinguishing them by which defences count', () => {
        expect(Object.keys(BASELINE_HINTS)).toEqual([
            'inherent',
            'current',
            'target',
        ]);

        expect(BASELINE_HINTS.current).toMatch(/implemented/);
        expect(BASELINE_HINTS.target).toMatch(/planned/i);
        for (const hint of Object.values(BASELINE_HINTS)) {
            expect(hint.length).toBeGreaterThan(20);
        }
    });

    it('names Base on the card and Inherent in the report, and ties the two together', () => {
        expect(ROOT_TOTAL_LABELS.inherent).toBe('Base');
        expect(BASELINE_LABELS.inherent).toBe('Inherent');
        // The sentence that stops a reader treating them as two ideas.
        expect(ROOT_TOTALS_HINT).toMatch(/Base is the inherent loss/);
    });
});
