<?php

use App\Domain\Structure\LinkService;
use App\Events\LinkChanged;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use Illuminate\Support\Facades\Event;

/*
 * Regression: LinkService's unlink methods deleted pivot rows with a
 * query-builder delete(), which issues raw SQL and fires no model events. The
 * observers therefore never saw an unlink, so LinkChanged was never broadcast
 * and — the part that actually corrupts output — assessments.calc_version was
 * never bumped, leaving the cached calculation in place.
 *
 * The user-visible symptom is the bad one: remove a defence from an attack and
 * the totals do not move, because the cache still holds the result computed
 * while the link existed. A security tool quietly reporting a lower loss than
 * reality is the failure mode this whole project exists to avoid.
 */
it('bumps calc_version when an attack-defence link is removed, so the cached total cannot go stale', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    app(LinkService::class)->linkAttackToDefence($attack, $defence);

    $versionAfterLink = $assessment->fresh()->calc_version;

    app(LinkService::class)->unlinkAttackFromDefence($attack, $defence);

    expect($assessment->fresh()->calc_version)->toBeGreaterThan($versionAfterLink);
});

it('bumps calc_version when a risk-attack link is removed', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    app(LinkService::class)->linkRiskToAttack($risk, $attack);

    $versionAfterLink = $assessment->fresh()->calc_version;

    app(LinkService::class)->unlinkRiskFromAttack($risk, $attack);

    expect($assessment->fresh()->calc_version)->toBeGreaterThan($versionAfterLink);
});

it('broadcasts the removal so other editors see it, rather than only the creation', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    app(LinkService::class)->linkAttackToDefence($attack, $defence);

    Event::fake([LinkChanged::class]);

    app(LinkService::class)->unlinkAttackFromDefence($attack, $defence);

    Event::assertDispatched(LinkChanged::class);
});
