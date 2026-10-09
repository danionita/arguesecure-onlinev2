<?php

namespace App\Domain\Assistant\Suggestions;

use App\Domain\Assistant\Client;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Node;
use App\Models\Risk;
use Illuminate\Support\Facades\File;

/**
 * Job B: propose children for a node (PLAN-2.3-ai.md W52).
 *
 * ## What it is allowed to see, and why that is a short list
 *
 * The target of assessment, the parent node, and the titles of what is already
 * recorded under that parent. Not the whole assessment, and specifically not
 * its numbers: impacts, likelihoods and strengths are the user's own
 * judgement, and a model that has seen them will echo them back as
 * corroboration. The prompt forbids inventing figures; not sending any is the
 * version of that rule which does not depend on the model obeying.
 *
 * ## Spend context, ration output
 *
 * The spike measured prompt tokens at roughly a tenth the cost of output
 * tokens on a CPU. So the parent's full description is worth sending and the
 * number of suggestions is the dial that actually decides how long a user
 * waits — five suggestions cost five suggestions' worth of generation, and
 * nothing else here comes close.
 */
class Suggester
{
    /** What a given parent type has as children, per the domain model. */
    private const CHILD_OF = [
        'root' => 'risk',
        'risk' => 'attack',
        'attack' => 'defence',
    ];

    /** One title plus a 500-character description, as JSON, with room to close it. */
    private const SINGLE_SUGGESTION_FLOOR = 192;

    private const LABELS = [
        'risk' => ['singular' => 'risk', 'plural' => 'risks'],
        'attack' => ['singular' => 'attack', 'plural' => 'attacks'],
        'defence' => ['singular' => 'defence', 'plural' => 'defences'],
    ];

    public function __construct(
        private readonly Client $client,
        private readonly SuggestionParser $parser,
    ) {}

    public static function childTypeFor(string $parentType): ?string
    {
        return self::CHILD_OF[$parentType] ?? null;
    }

    /**
     * The generation budget for `$count` suggestions (2.5.1).
     *
     * The configured budget is sized for the full list of five. The new-node
     * form asks for one, and giving one suggestion five suggestions' budget
     * lets a model that rambles run for five suggestions' time — the budget
     * is a ceiling on latency, not only on length. Scaled linearly, with a
     * floor that still fits one title and a 500-character description.
     */
    public static function budgetFor(int $count): int
    {
        $full = (int) config('assistant.max_tokens.suggestions');

        return min($full, max(self::SINGLE_SUGGESTION_FLOOR, (int) ceil($full * $count / 5)));
    }

    /**
     * @param  (callable(string): void)|null  $onToken  W58: the progress and
     *                                                  cancellation observer,
     *                                                  passed straight through
     *                                                  to the client. Optional
     *                                                  so a console or test
     *                                                  caller need not care.
     * @return list<array{title: string, description: string}>
     */
    public function suggest(Assessment $assessment, Node $parent, int $count, ?callable $onToken = null): array
    {
        $childType = self::childTypeFor($parent->type);

        if ($childType === null) {
            // A defence has no children in this model. The route refuses this
            // before reaching here; the guard stays so a future caller cannot
            // reach a prompt that asks for something the schema has no place
            // to put.
            return [];
        }

        $reply = $this->client->complete(
            $this->buildPrompt($assessment, $parent, $childType, $count),
            self::budgetFor($count),
            $onToken,
        );

        return $this->parser->parse($reply, $count);
    }

    private function buildPrompt(
        Assessment $assessment,
        Node $parent,
        string $childType,
        int $count,
    ): string {
        $template = File::get(resource_path('prompts/assistant/suggest.md'));
        $root = $assessment->rootNode;

        return str_replace(
            [
                '{{KIND_SINGULAR}}',
                '{{KIND_PLURAL}}',
                '{{COUNT}}',
                '{{TOS_TITLE}}',
                '{{TOS_DESCRIPTION}}',
                '{{PARENT_KIND}}',
                '{{PARENT_TITLE}}',
                '{{PARENT_DESCRIPTION}}',
                '{{EXISTING}}',
            ],
            [
                self::LABELS[$childType]['singular'],
                self::LABELS[$childType]['plural'],
                (string) $count,
                $root?->title ?: 'Untitled assessment',
                $root?->description ?: '(no description)',
                ucfirst($parent->type === 'root' ? 'target of assessment' : $parent->type),
                $parent->title ?: '(untitled)',
                $parent->description ?: '(no description)',
                $this->existing($assessment, $parent),
            ],
            $template,
        );
    }

    /**
     * Titles already recorded under this parent, so the model does not propose
     * them again.
     *
     * Titles only, deliberately: enough to avoid duplication, and it keeps the
     * prompt short in the place that grows without limit as an assessment
     * fills up.
     */
    private function existing(Assessment $assessment, Node $parent): string
    {
        // Narrowed by class rather than by the `type` string: `attacks()` and
        // `defences()` live on Risk and Attack, not on Node, and single-table
        // inheritance means the same row hydrates as whichever subclass its
        // `type` names.
        $titles = match (true) {
            $parent instanceof Risk => $parent->attacks()->pluck('title'),
            $parent instanceof Attack => $parent->defences()->pluck('title'),
            $parent->type === 'root' => Node::query()
                ->where('assessment_id', $assessment->id)
                ->where('type', 'risk')
                ->pluck('title'),
            default => collect(),
        };

        $lines = $titles
            ->filter(fn ($title) => is_string($title) && trim($title) !== '')
            ->map(fn ($title) => '- '.$title)
            ->all();

        return $lines === [] ? '(nothing yet)' : implode("\n", $lines);
    }
}
