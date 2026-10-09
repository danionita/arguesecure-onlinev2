<?php

namespace App\Domain\Scales;

use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;

/**
 * One shape for a scale's bands, for every screen that edits them (W87).
 *
 * Two screens edit bands now — the three cards on Assessment settings and the
 * admin **Defaults** tab (D46, D47) — and D47 is explicit that the admin wants
 * "the same band editor the assessment gets, not a different one". A component
 * can only be the same if the payload behind it is, so the payload is built
 * here rather than twice in two controllers that would then drift.
 *
 * ## `code` is in the payload and is never rendered
 *
 * It is the round-trip token that freezes a code across a rename (D44). The
 * editor carries it back untouched on save and displays it nowhere; a band the
 * editor creates carries `null` and gets a derived code server-side. Sending
 * an id instead would not work: a custom scale is cloned per assessment, so a
 * band's id does not exist until the clone runs.
 *
 * `color` is deliberately **absent**. D45 stopped it being editable, and a
 * value the editor cannot change and does not draw is a value it should not be
 * given.
 */
final class ScaleDescriber
{
    /**
     * The three scales an assessment is calibrated on, keyed by kind.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public static function forAssessment(Assessment $assessment): array
    {
        $assessment->loadMissing([
            'impactScale.levels',
            'likelihoodScale.levels',
            'strengthScale.levels',
        ]);

        $described = [];

        foreach (ScaleCalibrator::kinds() as $kind) {
            $scale = match ($kind) {
                'impact' => $assessment->impactScale,
                'likelihood' => $assessment->likelihoodScale,
                default => $assessment->strengthScale,
            };

            $described[$kind] = self::describe($scale);
        }

        return $described;
    }

    /**
     * The three scales of a seeded system set, keyed by kind.
     *
     * Any kind the set is missing comes back null rather than absent, so the
     * admin screen renders a card saying the set is incomplete instead of
     * silently showing two scales where there should be three.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public static function forSystemSet(string $setKey): array
    {
        $scales = Scale::query()
            ->whereNull('assessment_id')
            ->where('is_system', true)
            ->where('set_key', $setKey)
            ->with('levels')
            ->get()
            ->keyBy('kind');

        $described = [];

        foreach (ScaleCalibrator::kinds() as $kind) {
            $scale = $scales->get($kind);
            $described[$kind] = $scale instanceof Scale ? self::describe($scale) : null;
        }

        return $described;
    }

    /**
     * The multiplier the open top band's representative is derived with.
     *
     * Sent from the server rather than hardcoded in the editor: it is
     * configurable, and the client's suggestion has to agree with what the
     * server would have produced. Two copies of a configurable number is one
     * copy too many.
     */
    public static function topBandMultiplier(): float
    {
        return (float) config('arguesecure.scales.top_band_multiplier', 3);
    }

    /** @return array<string, mixed>|null */
    public static function describe(?Scale $scale): ?array
    {
        if ($scale === null) {
            return null;
        }

        $generator = RepresentativeGenerator::fromConfig();

        return [
            'id' => $scale->id,
            'kind' => $scale->kind,
            'name' => $scale->name,
            'unit' => $scale->unit,
            // What the "uncalibrated" advisory is keyed on, both here and in
            // the engine: an assessment still pointing at a shared system scale
            // (RiskCalculator's warning block, useScales.ts's isUncalibrated).
            // The banner it used to drive is gone (W87) — using the defaults is
            // the normal state — but the flag still says whether a save would
            // clone, which the card states as a fact rather than a warning.
            'is_system' => (bool) $scale->is_system,
            'bands' => $scale->levels->sortBy('ordinal')->map(fn (ScaleLevel $level): array => [
                'ordinal' => $level->ordinal,
                'code' => $level->code,
                'label' => $level->label,
                'lower_bound' => (float) $level->lower_bound,
                'upper_bound' => $level->upper_bound === null ? null : (float) $level->upper_bound,
                'representative' => (float) $level->representative,
                // The value the server would pre-fill for these bounds, sent
                // alongside the stored one so the editor can show that a
                // representative is no longer the suggested value without
                // re-deriving the rule in TypeScript.
                'suggested_representative' => $generator->for(
                    $scale->unit,
                    (float) $level->lower_bound,
                    $level->upper_bound === null ? null : (float) $level->upper_bound,
                ),
            ])->values()->all(),
        ];
    }
}
