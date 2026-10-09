import type { Warning } from '../../../../resources/js/calc';

/**
 * Collects every mismatch found while checking one golden vector against
 * the engine's output, instead of throwing at the first assertion. Mirrors
 * tests/Unit/Calculation/Support/GoldenVectorChecker.php so a single test
 * run produces a complete diagnostic for that vector, the same way the PHP
 * reconciliation harness does.
 */
export class GoldenVectorChecker {
    private failures: string[] = [];

    numeric(
        actual: unknown,
        expected: unknown,
        label: string,
        tolerance = 1e-9,
    ): void {
        if (expected === null) {
            this.isNull(actual, label);

            return;
        }

        if (actual === null || actual === undefined) {
            this.failures.push(`${label}: expected ${expected}, got null`);

            return;
        }

        if (
            typeof actual !== 'number' ||
            typeof expected !== 'number' ||
            Number.isNaN(actual) ||
            Number.isNaN(expected)
        ) {
            this.same(actual, expected, label);

            return;
        }

        const diff = Math.abs(actual - expected);

        if (diff > tolerance) {
            this.failures.push(
                `${label}: expected ${expected}, got ${actual} (|diff| = ${diff} > tolerance ${tolerance})`,
            );
        }
    }

    isNull(actual: unknown, label: string): void {
        if (actual !== null && actual !== undefined) {
            this.failures.push(
                `${label}: expected null, got ${JSON.stringify(actual)}`,
            );
        }
    }

    notNull(actual: unknown, label: string): void {
        if (actual === null || actual === undefined) {
            this.failures.push(`${label}: expected a non-null value, got null`);
        }
    }

    same(actual: unknown, expected: unknown, label: string): void {
        if (actual !== expected) {
            this.failures.push(
                `${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`,
            );
        }
    }

    /** Order-independent equality of two lists of scalars. */
    set(actual: unknown[], expected: unknown[], label: string): void {
        const a = [...actual].sort();
        const e = [...expected].sort();

        if (JSON.stringify(a) !== JSON.stringify(e)) {
            this.failures.push(
                `${label}: expected set ${JSON.stringify(e)}, got ${JSON.stringify(a)}`,
            );
        }
    }

    /** Order-independent equality of two lists of plain objects. */
    rowSet(
        actual: Record<string, unknown>[],
        expected: Record<string, unknown>[],
        label: string,
    ): void {
        const normalize = (rows: Record<string, unknown>[]): string[] =>
            rows.map((row) => JSON.stringify(sortKeys(row))).sort();

        const a = normalize(actual);
        const e = normalize(expected);

        if (JSON.stringify(a) !== JSON.stringify(e)) {
            this.failures.push(
                `${label}: expected ${JSON.stringify(e)}, got ${JSON.stringify(a)}`,
            );
        }
    }

    warningPresent(
        warnings: Warning[],
        expectedWarning: {
            code: string;
            node_uuid?: string | null;
            message?: string;
        },
        label: string,
    ): void {
        for (const w of warnings) {
            if (
                w.code === expectedWarning.code &&
                w.nodeUuid === (expectedWarning.node_uuid ?? null)
            ) {
                if (
                    'message' in expectedWarning &&
                    expectedWarning.message !== undefined &&
                    w.message !== expectedWarning.message
                ) {
                    this.failures.push(
                        `${label}: warning ${expectedWarning.code}/${JSON.stringify(expectedWarning.node_uuid ?? null)} message mismatch — expected '${expectedWarning.message}', got '${w.message}'`,
                    );
                }

                return;
            }
        }

        const found = warnings.map(
            (w) => `${w.code}/${JSON.stringify(w.nodeUuid)}`,
        );
        this.failures.push(
            `${label}: expected warning ${JSON.stringify(expectedWarning)} not found among [${found.join(', ')}]`,
        );
    }

    warningAbsentAll(warnings: Warning[], label: string): void {
        if (warnings.length !== 0) {
            const codes = warnings.map((w) => w.code);
            this.failures.push(
                `${label}: expected no warnings, got [${codes.join(', ')}]`,
            );
        }
    }

    fail(message: string): void {
        this.failures.push(message);
    }

    /** Throws once with every mismatch found, or does nothing if none were. */
    assertClean(): void {
        if (this.failures.length !== 0) {
            throw new Error(
                `Golden vector mismatch(es):\n- ${this.failures.join('\n- ')}`,
            );
        }
    }
}

function sortKeys(row: Record<string, unknown>): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const key of Object.keys(row).sort()) {
        out[key] = row[key];
    }

    return out;
}
