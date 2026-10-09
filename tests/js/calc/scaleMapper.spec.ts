import { describe, expect, test } from 'vitest';
import { ScaleMapper } from '../../../resources/js/calc';
import { workedExampleScaleLevels } from './support/workedExampleGraph';

/**
 * W11 mirror of tests/Unit/Calculation/ScaleMapperTest.php.
 */
describe('ScaleMapper', () => {
    // §15 item 5: round trip is lossless for every level of every scale.
    test('toLevel(toNumber(level)) round-trips for every level', () => {
        const mapper = new ScaleMapper();
        const levels = Object.values(workedExampleScaleLevels());

        const byKind: Record<string, typeof levels> = {};
        for (const level of levels) {
            (byKind[level.kind] ??= []).push(level);
        }

        for (const levelsOfKind of Object.values(byKind)) {
            const unit = levelsOfKind[0].unit;

            for (const level of levelsOfKind) {
                const number = mapper.toNumber(level);
                const roundTripped = mapper.toLevel(number, levelsOfKind, unit);

                expect(roundTripped).not.toBeNull();
                expect(roundTripped?.id).toBe(level.id);
            }
        }
    });

    // §8.2 boundary convention: [lower, upper) — a value exactly on a
    // boundary lands in the *higher* band.
    test('a value exactly on a boundary lands in the higher band', () => {
        const mapper = new ScaleMapper();
        const levels = Object.values(workedExampleScaleLevels()).filter(
            (l) => l.kind === 'impact',
        );

        // 10,000 is minor's upper_bound and moderate's lower_bound.
        const level = mapper.toLevel(10000.0, levels, 'currency');

        expect(level?.code).toBe('moderate');
    });

    // §8.2 fraction scale top band is [lower, 1] inclusive.
    test('the fraction scale top band is inclusive of 1.0', () => {
        const mapper = new ScaleMapper();
        const levels = Object.values(workedExampleScaleLevels()).filter(
            (l) => l.kind === 'strength',
        );

        const level = mapper.toLevel(1.0, levels, 'fraction');

        expect(level?.code).toBe('near_certain');
    });

    // x = ⊥ (null) maps to null, never the lowest band.
    test('a null value maps to null, not the lowest band', () => {
        const mapper = new ScaleMapper();
        const levels = Object.values(workedExampleScaleLevels()).filter(
            (l) => l.kind === 'impact',
        );

        expect(mapper.toLevel(null, levels, 'currency')).toBeNull();
    });

    // §8.3 / §15 item 13: unit mismatch must throw, not silently band the
    // wrong quantity.
    test('toLevel throws when the supplied unit disagrees with the scale', () => {
        const mapper = new ScaleMapper();
        const levels = Object.values(workedExampleScaleLevels()).filter(
            (l) => l.kind === 'impact',
        );

        expect(() =>
            mapper.toLevel(50000.0, levels, 'rate_per_year'),
        ).toThrow();
    });
});
