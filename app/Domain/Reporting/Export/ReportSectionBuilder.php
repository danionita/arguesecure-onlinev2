<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Reporting\Money;
use App\Domain\Reporting\ReportOptions;

/**
 * The one projection every export renders from (W74 item 25).
 *
 * ## Why this exists
 *
 * D34 puts five formats behind one menu. The obvious way to build five
 * formats is five classes that each walk the report and each decide which
 * figure goes in which column — and the day someone adds a column to the
 * defence ranking, four of the five are wrong and nothing fails. So there is
 * **one assembled report and five renderings of it**: `ReportAssembler`
 * produces the figures, this class arranges them into ordered sections, and
 * the exporters below only know how to write a heading, a paragraph and a
 * table in their own file format.
 *
 * Nothing here queries. Nothing here calculates. Every number in every cell
 * came out of `App\Domain\Calculation` and through `ReportAssembler`; this
 * class selects and labels, exactly as the Vue sections do on screen.
 *
 * ## What the options bind
 *
 * `ReportOptions` binds all five formats, not just the page (W33's finding
 * about the download button, restated for exports):
 *
 * - **`baseline`** — the current and target columns appear only when chosen.
 * - **`includeShelved`** — the shelved section exists only when asked for.
 *   Shelved controls never count towards a total in any baseline; the
 *   section is "what was given up", and an export that always carried it
 *   would put rejected controls in a document sent to an auditor.
 * - **`includeComments`** — the comment appendix, which is the one option
 *   that changes what was *fetched* upstream.
 * - **`includeMethodology`** — the appendix stating the independence
 *   assumption and the per-occurrence definition of impact.
 * - **`showAssignees`** — adds a column of names, and only when chosen. A
 *   report is a document that leaves the room.
 *
 * **That list is now the whole of it.** Through W79 there was a sixth
 * option, `figures`, which this class received and ignored — it bound
 * nothing in any renderer and never had. W80 removed it rather than building
 * it: calculation-engine.md §8.3 renders every ALE, cost, CBR, ROSI and
 * net-benefit figure as currency and §11.2 step 8 forbids band-mapping them,
 * so no option could have made a "qualitative" document out of this one, and
 * the per-node half of the idea — a value shown as the level its author
 * chose — already happens without being asked for. `ReportOptions`' docblock
 * carries the argument in full.
 */
final class ReportSectionBuilder
{
    /** D65's three states, as the coverage section words them. */
    private const COVERAGE_STATUS = [
        'implemented' => 'Implemented',
        'planned' => 'Planned',
        'shelved' => 'Shelved',
    ];

    /**
     * @param  array<string, mixed>  $report  the whole of ReportAssembler::assemble()
     * @return list<ReportSection>
     */
    public function build(array $report, ReportOptions $options): array
    {
        $sections = [
            $this->cover($report),
            $this->writtenSummary($report),
            $this->executiveSummary($report, $options),
            $this->programme($report),
            $this->riskRegister($report, $options),
            $this->defences(
                'defences-importance',
                'Defences ranked by importance',
                'Implemented controls, by marginal contribution — how much annual expected loss would return if each were switched off.',
                'If removed',
                $this->arr($report['defencesByImportance'] ?? []),
            ),
            $this->defences(
                'defences-opportunity',
                'Defences ranked by opportunity',
                'Todo controls, by what implementing each would buy against the current baseline — the roadmap.',
                'If implemented',
                $this->arr($report['defencesByOpportunity'] ?? []),
            ),
            $options->includeShelved ? $this->defences(
                'defences-shelved',
                'Shelved defences — what was given up',
                'Evaluated on demand only; never included in any total.',
                'If unshelved',
                $this->arr($report['shelvedOpportunity'] ?? []),
            ) : null,
            $this->transfers($report, $options),
            $this->controlCoverage($report),
            $this->tree($report, $options),
            $this->references($report),
            $options->includeComments ? $this->comments($report) : null,
            $this->warnings($report),
            $options->includeMethodology ? $this->methodology($report) : null,
        ];

        return array_values(array_filter(
            $sections,
            fn (?ReportSection $section): bool => $section !== null && ($section->hasTable() || $section->prose !== []),
        ));
    }

