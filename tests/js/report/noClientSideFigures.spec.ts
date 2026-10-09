import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';

/**
 * W83 — extends `tests/js/calc/purity.spec.ts`'s spirit ("no calc source
 * file calls a clock, randomness, DOM, or I/O function") to the search
 * feature this package adds: **no report figure is computed in the client.**
 *
 * CLAUDE.md: "the report is server-computed and nothing here may re-derive
 * an engine figure client-side" — `ReportBuilder` assembles every ALE, ARO,
 * CBR, ROSI, net-benefit, attribution and coverage figure once, server-side,
 * and hands it down as `ReportData`. `useReportSearch.ts` exists so
 * `SearchBox.vue` has something to bind to on a page with no
 * `AssessmentStore`; its own file header explains why that composable does
 * *not* construct one. What is asserted here is the property that makes
 * that safe: the composable's source never references a figure-bearing
 * field name at all, so there is no arithmetic to have snuck in — it can
 * only ever match against `title`/`description`/`rationale`, the same three
 * plain-text fields `AssessmentTreeSection.vue` already prints unmodified.
 *
 * `Pages/Report/Show.vue` is checked the same way, scoped to arithmetic
 * *on* a figure field (`.ale *`, `.total +`, …) rather than the field names
 * appearing at all — the page legitimately reads `report.options` and spreads
 * `report.defencesByImportance` into one array for the attribution chart
 * (concatenation, not computation), and forbidding the names outright would
 * make an honest pass-through prop reference indistinguishable from a
 * violation. `Components/Report/AttributionChart.vue` is deliberately not
 * scanned here: it already divides `r.attribution` by a client-side sum of
 * `attribution` values the server provided, to size a bar's *width* as a
 * fraction of the total width — a presentational proportion of numbers
 * already computed server-side, not a new figure. That distinction is real
 * but too fine for a token scan to draw safely across every file in
 * `Components/Report/**`, so this test targets exactly what W83 added
 * rather than re-litigating what W33 shipped.
 */

const here = path.dirname(fileURLToPath(import.meta.url));
const resourcesDir = path.resolve(here, '../../../resources/js');

const searchComposable = path.join(
    resourcesDir,
    'composables/useReportSearch.ts',
);
const showPage = path.join(resourcesDir, 'Pages/Report/Show.vue');

/** Every figure-bearing field name `Components/Report/types.ts` declares. */
const figureFields = [
    'ale',
    'aro',
    'impact',
    'likelihood',
    'strength',
    'cbr',
    'rosi',
    'netBenefit',
    'mc',
    'mcAdd',
    'attribution',
    'benefit',
    'annualCost',
    'coverage',
    'capexAmount',
    'opexAnnualAmount',
    'retainedImpact',
    'currentAle',
    'targetAle',
];

describe('no report figure is computed in the client (W83, spirit of §15 item 14)', () => {
    test('the report search composable exists', () => {
        expect(() => readFileSync(searchComposable, 'utf-8')).not.toThrow();
    });

    test('the report search composable never references a figure-bearing field — it can only match text', () => {
        const source = readFileSync(searchComposable, 'utf-8').toLowerCase();

        for (const field of figureFields) {
            expect(
                source.includes(`.${field.toLowerCase()}`),
                `useReportSearch.ts references '.${field}', a figure-bearing field. Search must only touch title/description/rationale.`,
            ).toBe(false);
        }
    });

    test('the report search composable does not import the calculation engine', () => {
        const source = readFileSync(searchComposable, 'utf-8');
        const importLines = source.match(/^import .*$/gm) ?? [];

        for (const line of importLines) {
            expect(line).not.toMatch(/['"].*\/calc\//);
        }
    });

    test('Pages/Report/Show.vue performs no arithmetic on a figure-bearing field', () => {
        const source = readFileSync(showPage, 'utf-8');

        for (const field of figureFields) {
            const arithmeticOnField = new RegExp(
                `\\.${field}\\b\\s*[*+/-]`,
                'i',
            );
            expect(
                arithmeticOnField.test(source),
                `Show.vue appears to compute against '.${field}' — every figure is assembled once by ReportBuilder server-side.`,
            ).toBe(false);
        }
    });

    test('Pages/Report/Show.vue does not import the calculation engine', () => {
        const source = readFileSync(showPage, 'utf-8');
        const importLines = source.match(/^import .*$/gm) ?? [];

        for (const line of importLines) {
            expect(line).not.toMatch(/['"].*\/calc\//);
        }
    });
});
