<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Reporting\Money;

/**
 * One value in one cell of one section table, carrying what it *is* rather
 * than what it looks like (W74 item 25).
 *
 * This is the join that makes "one assembled report, five renderings" true
 * rather than aspirational. Every exporter reads the same
 * {@see ReportSection} rows; each renders a cell the way its format can
 * express it — HTML and Word want the formatted string, a spreadsheet wants
 * the number plus a format code so the column can be summed, CSV wants the
 * machine-readable digits with no thousands separator. If each exporter
 * formatted for itself, the five would be five opinions about the same
 * figure, and the first rounding disagreement would be indistinguishable
 * from a calculation bug.
 *
 * **Missing is a dash, in every format and every kind.** Never `0`, never
 * blank, never `INF`. calculation-engine.md §10 and ui.md §9 both say so for
 * the on-screen report: a missing impact or likelihood excludes the pair
 * from the total rather than contributing zero, and a control with no cost
 * is not a free control. An exporter that wrote `0` would turn "we do not
 * know" into "we measured it and it was nothing", which is the one
 * transformation a risk report must never make.
 */
final readonly class Cell
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const PERCENT = 'percent';

    public const RATE = 'rate';

    /** Shown, written and exported for any null numeric. See the class docblock. */
    public const MISSING = '—';

    private function __construct(
        public string $kind,
        public string|float|null $value,
        public int $decimals = 2,
    ) {}

    public static function text(?string $value): self
    {
        return new self(self::TEXT, $value ?? '');
    }

    public static function money(?float $value): self
    {
        return new self(self::MONEY, $value, 0);
    }

    public static function number(?float $value, int $decimals = 2): self
    {
        return new self(self::NUMBER, $value, $decimals);
    }

    /** `$fraction` is 0–1: 0.42 renders as "42%", matching format.ts. */
    public static function percent(?float $fraction): self
    {
        return new self(self::PERCENT, $fraction, 0);
    }

    /** Likelihood is a rate per year and may exceed 1 (calculation-engine.md). */
    public static function rate(?float $value): self
    {
        return new self(self::RATE, $value, 2);
    }

    public static function count(int $value): self
    {
        return new self(self::NUMBER, (float) $value, 0);
    }

    /** What a reader sees: HTML, Word, and any prose rendering. */
    public function display(Money $money): string
    {
        if ($this->kind === self::TEXT) {
            return is_string($this->value) ? $this->value : '';
        }

        if (! is_float($this->value)) {
            return self::MISSING;
        }

        return match ($this->kind) {
            self::MONEY => $money->format($this->value),
            self::PERCENT => number_format($this->value * 100, $this->decimals).'%',
            self::RATE => number_format($this->value, $this->decimals).'/yr',
            default => number_format($this->value, $this->decimals),
        };
    }

    /**
     * What a machine reads: CSV. No thousands separator, no currency symbol,
     * a `.` decimal point regardless of locale — this column is going into
     * someone's spreadsheet or script, and a grouped "135,000" inside a
     * comma-separated file is a parsing accident waiting to happen. The
     * currency is stated once, in the column header.
     */
    public function raw(): string
    {
        if ($this->kind === self::TEXT) {
            return is_string($this->value) ? $this->value : '';
        }

        if (! is_float($this->value)) {
            return self::MISSING;
        }

        // A percentage is carried as a 0–1 fraction and *displayed* to whole
        // percent, but written to two decimals here. Rounding a strength of
        // 0.80 to the display's precision inside the raw column produced
        // "1" — the fraction rounded to zero decimals — which is not a
        // rounding error a reader would spot in a spreadsheet of 200 rows.
        if ($this->kind === self::PERCENT) {
            return number_format($this->value * 100, 2, '.', '');
        }

        return number_format($this->value, $this->decimals, '.', '');
    }

    /** True when a spreadsheet can hold this as a number rather than as text. */
    public function isNumeric(): bool
    {
        return $this->kind !== self::TEXT && is_float($this->value);
    }
}