    /** @param array<string, mixed> $report */
    private function cover(array $report): ReportSection
    {
        $cover = $this->arr($report['cover'] ?? []);
        $completeness = $this->arr($cover['completeness'] ?? []);
        $owner = $this->arr($cover['owner'] ?? []);

        $rows = [
            [Cell::text('Target of assessment'), Cell::text($this->str($cover['title'] ?? null) ?: 'Untitled assessment')],
            [Cell::text('Owner'), Cell::text($this->str($owner['name'] ?? null))],
            [Cell::text('Generated'), Cell::text($this->str($cover['generatedAt'] ?? null))],
            [Cell::text('Currency'), Cell::text($this->currency($report))],
            [Cell::text('Valued risk/attack pairs'), Cell::text(
                $this->int($completeness['pairsValued'] ?? 0).' of '.$this->int($completeness['pairsTotal'] ?? 0)
            )],
            [Cell::text('Coverage'), Cell::percent($this->float($completeness['coverage'] ?? null))],
        ];

        $prose = [];
        $description = $this->str($cover['description'] ?? null);
        if ($description !== '') {
            $prose[] = $description;
        }

        // ui.md §9, "Presenting the numbers honestly": a partial total is
        // prefixed with ≥ and its coverage stated. On screen that is a glyph
        // beside the figure; in a file that will be read without the page
        // beside it, it has to be a sentence.
        if (($completeness['isPartial'] ?? false) === true) {
            $prose[] = 'Some risk/attack pairs carry no impact or no likelihood and are excluded from every total rather than counted as zero. Totals in this report are therefore lower bounds: read each as "at least".';
        }

        return new ReportSection('cover', 'Report', $prose, ['Field', 'Value'], $rows);
    }

    /**
     * The author's own words (W54), which the Word and HTML exports carry as
     * the prose section they are. `writtenSummary` comes off the assembled
     * report rather than off the model a second time, for the same reason
     * every figure does.
     *
     * @param  array<string, mixed>  $report
     */
    private function writtenSummary(array $report): ReportSection
    {
        $summary = $this->arr($report['writtenSummary'] ?? []);
        $text = $this->str($summary['text'] ?? null);

        if (trim($text) === '') {
            return new ReportSection('written-summary', 'Written summary');
        }

        $prose = array_values(array_filter(
            array_map(trim(...), preg_split('/\R{2,}/', $text) ?: []),
            fn (string $paragraph): bool => $paragraph !== '',
        ));

        // The disclosure, and it prints (ui.md §9). Sticky: accepting a draft
        // sets it and editing the text afterwards does not clear it, so an
        // export made long after the fact still carries it.
        if (($summary['generated'] ?? false) === true) {
            $prose[] = 'Drafted by a local model and edited by the author, who is responsible for it.';
        }

        return new ReportSection('written-summary', 'Written summary', $prose);
    }

