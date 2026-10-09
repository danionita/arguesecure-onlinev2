<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/*
 * W43. CLAUDE.md: "Eager-load everything... a whole assessment must load in
 * four queries", and the plan's own acceptance line for this package: the
 * budget holds with 200 tagged nodes.
 *
 * A per-node tag lookup is the easiest possible way to reintroduce the N+1
 * that CLAUDE.md names as the legacy tree render's defining defect, and it
 * would be invisible: the page would render correctly, every label in place,
 * and simply cost one query per node. So the assertion is on the query
 * *count*, and it is made against two assessments of very different sizes
 * rather than against a fixed number, which is the same shape
 * AssessmentShowTest and ReportShowQueryBudgetTest already use.
 */

function tagEveryNode(Assessment $assessment, int $tagCount = 3): void
{
    $tags = [];
    foreach (range(1, $tagCount) as $i) {
        $tag = new Tag(['name' => "Label {$i}", 'slug' => "label-{$i}"]);
        $tag->assessment_id = $assessment->id;
        $tag->save();
        $tags[] = $tag->id;
    }

    $rows = [];
    foreach ($assessment->nodes()->pluck('id') as $nodeId) {
        foreach ($tags as $tagId) {
            $rows[] = ['node_id' => $nodeId, 'tag_id' => $tagId];
        }
    }

    DB::table('node_tag')->insert($rows);
}

test('the editor query count does not grow with the number of labelled nodes', function () {
    $build = function (int $riskCount): Assessment {
        $assessment = Assessment::factory()->create();
        foreach (range(1, $riskCount) as $i) {
            $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
            $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
            $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
            $risk->attacks()->attach($attack->id, ['position' => 0]);
            $attack->defences()->attach($defence->id, ['position' => 0]);
        }

        tagEveryNode($assessment);

        return $assessment;
    };

    $small = $build(1);
    // 20 risks + 20 attacks + 20 defences + a root, three labels each: 183
    // node_tag rows, which is the scale the plan asks about without making
    // the test itself slow enough that someone deletes it.
    $large = $build(20);

    $countFor = function (Assessment $assessment): int {
        // Warm up first: InstanceSettings' lazy first-row insert is a
        // one-time cost unrelated to graph size, and would otherwise be
        // charged to whichever assessment is measured first.
        $this->actingAs($assessment->owner)->get(route('assessments.show', $assessment));

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($assessment->owner)->get(route('assessments.show', $assessment));

        DB::flushQueryLog();

        return $queries;
    };

    expect($countFor($large))->toBe($countFor($small));
});

test('the assessment list costs the same whether or not the roots are labelled', function () {
    $plain = Assessment::factory()->create();
    $owner = $plain->owner;

    $labelled = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    tagEveryNode($labelled);

    // The list eager-loads `rootNode.tags`, so adding labels must cost the
    // same two constant queries no matter how many rows carry them — a
    // lazy-loaded `rootNode->tags` inside the map() would be one query per
    // assessment and would only be noticed by someone with fifty of them.
    $this->actingAs($owner)->get(route('assessments.index'));

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($owner)->get(route('assessments.index'))
        ->assertInertia(fn ($page) => $page->has('assessments', 2));

    $before = $queries;

    foreach (range(1, 6) as $i) {
        $extra = Assessment::factory()->create(['owner_user_id' => $owner->id]);
        tagEveryNode($extra);
    }

    $this->actingAs($owner)->get(route('assessments.index'));

    $queries = 0;
    $this->actingAs($owner)->get(route('assessments.index'))
        ->assertInertia(fn ($page) => $page->has('assessments', 8));

    expect($queries)->toBe($before);
});
