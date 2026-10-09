<?php

namespace App\Domain\Reporting;

/**
 * Money as the report renders it, for the exports that leave the server
 * (W74 item 25).
 *
 * Two rules, and both are the reason this exists rather than a `sprintf`
 * at each call site:
 *
 * 1. **The currency is the assessment's**, never a server default. An
 *    assessment priced in USD must export as USD on a machine whose PHP is
 *    configured for anything at all, because the figure and its unit are one
 *    fact and splitting them across two sources of configuration is how a
 *    report ends up claiming a dollar total in euros.
 * 2. **The locale is fixed at `en-GB`**, deliberately not the server's.
 *    `resources/js/Components/UI/format.ts` formats the on-screen report with
 *    `Intl.NumberFormat('en-GB', { style: 'currency', maximumFractionDigits: 0 })`,
 *    and an export that disagreed with the page it was exported from — one
 *    grouping on spaces because the container's locale said so — would read
 *    as a different number rather than as the same one written differently.
 *
 * `ext-intl` is a hard requirement of the runtime image already (see the
 * Dockerfile's `docker-php-ext-install ... intl`), so `NumberFormatter` is
 * used where it exists. The fallback is not defensive decoration: a
 * developer running the suite on a bare PHP build should get a readable
 * figure rather than a fatal error inside an exporter.
 *
 * `extension_loaded()` rather than `class_exists()`, and not by preference:
 * `tests/Feature/Security/SourceAuditTest.php` bans `class_exists()` outright
 * across `app/` and `routes/`, because it is half of legacy defect #7 —
 * `class_exists()` on an attacker-supplied string feeding
 * `ReflectionClass::newInstance()`. The ban is deliberately blunt so that no
 * reviewer has to judge each call site, and this is the blunt ban working as
 * intended rather than a false positive to argue with.
 */
final readonly class Money
{
    private const LOCALE = 'en-GB';

    public function __construct(public string $currency) {}

    /** Whole units, like the report page — never cents in a loss total. */
    public function format(float $amount): string
    {
        if (extension_loaded('intl')) {
            $formatter = new \NumberFormatter(self::LOCALE, \NumberFormatter::CURRENCY);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);

            $formatted = $formatter->formatCurrency($amount, $this->currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $this->currency.' '.number_format($amount, 0, '.', ',');
    }

    /**
     * The symbol alone, for the one consumer that cannot take a formatted
     * string: a spreadsheet cell holds a *number* and its currency lives in
     * the cell's format code (`"€"#,##0`). Writing the formatted string there
     * instead would make every money column text, which cannot be summed —
     * and a spreadsheet whose totals row does not work is a spreadsheet
     * nobody asked for.
     */
    public function symbol(): string
    {
        if (extension_loaded('intl')) {
            $formatter = new \NumberFormatter(self::LOCALE, \NumberFormatter::CURRENCY);
            $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $this->currency);

            $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
            if ($symbol !== '') {
                return $symbol;
            }
        }

        return $this->currency;
    }
}
