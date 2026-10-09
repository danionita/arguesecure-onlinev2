<?php

namespace App\Domain\Assistant\Summary;

use App\Domain\Calculation\AttackResult;
use App\Domain\Calculation\CalculationResults;
use App\Domain\Calculation\DefenceResult;
use App\Domain\Calculation\RiskResult;
use App\Domain\Graph\AssessmentGraph;

/**
 * The assessment, written out for a model to read (W54, job C).
 *
 * ## It reports figures, it does not produce them
 *
 * Every number here comes from a `CalculationResults` the engine already
 * computed. Nothing is recomputed and nothing new is derived — the two
 * reduction percentages are ratios between two engine totals, which is the
 * same arithmetic the report page prints beside them, not a fourth baseline.
 * `RiskCalculator` stays the only thing in this application that decides what
 * a risk is worth.
 *
 * ## Why the descriptions are in here at all
 *
 * They are the injection channel — the spike put every red-team payload in a
 * node description, because that is the field any collaborator holding
 * `editor` types into freely. Leaving them out would remove the channel and
 * also remove the only thing that makes a drafted summary worth reading: the
 * figures alone produce a paragraph that names no system and says nothing a
 * table does not. So they go in, inside the fence, and the controls are the
 * ones W53 pinned: the draft is never published, a person reads it, and the
 * report discloses that a model wrote it.
 *
 * ## Everything is bounded
 *
 * An assessment has no size limit and a context window does. The spike's
 * 73-node assessment came to 6,776 prompt tokens; ten times that would be
 * silently truncated from the front by llama.cpp, which drops the rules and
 * the fence and keeps the user content — the worst possible half to keep. So
 * the digest takes the largest risks and stops, and says how many it left out,
 * which is information the model can honestly use.
 */
class AssessmentDigest
{
    /** Largest risks by current-baseline ALE. Beyond this the summary is a list, not a summary. */
    private const MAX_RISKS = 12;

    private const MAX_ATTACKS_PER_RISK = 5;

    private const MAX_DEFENCES_PER_ATTACK = 4;

    private const MAX_DESCRIPTION = 400;

    private const MAX_ROOT_DESCRIPTION = 900;

    /**
     * @param  array<string, string|null>  $descriptions  node uuid => description, from the caller's own query — the graph DTO carries none, by design (schema.md §4)
     */
    public function build(
        AssessmentGraph $graph,
        CalculationResults $results,
        array $descriptions,
    ): string {
        $lines = [];

        $lines[] = 'Target of assessment: '.($graph->rootTitle ?: 'Untitled assessment');
        $root = $this->clean($graph->rootDescription, self::MAX_ROOT_DESCRIPTION);

        if ($root !== '') {
            $lines[] = $root;
        }

        $lines[] = '';
        $lines[] = 'Currency: '.$graph->currency;
        $lines[] = 'Annual expected loss with no defences at all: '.$this->money($results->inherent->total);
        $lines[] = 'Annual expected loss with the defences in place today: '
            .$this->money($results->current->total)
            .$this->reduction($results->inherent->total, $results->current->total);
        $lines[] = 'Annual expected loss if the planned defences are added too: '
            .$this->money($results->target->total)
            .$this->reduction($results->inherent->total, $results->target->total);

        $completeness = $results->current->completeness;
        $lines[] = sprintf(
            'Quantified: %d of %d risk/attack combinations carry both an impact and a likelihood.'
            .' The totals above count only those; the rest are recorded but not valued.',
            $completeness->pairsValued,
            $completeness->pairsTotal,
        );

        $lines[] = '';
        $lines = array_merge($lines, $this->risks($graph, $results, $descriptions));
        $lines = array_merge($lines, $this->transfers($graph, $results));

        return implode("\n", $lines);
    }

    /**
     * The risk register, largest first, each with its attacks and their
     * defences.
     *
     * Nested rather than three flat lists: a decision-maker's summary is about
     * what could go wrong and what is being done about it, and a model handed
     * three disconnected tables has to re-join them itself to say that.
     *
     * @param  array<string, string|null>  $descriptions
     * @return list<string>
     */
    private function risks(
        AssessmentGraph $graph,
        CalculationResults $results,
        array $descriptions,
    ): array {
        $current = $this->byUuid($results->current->risks);
        $attacks = $this->byUuid($results->current->attacks);

        $ranked = array_values(array_filter(
            $current,
            fn (RiskResult $risk): bool => isset($graph->risks[$risk->uuid]),
        ));

        usort($ranked, fn (RiskResult $a, RiskResult $b): int => $b->ale <=> $a->ale);

        $shown = array_slice($ranked, 0, self::MAX_RISKS);
        $lines = ['Risks, largest first, with the defences that reduce them:'];

        foreach ($shown as $index => $risk) {
            $node = $graph->risks[$risk->uuid];

            $lines[] = '';
            $lines[] = sprintf(
                '%d. %s - %s a year at today\'s defences',
                $index + 1,
                $node['title'] ?: '(untitled risk)',
                $risk->ale > 0.0 ? $this->money($risk->ale) : 'not quantified',
            );

            $description = $this->clean($descriptions[$risk->uuid] ?? null, self::MAX_DESCRIPTION);

            if ($description !== '') {
                $lines[] = '   '.$description;
            }

            foreach ($this->attacksOf($graph, $risk->uuid, $attacks) as $attackUuid) {
                $attack = $graph->attacks[$attackUuid];
                $result = $attacks[$attackUuid] ?? null;

                $lines[] = sprintf(
                    '   Attack: %s%s',
                    $attack['title'] ?: '(untitled attack)',
                    $result?->aroBase !== null
                        ? sprintf(' (expected %s times a year before defences)', $this->rate($result->aroBase))
                        : ' (no likelihood recorded)',
                );

                foreach ($this->defencesOf($graph, $attackUuid) as $line) {
                    $lines[] = '      '.$line;
                }
            }
        }

        $omitted = count($ranked) - count($shown);

        if ($omitted > 0) {
            $lines[] = '';
            $lines[] = sprintf(
                '%d further risks are recorded and are not listed here. They are smaller than every risk above.',
                $omitted,
            );
        }

        return $lines;
    }

