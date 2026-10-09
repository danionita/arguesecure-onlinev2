<?php

/**
 * No rendered surface and no export column contains a level code (W87, D44).
 *
 * The other half of D44. `scale_levels.code` stays in the database, in the
 * catalogue's resolution path and in calculation-engine.md §8.3's payload —
 * `LevelCodeTest` is where that is asserted — and it is shown to the user
 * **nowhere**.
 *
 * Four surfaces were showing one before W87, and only the first was obvious:
 *
 * 1. The scale editor asked for it in a **Code** column.
 * 2. The exported methodology appendix printed it as a **Code** column, which
 *    is the one place a user actually saw one.
 * 3. `ChartCard` rendered `formatLevelCode(level.code)` as the figure for a
 *    qualitative value, and `NodeCard` rendered the engine's
 *    `impactLevelCode` / `aroLevelCode` in a chip and quoted it in the
 *    tooltip. Those looked harmless only because the seeded codes happen to
 *    match the seeded labels — freezing a code on rename is exactly what makes
 *    them diverge.
 * 4. `ScaleValidator`'s five rejection sentences named the offending band by
 *    code, which is the one message a user reads when a save fails.
 *
 * The Vue half of (3) is asserted in `tests/js/scales/`; the two server-side
 * halves are here.
 */

use App\Domain\Reporting\Export\ReportSection;
use App\Domain\Reporting\Export\ReportSectionBuilder;
use App\Domain\Reporting\ReportOptions;
use App\Domain\Structure\ScaleValidator;
use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use Tests\Feature\Reporting\ExportFixture;

test('the exported methodology table has no code column', function () {
    $assessment = ExportFixture::assessment();

    $html = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?include_methodology=1')
        ->getContent();

    // Scoped to the methodology appendix, deliberately. The *warnings*
    // appendix has a legitimate `Code` column of its own — a warning code is a
    // different thing on a different table — so an unscoped
    // `not->toContain('<th>Code</th>')` would fail for a reason that has
    // nothing to do with scale levels, and would then have to be weakened into
    // something that asserts nothing.
    $from = strpos($html, 'Appendix: methodology');
    expect($from)->not->toBeFalse();

    $end = strpos($html, '</table>', $from);
    $section = substr($html, $from, ($end === false ? strlen($html) : $end) - $from);

    // The columns that remain, asserted by name rather than by counting them:
    // a count would still pass if Code were merely renamed.
    expect($section)->toContain('<th>Label</th>')
        ->and($section)->toContain('<th>Representative</th>')
        ->and($section)->not->toContain('<th>Code</th>');
})->group('scales');

test('no level code appears anywhere in an exported report', function () {
    $assessment = ExportFixture::assessment();

    // A code nothing else in the document could plausibly contain, on the
    // assessment's own calibration. Asserting against the seeded codes would
    // be worthless — `moderate` is also a perfectly ordinary English word that
    // a label or a narrative might legitimately use.
    $scale = Scale::query()->findOrFail($assessment->impact_scale_id);
    $level = $scale->levels->first();
    $level->code = 'zzq_internal_only';
    $level->save();

    $html = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?include_methodology=1')
        ->getContent();

    expect($html)->not->toContain('zzq_internal_only')
        // …and the row it belongs to is still there, so this is not passing
        // because the appendix went missing.
        ->and($html)->toContain($level->label);
})->group('scales');

test('the methodology header and its rows are the same width', function () {
    // A dropped cell and a kept header is the failure mode of removing a
    // column by deleting one of the two places it is written. Every exporter
    // downstream (`Cell`, the DOCX and XLSX writers) assumes they agree.
    $assessment = ExportFixture::assessment();

    $scale = Scale::query()->findOrFail($assessment->impact_scale_id);

    // Built from the one branch of the assembled report the methodology
    // appendix reads, rather than from a whole assembly: this asserts the
    // mapping, and a full report would only make the failure harder to place.
    $sections = app(ReportSectionBuilder::class)->build(
        [
            'cover' => [
                'scaleCalibration' => [
                    [
                        'kind' => 'impact',
                        'isSystem' => true,
                        'levels' => $scale->levels->map(fn (ScaleLevel $l): array => [
                            'code' => $l->code,
                            'label' => $l->label,
                            'lowerBound' => (float) $l->lower_bound,
                            'upperBound' => $l->upper_bound === null ? null : (float) $l->upper_bound,
                            'representative' => (float) $l->representative,
                        ])->values()->all(),
                    ],
                ],
            ],
        ],
        ReportOptions::forDownload(),
    );

    $methodology = collect($sections)->first(fn (ReportSection $s): bool => $s->key === 'methodology');

    expect($methodology)->not->toBeNull()
        ->and($methodology->headers)->not->toContain('Code');

    foreach ($methodology->rows as $row) {
        expect(count($row))->toBe(count($methodology->headers));
    }
})->group('scales');

test('a rejected calibration names the band by its label, never by its code', function () {
    $assessment = Assessment::factory()->create();
    $scale = Scale::query()->findOrFail($assessment->impact_scale_id);

    $levels = $scale->levels->sortBy('ordinal')->values();
    // A code and a label that cannot be confused with one another, so the
    // assertion is about which one the message uses rather than about the
    // seeded data happening to make them equal.
    $levels[0]->code = 'zzq_internal_only';
    $levels[0]->label = 'Almost nothing';
    $levels[0]->representative = 99_999_999.0;

    $errors = app(ScaleValidator::class)->validate(
        $levels->all(),
        $scale->unit,
    );

    expect($errors)->not->toBeEmpty();

    foreach ($errors as $message) {
        expect($message)->not->toContain('zzq_internal_only');
    }

    expect(implode(' ', $errors))->toContain('Almost nothing');
})->group('scales');

test('the settings page sends the code but the scale summary no longer renders one', function () {
    $assessment = Assessment::factory()->create();

    $props = $this->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props'];

    $band = $props['scales']['impact']['bands'][0];

    // Present in the payload, because it is the round-trip token that freezes
    // a code across a rename — and never drawn, which is the Vue half of this
    // assertion (tests/js/scales/BandTable.spec.ts).
    expect($band)->toHaveKey('code')
        // …and absent is `color`, which D45 stopped being editable. A value
        // the editor cannot change and does not draw is one it should not be
        // handed.
        ->and($band)->not->toHaveKey('color');

    /** @var ScaleLevel $level */
    $level = Scale::query()->findOrFail($assessment->impact_scale_id)->levels->first();
    expect($band['code'])->toBe($level->code);
})->group('scales');
