<?php

namespace Tests\Unit\Calculation\Support;

use PHPUnit\Framework\Assert;

/**
 * Collects every mismatch found while checking one golden vector against
 * the engine's output, instead of failing at the first `expect()` call.
 *
 * A single vector's `expected` block can carry a dozen independent claims
 * (baseline totals, pair values, completeness fields, warnings,
 * defence-ranking figures, ...). If the first mismatch aborted the test,
 * the W7 reconciliation report would only ever see one disagreement per
 * vector per run. This class accumulates every mismatch it finds and fails
 * once, at the end, with the full list — so a single test run produces a
 * complete diagnostic for that vector.
 */
final class GoldenVectorChecker
{
    /** @var list<string> */
    private array $failures = [];

    public function numeric(mixed $actual, mixed $expected, string $label, float $tolerance = 1e-9): void
    {
        if ($expected === null) {
            $this->isNull($actual, $label);

            return;
        }

        if ($actual === null) {
            $this->failures[] = "{$label}: expected {$expected}, got null";

            return;
        }

        if (! is_numeric($actual) || ! is_numeric($expected)) {
            $this->same($actual, $expected, $label);

            return;
        }

        $diff = abs((float) $actual - (float) $expected);

        if ($diff > $tolerance) {
            $this->failures[] = sprintf(
                '%s: expected %s, got %s (|diff| = %s > tolerance %s)',
                $label,
                $expected,
                $actual,
                $diff,
                $tolerance,
            );
        }
    }

    public function isNull(mixed $actual, string $label): void
    {
        if ($actual !== null) {
            $this->failures[] = "{$label}: expected null, got ".var_export($actual, true);
        }
    }

    public function notNull(mixed $actual, string $label): void
    {
        if ($actual === null) {
            $this->failures[] = "{$label}: expected a non-null value, got null";
        }
    }

    public function same(mixed $actual, mixed $expected, string $label): void
    {
        if ($actual !== $expected) {
            $this->failures[] = "{$label}: expected ".var_export($expected, true).', got '.var_export($actual, true);
        }
    }

    /**
     * Order-independent equality of two lists of scalars.
     *
     * @param  list<mixed>  $actual
     * @param  list<mixed>  $expected
     */
    public function set(array $actual, array $expected, string $label): void
    {
        $a = $actual;
        $e = $expected;
        sort($a);
        sort($e);

        if ($a !== $e) {
            $this->failures[] = "{$label}: expected set ".json_encode($e).', got '.json_encode($a);
        }
    }

    /**
     * Order-independent equality of two lists of associative arrays.
     *
     * @param  list<array<string, mixed>>  $actual
     * @param  list<array<string, mixed>>  $expected
     */
    public function rowSet(array $actual, array $expected, string $label): void
    {
        $normalize = function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                ksort($row);
                $out[] = json_encode($row);
            }
            sort($out);

            return $out;
        };

        $a = $normalize($actual);
        $e = $normalize($expected);

        if ($a !== $e) {
            $this->failures[] = "{$label}: expected ".json_encode($e).', got '.json_encode($a);
        }
    }

    /**
     * @param  list<object{code: string, nodeUuid: ?string, message: string}>  $warnings
     * @param  array{code: string, node_uuid?: ?string, message?: string}  $expectedWarning
     */
    public function warningPresent(array $warnings, array $expectedWarning, string $label): void
    {
        foreach ($warnings as $w) {
            if ($w->code === $expectedWarning['code']
                && $w->nodeUuid === ($expectedWarning['node_uuid'] ?? null)) {
                if (array_key_exists('message', $expectedWarning) && $w->message !== $expectedWarning['message']) {
                    $this->failures[] = "{$label}: warning {$expectedWarning['code']}/".
                        json_encode($expectedWarning['node_uuid'] ?? null).
                        " message mismatch — expected '{$expectedWarning['message']}', got '{$w->message}'";
                }

                return;
            }
        }

        $found = array_map(fn ($w) => "{$w->code}/".json_encode($w->nodeUuid), $warnings);
        $this->failures[] = "{$label}: expected warning ".json_encode($expectedWarning).' not found among ['.implode(', ', $found).']';
    }

    /**
     * @param  list<object{code: string, nodeUuid: ?string, message: string}>  $warnings
     */
    public function warningAbsentAll(array $warnings, string $label): void
    {
        if ($warnings !== []) {
            $codes = array_map(fn ($w) => $w->code, $warnings);
            $this->failures[] = "{$label}: expected no warnings, got [".implode(', ', $codes).']';
        }
    }

    public function fail(string $message): void
    {
        $this->failures[] = $message;
    }

    /**
     * Fails once with every mismatch found, or passes cleanly if none were.
     */
    public function assertClean(): void
    {
        if ($this->failures !== []) {
            Assert::fail("Golden vector mismatch(es):\n- ".implode("\n- ", $this->failures));
        }

        Assert::assertTrue(true);
    }
}