    /**
     * @param  array<string, AttackResult>  $attackResults
     * @return list<string> attack uuids, largest first
     */
    private function attacksOf(AssessmentGraph $graph, string $riskUuid, array $attackResults): array
    {
        $uuids = [];

        foreach ($graph->riskAttackLinks as $link) {
            if ($link['risk_uuid'] === $riskUuid && isset($graph->attacks[$link['attack_uuid']])) {
                $uuids[] = $link['attack_uuid'];
            }
        }

        usort($uuids, fn (string $a, string $b): int => ($attackResults[$b]->ale ?? 0.0) <=> ($attackResults[$a]->ale ?? 0.0));

        return array_slice($uuids, 0, self::MAX_ATTACKS_PER_RISK);
    }

    /**
     * The defences on one attack, said in the words the report uses.
     *
     * A transfer is named as one. It is a presentation variant of a defence
     * and not a database type, but the distinction is exactly what an
     * executive summary is for — "we hold this risk and reduced it" and "we
     * moved this risk to an insurer" are different sentences to a reader.
     *
     * @return list<string>
     */
    private function defencesOf(AssessmentGraph $graph, string $attackUuid): array
    {
        $lines = [];

        foreach ($graph->attackDefenceLinks as $link) {
            if ($link['attack_uuid'] !== $attackUuid) {
                continue;
            }

            $defence = $graph->defences[$link['defence_uuid']] ?? null;

            if ($defence === null || ($defence['status'] ?? null) === 'shelved') {
                // Shelved never counts towards a baseline, so a summary that
                // mentioned it would be describing protection nobody has.
                continue;
            }

            $lines[] = sprintf(
                '%s: %s%s%s',
                ($defence['status'] ?? null) === 'implemented' ? 'In place' : 'Planned',
                $defence['title'] ?: '(untitled)',
                $defence['is_transfer'] ? ' (a transfer: the loss moves to another party)' : '',
                $link['strength_fraction'] !== null
                    ? sprintf(', reduces this attack by %d%%', (int) round($link['strength_fraction'] * 100))
                    : ', effectiveness not recorded',
            );

            if (count($lines) >= self::MAX_DEFENCES_PER_ATTACK) {
                break;
            }
        }

        return $lines;
    }

    /**
     * What the assessment spends, and what it moved elsewhere.
     *
     * Costs come from the engine's own DefenceResult, annualised there, so
     * the summary and the programme table cannot disagree about what a control
     * costs.
     *
     * @return list<string>
     */
    private function transfers(AssessmentGraph $graph, CalculationResults $results): array
    {
        $annualCost = 0.0;
        $planned = 0;
        $transfers = [];

        foreach ($results->current->defences as $defence) {
            $node = $graph->defences[$defence->uuid] ?? null;

            if ($node === null) {
                continue;
            }

            if ($defence->status === 'implemented') {
                $annualCost += $defence->annualCost ?? 0.0;
            }

            if ($defence->status === 'todo') {
                $planned++;
            }

            if ($node['is_transfer'] && $defence->status !== 'shelved') {
                $transfers[] = $node['title'] ?: '(untitled transfer)';
            }
        }

        $lines = ['', sprintf(
            'The defences in place today cost %s a year, annualised. %d further defences are planned and not yet in place.',
            $this->money($annualCost),
            $planned,
        )];

        if ($transfers !== []) {
            $lines[] = 'Risk moved to another party by contract, disclaimer or insurance: '
                .implode('; ', $transfers).'.';
        }

        return $lines;
    }

    /**
     * @template T of RiskResult|AttackResult|DefenceResult
     *
     * @param  list<T>  $results
     * @return array<string, T>
     */
    private function byUuid(array $results): array
    {
        $keyed = [];

        foreach ($results as $result) {
            $keyed[$result->uuid] = $result;
        }

        return $keyed;
    }

    /**
     * Money as digits and a currency code, never a locale format.
     *
     * Thousands separators and currency symbols are a formatting decision the
     * report page makes for a human reader. Here they are noise a model may
     * reproduce inconsistently, and the prompt's "do not round them into
     * different numbers" rule is easier to keep against a plain integer.
     */
    private function money(float $amount): string
    {
        return number_format($amount, 0, '.', '');
    }

    /** A rate per year, which may exceed 1 and is never a probability. */
    private function rate(float $aro): string
    {
        return rtrim(rtrim(number_format($aro, 2, '.', ''), '0'), '.');
    }

    /**
     * A reduction against the inherent baseline, or nothing at all.
     *
     * Nothing when there is no inherent loss to reduce: "100% lower than zero"
     * is a sentence a model will happily build a paragraph on.
     */
    private function reduction(float $inherent, float $baseline): string
    {
        if ($inherent <= 0.0) {
            return '';
        }

        return sprintf(' (%d%% lower)', (int) round((1 - $baseline / $inherent) * 100));
    }

    private function clean(?string $text, int $max): string
    {
        $collapsed = trim(preg_replace('/\s+/u', ' ', $text ?? '') ?? '');

        return mb_substr($collapsed, 0, $max);
    }
}
