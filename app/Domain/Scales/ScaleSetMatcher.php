<?php

namespace App\Domain\Scales;

use App\Models\Assessment;
use App\Models\Scale;
use Illuminate\Support\Collection;

/**
 * Which admin scale an assessment's bands equal, if any (W126, D93).
 *
 * Compared by label, bounds and representative, in order, per kind, never by
 * id: an assessment holds its own copy, so ids never match. Run only at the
 * moments an assessment's bands are written, so a later admin edit to a scale
 * does not silently turn every assessment created from it into Custom.
 */
final class ScaleSetMatcher
{
    /** The first scale (in list order) whose three kinds equal the assessment's, else null (Custom). */
    public static function keyFor(Assessment $assessment): ?string
    {
        $assessment->unsetRelation('impactScale')->unsetRelation('likelihoodScale')->unsetRelation('strengthScale');
        $assessment->load(['impactScale.levels', 'likelihoodScale.levels', 'strengthScale.levels']);

        $mine = [
            'impact' => self::signature($assessment->impactScale),
            'likelihood' => self::signature($assessment->likelihoodScale),
            'strength' => self::signature($assessment->strengthScale),
        ];

        // All system scales with their levels in one query, grouped by set:
        // a save compares against every scale, and a query per kind per scale
        // would run inside the save's transaction.
        /** @var Collection<string, Collection<int, Scale>> $bySet */
        $bySet = Scale::query()->whereNull('assessment_id')->where('is_system', true)->whereNotNull('set_key')
            ->with('levels')->get()->groupBy('set_key');

        foreach (ScaleSets::selectableKeys() as $key) {
            $kinds = ($bySet->get($key) ?? collect())->keyBy('kind');

            if (! $kinds->has('impact') || ! $kinds->has('likelihood') || ! $kinds->has('strength')) {
                continue;
            }

            $equal = true;

            foreach (ScaleSets::KINDS as $kind) {
                if (self::signature($kinds->get($kind)) !== $mine[$kind]) {
                    $equal = false;
                    break;
                }
            }

            if ($equal) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<list<string|null>> */
    private static function signature(?Scale $scale): array
    {
        if ($scale === null) {
            return [];
        }

        return $scale->levels->sortBy('ordinal')->map(static fn ($level): array => [
            $level->label,
            number_format((float) $level->lower_bound, 6, '.', ''),
            $level->upper_bound === null ? null : number_format((float) $level->upper_bound, 6, '.', ''),
            number_format((float) $level->representative, 6, '.', ''),
        ])->values()->all();
    }
}
