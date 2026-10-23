import { describe, expect, it } from 'vitest';
// @ts-expect-error — a plain .mjs script with no declaration file.
import {
    evaluate,
    phpStatements,
} from '../../../tools/dev/checks/coverage-gate.mjs';

const clover = (covered: number, total: number) =>
    `<coverage><project timestamp="1"><file name="a.php"><metrics statements="3" coveredstatements="3"/></file>` +
    `<metrics files="1" statements="${total}" coveredstatements="${covered}"/></project></coverage>`;

describe('coverage gate (W119)', () => {
    it('reads the project total of a Clover report, not a file line', () => {
        expect(phpStatements(clover(90, 100))).toBe(90);
    });

    it('refuses a report with no statements rather than reporting 0% or NaN', () => {
        expect(() => phpStatements(clover(0, 0))).toThrow();
    });

    it('passes at the floor and fails one hundredth below it', () => {
        const floors = { php: { statements: 93.5 } };
        expect(evaluate(floors, { php: { statements: 93.5 } })[0].ok).toBe(
            true,
        );
        expect(evaluate(floors, { php: { statements: 93.49 } })[0].ok).toBe(
            false,
        );
    });

    it('fails a measure that was not reported at all', () => {
        const rows = evaluate({ js: { branches: 60 } }, { js: {} });
        expect(rows[0].ok).toBe(false);
    });
});