    /** @param array<string, mixed> $report */
    private function executiveSummary(array $report, ReportOptions $options): ReportSection
    {
        $summary = $this->arr($report['executiveSummary'] ?? []);
        $costs = $this->arr($summary['annualCostOfControls'] ?? []);

        $rows = [$this->baselineRow('Inherent — before any defence', $this->arr($summary['inherent'] ?? []), null)];

        if ($options->showsCurrent()) {
            $rows[] = $this->baselineRow(
                'Current — implemented defences only',
                $this->arr($summary['current'] ?? []),
                $this->float($summary['reductionCurrent'] ?? null),
            );
        }

        if ($options->showsTarget()) {
            $rows[] = $this->baselineRow(
                'Target — implemented and todo',
                $this->arr($summary['target'] ?? []),
                $this->float($summary['reductionTarget'] ?? null),
            );
        }

        $prose = [
            'Annual expected loss under each baseline. Shelved controls never count towards any of them.',
            'Implemented controls: '.$this->int($costs['implementedCount'] ?? 0)
                .'. Recorded annual cost: '.(new Money($this->currency($report)))->format($this->float($costs['priced'] ?? null) ?? 0.0)
                .'. Controls with no cost recorded: '.$this->int($costs['unpricedCount'] ?? 0)
                .' — counted here and never folded into that total, because an unpriced control is not a free one.',
        ];

        return new ReportSection(
            'executive-summary',
            'Executive summary',
            $prose,
            ['Baseline', 'Annual expected loss', 'Reduction vs inherent', 'Pairs valued', 'Pairs total', 'Coverage'],
            $rows,
        );
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<Cell>
     */
    private function baselineRow(string $label, array $block, ?float $reduction): array
    {
        // The ≥ that ui.md §9 requires on a partial total. It rides in the
        // label rather than in the money cell so the cell stays a number a
        // spreadsheet can sum; the cover section states the caveat in full.
        $partial = ($block['isPartial'] ?? false) === true;

        return [
            Cell::text($label.($partial ? ' (≥, partial)' : '')),
            Cell::money($this->float($block['total'] ?? null)),
            Cell::percent($reduction),
            Cell::count($this->int($block['pairsValued'] ?? 0)),
            Cell::count($this->int($block['pairsTotal'] ?? 0)),
            Cell::percent($this->float($block['coverage'] ?? null)),
        ];
    }

    /** @param array<string, mixed> $report */
    private function programme(array $report): ReportSection
    {
        $programme = $this->arr($report['programme'] ?? []);
        $planned = $this->arr($programme['planned'] ?? []);
        $reduced = $this->arr($programme['riskReduced'] ?? []);

        $rows = [
            [Cell::text('Planned controls'), Cell::count($this->int($planned['count'] ?? 0))],
            [Cell::text('Annualised cost of the plan'), Cell::money($this->float($planned['annualisedCost'] ?? null))],
            [Cell::text('— of which one-off'), Cell::money($this->float($planned['oneOffCost'] ?? null))],
            [Cell::text('— of which recurring per year'), Cell::money($this->float($planned['recurringCost'] ?? null))],
            [Cell::text('Planned controls recorded as free'), Cell::count($this->int($planned['freeCount'] ?? 0))],
            [Cell::text('Planned controls with no cost recorded'), Cell::count($this->int($planned['unpricedCount'] ?? 0))],
            [Cell::text('Risk removed, current to target'), Cell::money($this->float($reduced['currentToTarget'] ?? null))],
            [Cell::text('Risk removed, inherent to target'), Cell::money($this->float($reduced['inherentToTarget'] ?? null))],
            [Cell::text('Return on the plan'), Cell::number($this->float($programme['returnOnPlanned'] ?? null))],
        ];

        return new ReportSection(
            'programme',
            'The plan: cost and benefit',
            [
                'What the todo controls cost, annualised and split into one-off and recurring, against the risk they remove.',
                'Controls with no cost recorded are counted beside these totals and never folded into them: an unpriced control is not a free one. "Return on the plan" is blank rather than infinite when the plan has no recorded cost — an unknown denominator is not a windfall.',
            ],
            ['Figure', 'Value'],
            $rows,
        );
    }

    /** @param array<string, mixed> $report */
    private function riskRegister(array $report, ReportOptions $options): ReportSection
    {
        $headers = ['Level', 'Title', 'Impact per occurrence', 'Inherent ALE'];
        if ($options->showsCurrent()) {
            $headers[] = 'Current ALE';
        }
        if ($options->showsTarget()) {
            $headers[] = 'Target ALE';
        }
        $headers[] = 'Reduction vs inherent';

        $rows = [];

        foreach ($this->rowsOf($report['riskRegister'] ?? []) as $risk) {
            $inherent = $this->arr($risk['inherent'] ?? []);
            $current = $this->arr($risk['current'] ?? []);
            $target = $this->arr($risk['target'] ?? []);

            $row = [
                Cell::text('Risk'),
                Cell::text($this->str($risk['title'] ?? null)),
                Cell::money($this->float($risk['impactAmount'] ?? null)),
                Cell::money($this->float($inherent['ale'] ?? null)),
            ];
            if ($options->showsCurrent()) {
                $row[] = Cell::money($this->float($current['ale'] ?? null));
            }
            if ($options->showsTarget()) {
                $row[] = Cell::money($this->float($target['ale'] ?? null));
            }
            $row[] = Cell::percent($this->float(
                ($options->showsTarget() ? $risk['reductionTarget'] : $risk['reductionCurrent']) ?? null
            ));

            $rows[] = $row;

            // The attacks under each risk, as their own rows rather than a
            // second table: a risk register that lists risks without the
            // attacks that realise them is a list of headings.
            foreach ($this->rowsOf($risk['attacks'] ?? []) as $attack) {
                $attackRow = [
                    Cell::text('Attack'),
                    Cell::text($this->str($attack['title'] ?? null)),
                    Cell::text(''),
                    Cell::text(''),
                ];
                if ($options->showsCurrent()) {
                    $attackRow[] = Cell::money(
                        ($attack['currentValued'] ?? false) === true ? $this->float($attack['currentAle'] ?? null) : null
                    );
                }
                if ($options->showsTarget()) {
                    $attackRow[] = Cell::money(
                        ($attack['targetValued'] ?? false) === true ? $this->float($attack['targetAle'] ?? null) : null
                    );
                }
                $attackRow[] = Cell::text('');

                $rows[] = $attackRow;
            }
        }

        return new ReportSection(
            'risk-register',
            'Risk register',
            ['Every risk with the attacks that realise it. A dash is a value nobody entered — it is excluded from the totals, never counted as zero.'],
            $headers,
            $rows,
        );
    }

    /**
     * The ranked defence lists (calculation-engine.md §10): three buckets,
     * never one merged list, and never `∞` for a control that costs nothing
     * recorded.
     *
     * @param  array<string, mixed>  $buckets
     */
    private function defences(string $key, string $title, string $prose, string $benefitLabel, array $buckets): ReportSection
    {
        $currencyPerYear = ' (per year)';
        $rows = [];

        foreach (['priced' => 'Priced', 'free' => 'Free', 'unpriced' => 'Unpriced'] as $bucketKey => $bucketLabel) {
            foreach ($this->rowsOf($buckets[$bucketKey] ?? []) as $defence) {
                $rows[] = [
                    Cell::text($bucketLabel),
                    Cell::text($this->str($defence['title'] ?? null)),
                    Cell::text($this->str($defence['status'] ?? null)),
                    Cell::text(($defence['isTransfer'] ?? false) === true ? 'Yes' : 'No'),
                    Cell::money($this->float($defence['benefit'] ?? null)),
                    Cell::money($this->float($defence['annualCost'] ?? null)),
                    Cell::number($this->float($defence['cbr'] ?? null)),
                    Cell::number($this->float($defence['rosi'] ?? null)),
                    Cell::money($this->float($defence['netBenefit'] ?? null)),
                    Cell::money($this->float($defence['attribution'] ?? null)),
                ];
            }
        }

        return new ReportSection(
            $key,
            $title,
            [
                $prose,
                'Attribution is the log-share metric, not a decomposition of marginal contribution: reductions multiply, so removal deltas overlap and cannot be summed. CBR and ROSI are blank for the free and unpriced buckets rather than infinite.',
            ],
            [
                'Cost bucket', 'Title', 'Status', 'Transfer',
                $benefitLabel.$currencyPerYear, 'Annual cost'.$currencyPerYear,
                'CBR', 'ROSI', 'Net benefit'.$currencyPerYear, 'Attribution',
            ],
            $rows,
        );
    }

    /** @param array<string, mixed> $report */
    private function transfers(array $report, ReportOptions $options): ReportSection
    {
        $headers = ['Transfer', 'Status', 'Attack', 'Reduces', 'Strength', 'Risk'];
        if ($options->showsCurrent()) {
            $headers[] = 'Retained impact (current)';
        }
        if ($options->showsTarget()) {
            $headers[] = 'Retained impact (target)';
        }

        $rows = [];

        foreach ($this->rowsOf($report['transfers'] ?? []) as $transfer) {
            foreach ($this->rowsOf($transfer['links'] ?? []) as $link) {
                foreach ($this->rowsOf($link['retained'] ?? []) as $retained) {
                    $row = [
                        Cell::text($this->str($transfer['title'] ?? null)),
                        Cell::text($this->str($transfer['status'] ?? null)),
                        Cell::text($this->str($link['attackTitle'] ?? null)),
                        Cell::text($this->str($link['reductionTarget'] ?? null)),
                        Cell::percent($this->float($link['strength'] ?? null)),
                        Cell::text($this->str($retained['riskTitle'] ?? null)),
                    ];
                    if ($options->showsCurrent()) {
                        $row[] = Cell::money($this->float($retained['currentRetainedImpact'] ?? null));
                    }
                    if ($options->showsTarget()) {
                        $row[] = Cell::money($this->float($retained['targetRetainedImpact'] ?? null));
                    }

                    $rows[] = $row;
                }
            }
        }

        return new ReportSection(
            'transfers',
            'Transfers and retained risk',
            ['A transfer moves loss to another party through a contract, a disclaimer or an insurer. What is retained is what the other party did not take.'],
            $headers,
            $rows,
        );
    }

    /**
     * PLAN-2.7 D65: the controls the defences cite, one row per citing
     * defence. No section at all when nothing cites one (D66's count of
     * shelved-only controls aside), so an assessment
     * that is not about a framework does not get an empty heading about
     * frameworks. The status column is the control's, repeated on each of
     * its rows so a filtered spreadsheet still reads correctly.
     *
     * @param  array<string, mixed>  $report
     */
    private function controlCoverage(array $report): ReportSection
    {
        $coverage = $this->arr($report['controlCoverage'] ?? []);
        $frameworks = $this->rowsOf($coverage['frameworks'] ?? []);
        $hidden = $this->int($coverage['hiddenShelved'] ?? 0);
        if ($frameworks === [] && $hidden === 0) {
            return new ReportSection('control-coverage', 'Control coverage');
        }

        $rows = [];
        foreach ($frameworks as $framework) {
            foreach ($this->rowsOf($framework['controls'] ?? []) as $control) {
                foreach ($this->rowsOf($control['defences'] ?? []) as $defence) {
                    $rows[] = [
                        Cell::text($this->str($framework['label'] ?? null)),
                        Cell::text($this->str($control['label'] ?? null)),
                        Cell::text(self::COVERAGE_STATUS[$this->str($control['status'] ?? null)] ?? ''),
                        Cell::text($this->str($defence['title'] ?? null)),
                        Cell::text($this->str($defence['status'] ?? null)),
                        Cell::text($this->int($defence['attackCount'] ?? 0) === 0 ? 'None' : (string) $this->int($defence['attackCount'] ?? 0)),
                    ];
                }
            }
        }

        $prose = ['The framework controls this assessment\'s defences cite, and whether a defence citing each is in place. It lists only what is cited, not every control a framework contains, and it is not a statement of compliance.'];
        if ($hidden > 0) {
            $prose[] = $hidden === 1
                ? '1 control cited only by shelved defences is not listed; include shelved defences to see it.'
                : "{$hidden} controls cited only by shelved defences are not listed; include shelved defences to see them.";
        }

        return new ReportSection(
            'control-coverage',
            'Control coverage',
            $prose,
            ['Framework', 'Control', 'Control status', 'Cited by', 'Defence status', 'Attacks it counters'],
            $rows,
        );
    }

    /**
     * The full-assessment section: the list view on paper (ui.md §9), and the
     * one place the DAG's **edges** appear — a defence row carries the
     * strength, reduction target and rationale of *that link*, not of the
     * defence, because one control may be 80% effective against one attack
     * and 20% against another (CLAUDE.md, schema.md). A CSV of defences
     * alone could not express that, which is half of why item 25 widened it.
     *
     * @param  array<string, mixed>  $report
     */
    private function tree(array $report, ReportOptions $options): ReportSection
    {
        $headers = ['Level', 'Title', 'Status', 'Transfer', 'Impact per occurrence', 'Likelihood', 'Strength on this link', 'Reduces', 'Rationale', 'One-off cost', 'Recurring cost per year', 'Annualised cost', 'Inherent ALE'];
        if ($options->showsCurrent()) {
            $headers[] = 'Current ALE';
        }
        if ($options->showsTarget()) {
            $headers[] = 'Target ALE';
        }
        if ($options->showAssignees) {
            $headers[] = 'Assignees';
        }

        $tree = $this->arr($report['tree'] ?? []);
        $rows = [];

        foreach ($this->rowsOf($tree['risks'] ?? []) as $risk) {
            $rows[] = $this->treeRow($options, 'Risk', $risk, [
                'impact' => $this->float($risk['impactAmount'] ?? null),
            ]);

            foreach ($this->rowsOf($risk['attacks'] ?? []) as $attack) {
                $rows[] = $this->treeRow($options, 'Attack', $attack, [
                    'likelihood' => $this->float($attack['likelihoodAro'] ?? null),
                ]);

                foreach ($this->rowsOf($attack['defences'] ?? []) as $defence) {
                    $rows[] = $this->treeRow($options, 'Defence', $defence, [
                        'strength' => $this->float($defence['strength'] ?? null),
                        'reduces' => $this->str($defence['reductionTarget'] ?? null),
                        'rationale' => $this->str($defence['rationale'] ?? null),
                        'capex' => $this->float($defence['capexAmount'] ?? null),
                        'opex' => $this->float($defence['opexAnnualAmount'] ?? null),
                    ]);
                }
            }
        }

        foreach ($this->rowsOf($tree['orphanAttacks'] ?? []) as $orphan) {
            $rows[] = $this->treeRow($options, 'Attack (unlinked)', $orphan, []);
        }
        foreach ($this->rowsOf($tree['orphanDefences'] ?? []) as $orphan) {
            $rows[] = $this->treeRow($options, 'Defence (unlinked)', $orphan, []);
        }

        return new ReportSection(
            'full-assessment',
            'Full assessment',
            ['Every node with the values a person entered and the ones the engine computed. A missing value is a dash.'],
            $headers,
            $rows,
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, float|string|null>  $extra
     * @return list<Cell>
     */
    private function treeRow(ReportOptions $options, string $level, array $node, array $extra): array
    {
        $inherent = $this->arr($node['inherent'] ?? []);
        $current = $this->arr($node['current'] ?? []);
        $target = $this->arr($node['target'] ?? []);

        // A defence has no ALE of its own; what it has is a marginal
        // contribution, which is already its own column in the ranked
        // sections. Leaving the ALE columns empty here rather than repeating
        // MC under a heading that means something else.
        $isDefence = str_starts_with($level, 'Defence');

        $row = [
            Cell::text($level),
            Cell::text($this->str($node['title'] ?? null)),
            Cell::text($this->str($node['status'] ?? null)),
            Cell::text(array_key_exists('isTransfer', $node) ? (($node['isTransfer'] ?? false) === true ? 'Yes' : 'No') : ''),
            Cell::money($this->floatOrNull($extra['impact'] ?? null)),
            Cell::rate($this->floatOrNull($extra['likelihood'] ?? null)),
            Cell::percent($this->floatOrNull($extra['strength'] ?? null)),
            Cell::text(is_string($extra['reduces'] ?? null) ? $extra['reduces'] : ''),
            Cell::text(is_string($extra['rationale'] ?? null) ? $extra['rationale'] : ''),
            Cell::money($this->floatOrNull($extra['capex'] ?? null)),
            Cell::money($this->floatOrNull($extra['opex'] ?? null)),
            // opex + capex/amortization_years, already amortised — the two
            // columns before it are the breakdown, not additional cost.
            $isDefence ? Cell::money($this->float($current['annualCost'] ?? null)) : Cell::text(''),
            $isDefence ? Cell::text('') : Cell::money($this->float($inherent['ale'] ?? null)),
        ];

        if ($options->showsCurrent()) {
            $row[] = $isDefence ? Cell::text('') : Cell::money($this->float($current['ale'] ?? null));
        }
        if ($options->showsTarget()) {
            $row[] = $isDefence ? Cell::text('') : Cell::money($this->float($target['ale'] ?? null));
        }
        if ($options->showAssignees) {
            $row[] = Cell::text(implode(', ', $this->strings($node['assignees'] ?? [])));
        }

        return $row;
    }

    /** @param array<string, mixed> $report */
    private function references(array $report): ReportSection
    {
        $rows = [];
        foreach ($this->rowsOf($this->arr($report['appendices'] ?? [])['references'] ?? []) as $reference) {
            $rows[] = [
                Cell::text($this->str($reference['nodeTitle'] ?? null)),
                Cell::text($this->str($reference['kind'] ?? null)),
                Cell::text($this->str($reference['identifier'] ?? null)),
                Cell::text($this->str($reference['title'] ?? null)),
                Cell::text($this->str($reference['url'] ?? null)),
            ];
        }

        return new ReportSection(
            'references',
            'Appendix: references',
            [],
            ['Node', 'Kind', 'Identifier', 'Title', 'URL'],
            $rows,
        );
    }

    /** @param array<string, mixed> $report */
    private function comments(array $report): ReportSection
    {
        $rows = [];
        foreach ($this->rowsOf($this->arr($report['appendices'] ?? [])['comments'] ?? []) as $comment) {
            $rows[] = [
                Cell::text($this->str($comment['nodeTitle'] ?? null)),
                Cell::text($this->str($comment['author'] ?? null)),
                Cell::text($this->str($comment['createdAt'] ?? null)),
                Cell::text(($comment['resolved'] ?? false) === true ? 'Resolved' : 'Open'),
                Cell::text($this->str($comment['body'] ?? null)),
            ];

            foreach ($this->rowsOf($comment['replies'] ?? []) as $reply) {
                $rows[] = [
                    Cell::text($this->str($comment['nodeTitle'] ?? null)),
                    Cell::text($this->str($reply['author'] ?? null)),
                    Cell::text($this->str($reply['createdAt'] ?? null)),
                    Cell::text('Reply'),
                    Cell::text($this->str($reply['body'] ?? null)),
                ];
            }
        }

        return new ReportSection(
            'comments',
            'Appendix: comments',
            [],
            ['Node', 'Author', 'When', 'State', 'Comment'],
            $rows,
        );
    }

    /** @param array<string, mixed> $report */
    private function warnings(array $report): ReportSection
    {
        $rows = [];
        foreach ($this->rowsOf($this->arr($report['appendices'] ?? [])['warnings'] ?? []) as $warning) {
            $rows[] = [
                Cell::text($this->str($warning['code'] ?? null)),
                Cell::text($this->str($warning['nodeTitle'] ?? null)),
                Cell::text($this->str($warning['message'] ?? null)),
            ];
        }

        return new ReportSection(
            'warnings',
            'Appendix: warnings',
            [],
            ['Code', 'Node', 'Warning'],
            $rows,
        );
    }

    /** @param array<string, mixed> $report */
    private function methodology(array $report): ReportSection
    {
        $rows = [];
        foreach ($this->rowsOf($this->arr($report['cover'] ?? [])['scaleCalibration'] ?? []) as $scale) {
            foreach ($this->rowsOf($scale['levels'] ?? []) as $level) {
                $rows[] = [
                    Cell::text($this->str($scale['kind'] ?? null)),
                    Cell::text(($scale['isSystem'] ?? false) === true ? 'System' : 'Assessment'),
                    Cell::text($this->str($level['label'] ?? null)),
                    Cell::number($this->float($level['lowerBound'] ?? null)),
                    Cell::number($this->float($level['upperBound'] ?? null)),
                    Cell::number($this->float($level['representative'] ?? null)),
                ];
            }
        }

        return new ReportSection(
            'methodology',
            'Appendix: methodology',
            [
                'Annual expected loss for a risk/attack pair is the attack rate per year multiplied by the loss per occurrence of the risk, multiplied by (1 − strength) for each defence in the baseline being read.',
                'Impact is loss per occurrence, not annual loss. Likelihood is a rate per year, not a probability, and may exceed 1.',
                'Defences are assumed independent. Where they are not — two controls stopping the same step of the same attack — this overstates the reduction they buy together, and the warnings appendix names each place the assumption was applied.',
                'A pair with no impact or no likelihood is excluded from the total rather than counted as zero, because counting it as zero would understate risk. A defence with no recorded strength is treated as reducing nothing, which overstates residual risk — the safe direction.',
                'Computed losses are reported as currency with a reduction percentage. They are never mapped to a low/medium/high band.',
            ],
            // No Code column (W87, D44). `scale_levels.code` is internal — it
            // is derived from the label and frozen on rename — and this export
            // was the one place a user actually saw one. Origin and Label say
            // everything the row is for.
            ['Scale', 'Origin', 'Label', 'Lower bound', 'Upper bound', 'Representative'],
            $rows,
        );
    }

    /** @param array<string, mixed> $report */
    private function currency(array $report): string
    {
        return $this->str($this->arr($report['assessment'] ?? [])['currency'] ?? null) ?: 'EUR';
    }

    /**
     * The accessors below exist because the assembled report is an
     * `array<string, mixed>` — it is Inertia page-prop data, not a DTO — and
     * PHPStan is right to refuse to guess. Narrowing once here beats a cast
     * at every one of the several hundred reads above.
     *
     * @return array<string, mixed>
     */
    private function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private function rowsOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($value, is_array(...)));
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map($this->str(...), $value));
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function floatOrNull(float|string|null $value): ?float
    {
        return is_float($value) ? $value : null;
    }
}
