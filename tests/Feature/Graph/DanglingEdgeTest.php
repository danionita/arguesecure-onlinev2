<?php

use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;

/*
 * Regression: a soft delete deliberately leaves edge rows in place so that an
 * undo can restore the node with its links intact, and DeleteNodeApplier
 * documents those dangling edges as "invisible to the graph loader until
 * restored". They were not invisible. The loader selects edge rows by their
 * near side only, so the far side could point at a soft-deleted node that was
 * absent from the id->uuid map; the lookup hit a missing key and threw.
 *
 * The blast radius is what makes this severe rather than cosmetic. It is not
 * the deleted branch that breaks — the whole assessment fails to load, so the
 * tree, the report and every calculation 500 together. Reaching it takes one
 * ordinary click: delete an attack that sits under a risk.
 */
it('loads an assessment after a linked attack is soft-deleted, instead of throwing', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    app(LinkService::class)->linkRiskToAttack($risk, $attack);

    $attack->delete();

    $graph = app(AssessmentGraphLoader::class)->load($assessment->fresh());

    expect($graph->riskAttackLinks)->toBe([]);
});

it('drops attack-defence edges whose defence has been soft-deleted', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    app(LinkService::class)->linkAttackToDefence($attack, $defence);

    $defence->delete();

    $graph = app(AssessmentGraphLoader::class)->load($assessment->fresh());

    expect($graph->attackDefenceLinks)->toBe([]);
});

/*
 * The direction that matters for the numbers: a deleted defence must stop
 * reducing the loss. If the edge survived into the graph while the defence
 * itself did not, the engine would keep applying a control nobody has — the
 * tool understating risk, which is the one failure mode the whole project
 * exists to avoid.
 */
it('stops applying a defence to the total once that defence is deleted', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'impact_mode' => 'quantitative', 'impact_amount' => 100000]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 1.0]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id, 'status' => 'implemented']);

    app(LinkService::class)->linkRiskToAttack($risk, $attack);
    $link = app(LinkService::class)->linkAttackToDefence($attack, $defence);
    $link->strength_mode = 'quantitative';
    $link->strength_fraction = 0.8;
    $link->save();

    $calculator = app(RiskCalculator::class);
    $loader = app(AssessmentGraphLoader::class);

    $before = $calculator->compute($loader->load($assessment->fresh()))->current->total;
    expect($before)->toEqualWithDelta(20000.0, 1e-9);

    $defence->delete();

    $after = $calculator->compute($loader->load($assessment->fresh()))->current->total;
    expect($after)->toEqualWithDelta(100000.0, 1e-9);
});
