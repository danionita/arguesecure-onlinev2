import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Loads tests/fixtures/calculation-vectors.json — the same golden-vector
 * fixture the Pest suite (tests/Unit/Calculation/GoldenVectorTest.php) runs
 * against. Read from disk with plain node fs rather than a JSON import so
 * this file's typing does not depend on the root tsconfig's module settings.
 */

const here = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.resolve(
    here,
    '../../../fixtures/calculation-vectors.json',
);

export interface GoldenVector {
    name: string;
    description: string;
    input: Record<string, any>;
    expected: Record<string, any>;
}

export interface GoldenVectorFixture {
    schema_version: number;
    tolerance: number;
    known_spec_discrepancies: unknown[];
    vectors: GoldenVector[];
}

let cached: GoldenVectorFixture | null = null;

export function loadFixture(): GoldenVectorFixture {
    if (cached === null) {
        cached = JSON.parse(
            readFileSync(fixturePath, 'utf-8'),
        ) as GoldenVectorFixture;
    }

    return cached;
}

export function vectorsByName(): Record<string, GoldenVector> {
    const out: Record<string, GoldenVector> = {};
    for (const v of loadFixture().vectors) {
        out[v.name] = v;
    }

    return out;
}

/**
 * Resolves the `{"$ref_note": "..."}` shorthand some vectors use for their
 * `input.scales` block (they mean "identical to another vector's scales").
 */
export function resolveVectorInput(
    input: Record<string, any>,
): Record<string, any> {
    if (
        input.scales &&
        typeof input.scales === 'object' &&
        '$ref_note' in input.scales
    ) {
        return {
            ...input,
            scales: vectorsByName()['worked_example_section14'].input.scales,
        };
    }

    return input;
}
