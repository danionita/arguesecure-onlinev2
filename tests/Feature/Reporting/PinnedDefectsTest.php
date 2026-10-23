<?php

use App\Domain\Reporting\Export\HtmlExporter;
use App\Domain\Reporting\Export\ReportSectionBuilder;
use App\Domain\Reporting\ReportBuilder;
use App\Domain\Reporting\ReportOptions;
use Tests\Feature\Reporting\ExportFixture;

/**
 * FIXED (W80): `ReportOptions::$figures` is gone.
 *
 * ## What was pinned
 *
 * The report options offered "Full figures" and "Qualitative only". The
 * value was parsed by `ReportOptions::fromQuery()`, validated against
 * `ReportOptions::FIGURES`, echoed back into the payload as
 * `report.options.figures`, rendered as a live segmented control on the
 * report page, and forwarded by the export menu to all five formats — and
 * honoured by no renderer. Choosing "Qualitative only" changed the URL,
 * re-requested the page, and produced a byte-identical document. It was that
 * way from W17 to W79. W74 found it while binding the other five options
 * into the exports and pinned rather than fixed it, because binding it in
 * the exports alone would have made a downloaded file disagree with the page
 * it came from — the exact divergence that package existed to prevent.
 *
 * ## Why it was removed rather than built
 *
 * The pin's original docblock proposed building the qualitative rendering.
 * W80 worked through what that document would be and found no reading that
 * both said something true and did not already happen.
 *
 * 1. **The currency cannot leave.** calculation-engine.md §8.3 fixes which
 *    displayed quantities have a scale at all: impact `Imp(r)`/`Imp′`,
 *    likelihood `ARO`/`ARO′`, and strength. Every ALE — and with it every
 *    cost, CBR, ROSI and net-benefit figure — is "**none — rendered as
 *    currency**", and §11.2 step 8 repeats it: *do not band-map ALEs*. So no
 *    option can make the report stop printing currency in every register
 *    row, every ranked list and every programme figure, and a control
 *    labelled "Qualitative only" on such a document is a false statement
 *    about it. The report's own methodology appendix has printed "Computed
 *    loss figures are shown as currency, never mapped to a low/medium/high
 *    band" to the reader since W17, directly under the control claiming
 *    otherwise.
 * 2. **The honest half already happens, and is per node.** A value renders
 *    as the level its author chose when `impact_mode` / `likelihood_mode` is
 *    `qualitative`, and as the amount they typed when it is `quantitative` —
 *    see `RiskRegisterSection.vue`, and PLAN-2.4 item 23 for the same rule
 *    on the cards ("the chips are the *typed* values, not computed ones;
 *    computed figures keep their existing treatment"). A per-report switch
 *    was never needed for it.
 * 3. **What is left is dishonest in both directions.** Forcing `qualitative`
 *    would band-map an amount its author elicited precisely, discarding what
 *    they said; forcing `full` would print a level's `representative` as
 *    currency, inventing a figure nobody gave. `ScaleMapper` resolves
 *    against the assessment's own calibration for the *engine*, and §8.4
 *    pitfall 4 is explicit that doing it off an uncalibrated default scale
 *    is "the tool lying with a straight face".
 *
 * The false-precision worry the option reached for is real, and the spec
 * answers it elsewhere — an order-of-magnitude range beside the figure and a
 * persistent advisory on an uncalibrated scale (§8.4 pitfalls 3 and 4) — not
 * by withholding the numbers a report exists to deliver.
 *
 * ## What these guards now hold
 *
 * The assertions are flipped in place rather than deleted, so the reasoning
 * survives and the fix cannot be quietly reverted. The property they defend
 * is **no report surface offers a figures choice and no payload carries
 * one** — which is what would regress if someone reintroduced the option as
 * a dead control, the shape it had for four releases.
 */
test('FIXED (W80): no report surface offers a figures choice', function () {
    // The option is gone from the type, so a stale query string carrying it
    // is ignored the way any unknown parameter is — not parsed, not
    // validated, not echoed. Asserting the *absence of the key* rather than
    // a resolved value is deliberate: a reintroduced `figures` defaulting to
    // 'full' would satisfy "the document is unchanged" while being exactly
    // the dead control this package removed.
    $options = ReportOptions::fromQuery(['figures' => 'qualitative']);

    expect(property_exists($options, 'figures'))->toBeFalse()
        ->and(defined(ReportOptions::class.'::FIGURES'))->toBeFalse()
        ->and($options->toArray())->not->toHaveKey('figures');

    // `download=1` forces its set without one either.
    expect(ReportOptions::forDownload()->toArray())->not->toHaveKey('figures');

    $assessment = ExportFixture::assessment();
    $report = app(ReportBuilder::class)->build($assessment, ReportOptions::fromQuery(['figures' => 'qualitative']));

    expect($report['options'])->not->toHaveKey('figures');
});

test('FIXED (W80): a stale figures= URL still renders the one report there is', function () {
    $assessment = ExportFixture::assessment();

    // The pinned test asserted these two were identical as evidence of the
    // defect. They are still identical — but now because a report has one
    // rendering, not because a live control was being ignored. A bookmarked
    // link from before W80 keeps working rather than 404ing or erroring.
    $stale = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?figures=qualitative')
        ->getContent();
    $plain = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment))
        ->getContent();

    // The generated-at stamp is the only thing that legitimately differs
    // between two renders a second apart, so it is normalised away rather
    // than making this test flaky.
    $strip = fn (string $html): string => preg_replace('/\d{4}-\d{2}-\d{2}[T\d:+\-\.]*/', '', $html) ?? $html;

    expect($strip($stale))->toBe($strip($plain));

    // And the currency figures are there, which is no longer the visible
    // half of a defect but the specified behaviour: §8.3 renders every
    // computed loss as currency and §11.2 step 8 forbids band-mapping it.
    expect($stale)->toContain('€187,000');
});

test('FIXED (W80): the section builder and the exporters take no figures option', function () {
    $assessment = ExportFixture::assessment();
    $report = app(ReportBuilder::class)->build($assessment, ReportOptions::fromQuery([]));
    $options = ReportOptions::fromQuery(['include_methodology' => '1']);

    // The section builder used to receive the choice and ignore it. There is
    // now nothing to ignore.
    $sections = (new ReportSectionBuilder)->build($report, $options);
    $titles = array_map(fn ($section) => $section->title, $sections);

    expect($titles)->toContain('Risk register');

    $html = (new HtmlExporter)->export($report, $options);
    expect($html)->toContain('Annual expected loss');

    // The methodology appendix states the rule to the reader in the
    // document itself — it is why no "qualitative" mode could exist, and it
    // must not quietly stop being printed.
    expect($html)->toContain('never mapped to');
});
