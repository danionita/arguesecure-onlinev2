import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';

/**
 * W11 mirror of tests/Unit/Calculation/RiskCalculatorPurityTest.php (§15
 * item 14). PHP checks "no Eloquent, no I/O, no clock, no randomness" via an
 * arch-dependency test plus a source-token scan; TypeScript has no Eloquent
 * to depend on, so this keeps only the source-token scan, adapted to the
 * browser/Node globals that would break purity here: no DOM/network access,
 * no clock, no randomness, no console noise.
 */

const here = path.dirname(fileURLToPath(import.meta.url));
const calcDir = path.resolve(here, '../../../resources/js/calc');

const files = [
    'types.ts',
    'ScaleMapper.ts',
    'GraphResolver.ts',
    'DefenceRanker.ts',
    'RiskCalculator.ts',
    'index.ts',
].map((f) => path.join(calcDir, f));

const forbidden = [
    // clock
    'Date.now(',
    'new Date(',
    'performance.now(',
    'setTimeout(',
    'setInterval(',
    // randomness
    'Math.random(',
    'crypto.getRandomValues(',
    'crypto.randomUUID(',
    // I/O / network / DOM
    'fetch(',
    'XMLHttpRequest',
    'localStorage',
    'sessionStorage',
    'document.',
    'window.',
    'readFileSync(',
    'writeFileSync(',
    'require(',
    // logging noise a pure engine should never need
    'console.',
];

describe('calculation engine purity (§15 item 14)', () => {
    test('every calc source file exists', () => {
        for (const file of files) {
            expect(() => readFileSync(file, 'utf-8')).not.toThrow();
        }
    });

    test('no calc source file calls a clock, randomness, DOM, or I/O function', () => {
        for (const file of files) {
            const source = readFileSync(file, 'utf-8');

            for (const needle of forbidden) {
                expect(
                    source.includes(needle),
                    `${file} contains forbidden token '${needle}'.`,
                ).toBe(false);
            }
        }
    });

    test('no calc source file imports a Vue, Inertia, or framework module', () => {
        for (const file of files) {
            const source = readFileSync(file, 'utf-8');
            const importLines = source.match(/^import .*$/gm) ?? [];

            for (const line of importLines) {
                expect(line).not.toMatch(/['"]vue['"]/);
                expect(line).not.toMatch(/['"]@inertiajs/);
                expect(line).not.toMatch(/['"]axios['"]/);
            }
        }
    });
});
