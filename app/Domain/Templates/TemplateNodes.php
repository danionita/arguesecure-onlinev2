<?php

namespace App\Domain\Templates;

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use Illuminate\Support\Collection;

/**
 * Reading a template's nodes so they can be added to another assessment
 * (2.5.1, "add from template").
 *
 * The picker copies a node's words, never the node: `title`, `description`
 * and, for a defence, whether it is a transfer. The copy goes through the
 * ordinary `addChild` write path in the editor, so it gets a fresh uuid, is
 * validated like a typed node, and lands in both history logs. Nothing here
 * writes, and nothing links the two assessments — editing the template later
 * changes nothing that was taken from it, which is the same promise
 * AssessmentCloner makes for a whole assessment.
 *
 * Values — impact, likelihood, strength — are deliberately not carried. They
 * are the template's calibration of *its* example, and the bundled standards
 * templates hold qualitative placeholders; a figure copied across would sit
 * in the new assessment looking like somebody's judgement.
 */
class TemplateNodes
{
    public const TYPES = ['risk', 'attack', 'defence'];

    /**
     * Every template the caller may use, with how many nodes of each type it
     * holds, so the picker can say "12 attacks" before anyone opens it.
     *
     * @param  Collection<int, Assessment>  $templates
     * @return list<array{uuid: string, title: string, counts: array<string, int>}>
     */
    public function summaries(Collection $templates): array
    {
        $counts = Node::query()
            ->whereIn('assessment_id', $templates->pluck('id'))
            ->whereIn('type', self::TYPES)
            ->selectRaw('assessment_id, type, COUNT(*) as aggregate')
            ->groupBy('assessment_id', 'type')
            ->get()
            ->groupBy('assessment_id');

        return $templates
            ->map(function (Assessment $template) use ($counts): array {
                $byType = array_fill_keys(self::TYPES, 0);
                foreach ($counts->get($template->id, collect()) as $row) {
                    $byType[(string) $row->getAttribute('type')] = (int) $row->getAttribute('aggregate');
                }

                return [
                    'uuid' => $template->uuid,
                    'title' => (string) $template->rootNode?->title,
                    'counts' => $byType,
                ];
            })
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * The template's nodes of one type, in natural order ("A.5.2" before
     * "A.5.10"), each with the titles of the parents it sits under in the
     * template — the context that tells two similarly-named controls apart.
     *
     * Three queries whatever the size: the nodes, and one eager load of the
     * parent relation for the type that has one.
     *
     * @return list<array{uuid: string, title: string, description: ?string, is_transfer: bool, parents: list<string>}>
     */
    public function ofType(Assessment $template, string $type): array
    {
        $query = match ($type) {
            'risk' => Risk::query(),
            'attack' => Attack::query()->with('risks:id,title'),
            'defence' => Defence::query()->with('attacks:id,title'),
            default => throw new \InvalidArgumentException("Unknown node type '{$type}'."),
        };

        return $query
            ->where('assessment_id', $template->id)
            ->get()
            ->map(fn (Node $node): array => [
                'uuid' => $node->uuid,
                'title' => $node->title,
                'description' => $node->description,
                'is_transfer' => (bool) $node->getAttribute('is_transfer'),
                'parents' => $this->parentTitles($node),
            ])
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function parentTitles(Node $node): array
    {
        $parents = match (true) {
            $node instanceof Attack => $node->risks,
            $node instanceof Defence => $node->attacks,
            default => collect(),
        };

        return $parents->pluck('title')->map(fn ($t): string => (string) $t)->values()->all();
    }
}
