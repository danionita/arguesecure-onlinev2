<?php

namespace App\Domain\Reporting;

/**
 * The options step before generating a report (PLAN.md Phase 7, ui.md §9):
 * baseline (current / target / both), include-or-exclude shelved defences,
 * include comments, include the methodology appendix. Parsed once from the
 * request's query string so every downstream consumer (ReportAssembler, the
 * CSV/JSON exporters) shares one resolved, validated set of choices instead
 * of re-reading the query bag.
 *
 * **`figures` was removed in W80** and the parameter is now ignored rather
 * than parsed. It offered "Full figures" / "Qualitative only", bound nothing
 * anywhere from W17 to W79, and was pinned as a defect by W74. Closing the
 * pin meant deciding what a qualitative report *is*, and there was no
 * answer that both said something true and did not already happen:
 *
 * - calculation-engine.md §8.3 fixes which displayed quantities have a scale
 *   at all — impact `Imp(r)`/`Imp′`, likelihood `ARO`/`ARO′`, and strength.
 *   Every ALE, and with it every cost, CBR, ROSI and net-benefit figure, is
 *   "**none — rendered as currency**", and §11.2 step 8 repeats it: *do not
 *   band-map ALEs*. So no setting can make the report stop showing currency,
 *   and a control labelled "Qualitative only" on a document that still
 *   prints a currency total in every register row, every ranked list and
 *   every programme figure is a false statement about that document.
 * - The honest half of what the option could have meant is already the
 *   report's behaviour, and is per node rather than per report: a value
 *   renders as the level its author chose when `impact_mode` /
 *   `likelihood_mode` is `qualitative`, and as the amount they typed when it
 *   is `quantitative` (see `RiskRegisterSection.vue`, and PLAN-2.4 item 23
 *   for the same rule on the cards — "the chips are the *typed* values, not
 *   computed ones; computed figures keep their existing treatment").
 * - What is left for a global switch is dishonest in both directions.
 *   Forcing `qualitative` would band-map an amount its author elicited
 *   precisely, discarding what they said; forcing `full` would print a
 *   level's `representative` as currency, inventing a figure nobody gave.
 *   `ScaleMapper` resolves against the assessment's own calibration for the
 *   *engine*; §8.4 pitfall 4 is explicit that doing it off an uncalibrated
 *   default scale is "the tool lying with a straight face".
 *
 * The false-precision worry the option was reaching for is real and the spec
 * already answers it elsewhere, with an order-of-magnitude range beside the
 * figure and a persistent advisory on an uncalibrated scale (§8.4 pitfalls 3
 * and 4) — not by withholding the numbers a report exists to deliver.
 *
 * A stale URL carrying `figures=qualitative` keeps working: it is ignored,
 * exactly as any other unknown parameter is, and produces the one rendering
 * a report has.
 *
 * `download=1` (W33) is the one-click path: no options step, everything
 * expanded, both states. It **overrides** every other parameter rather than
 * merging with them — a stale link carrying `baseline=current` must not be
 * able to produce a "full" report that silently omits the target state, and
 * the omission would be invisible on screen because the document still looks
 * complete.
 *
 * `show_assignees` (W46) is the one option `download=1` does **not** turn on.
 * Everything else it forces is a matter of showing more of the assessment;
 * that one adds people's names to a document about to leave the room, which
 * has to be chosen rather than inherited from a button labelled "Download".
 */
final readonly class ReportOptions
{
    public const BASELINES = ['current', 'target', 'both'];

    public function __construct(
        public string $baseline,
        public bool $includeShelved,
        public bool $includeComments,
        public bool $includeMethodology,
        /** True when this report was requested through the Download button. */
        public bool $download = false,
        /**
         * Names against nodes in the full-assessment section (W46, item 16).
         *
         * Default **off**, and not turned on by `download=1` either. A report
         * is a document that leaves the room — it is attached to an email,
         * handed to a client, filed with an auditor — and who was responsible
         * for which line is internal information that the person exporting it
         * has to choose to include. Making the one-click Download carry it
         * would be exactly the silent disclosure this package is otherwise
         * careful to avoid.
         */
        public bool $showAssignees = false,
    ) {}

    /** Everything expanded, both states — see the class docblock. */
    public static function forDownload(): self
    {
        return new self(
            baseline: 'both',
            includeShelved: true,
            includeComments: true,
            includeMethodology: true,
            download: true,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        if (self::truthy($query['download'] ?? false)) {
            return self::forDownload();
        }

        $baseline = (string) ($query['baseline'] ?? 'both');
        if (! in_array($baseline, self::BASELINES, true)) {
            $baseline = 'both';
        }

        return new self(
            baseline: $baseline,
            includeShelved: self::truthy($query['include_shelved'] ?? false),
            includeComments: self::truthy($query['include_comments'] ?? false),
            // Methodology defaults ON (ui.md §9: "Include the methodology
            // appendix by default, stating the independence assumption and
            // the per-occurrence definition of impact").
            includeMethodology: self::truthy($query['include_methodology'] ?? true),
            showAssignees: self::truthy($query['show_assignees'] ?? false),
        );
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'baseline' => $this->baseline,
            'includeShelved' => $this->includeShelved,
            'includeComments' => $this->includeComments,
            'includeMethodology' => $this->includeMethodology,
            'download' => $this->download,
            'showAssignees' => $this->showAssignees,
        ];
    }

    public function showsCurrent(): bool
    {
        return $this->baseline === 'current' || $this->baseline === 'both';
    }

    public function showsTarget(): bool
    {
        return $this->baseline === 'target' || $this->baseline === 'both';
    }
}
