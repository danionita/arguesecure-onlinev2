<?php

use App\Domain\Structure\LinkService;
use App\Domain\Structure\LinkValidationException;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;

beforeEach(function () {
    $this->service = new LinkService;
});

it('links a risk to an attack, defaulting position to the next slot', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attackA = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $attackB = Attack::factory()->create(['assessment_id' => $assessment->id]);

    $linkA = $this->service->linkRiskToAttack($risk, $attackA);
    $linkB = $this->service->linkRiskToAttack($risk, $attackB);

    expect($linkA->position)->toBe(0)
        ->and($linkB->position)->toBe(1)
        ->and($risk->fresh()->attacks->pluck('id')->all())->toBe([$attackA->id, $attackB->id]);
});

it('rejects linking a risk and an attack from different assessments', function () {
    $assessmentA = Assessment::factory()->create();
    $assessmentB = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessmentA->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessmentB->id]);

    expect(fn () => $this->service->linkRiskToAttack($risk, $attack))
        ->toThrow(LinkValidationException::class);
});

it('rejects linking an attack and a defence from different assessments', function () {
    $assessmentA = Assessment::factory()->create();
    $assessmentB = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessmentA->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessmentB->id]);

    expect(fn () => $this->service->linkAttackToDefence($attack, $defence))
        ->toThrow(LinkValidationException::class);
});

it('unlinks a risk from an attack', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $this->service->linkRiskToAttack($risk, $attack);

    $this->service->unlinkRiskFromAttack($risk, $attack);

    expect($risk->fresh()->attacks)->toHaveCount(0);
});

it('defaults an ordinary defence link to null reduction_target (combined loss)', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $link = $this->service->linkAttackToDefence($attack, $defence);

    expect($link->reduction_target)->toBeNull();
});

it('defaults a transfer defence link to impact reduction_target', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->transfer()->create(['assessment_id' => $assessment->id]);

    $link = $this->service->linkAttackToDefence($attack, $defence);

    expect($link->reduction_target)->toBe('impact');
});

it('lets a caller override reduction_target explicitly', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $link = $this->service->linkAttackToDefence($attack, $defence, reductionTarget: 'likelihood');

    expect($link->reduction_target)->toBe('likelihood');
});

it('unlinks an attack from a defence', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $this->service->linkAttackToDefence($attack, $defence);

    $this->service->unlinkAttackFromDefence($attack, $defence);

    expect($attack->fresh()->defences)->toHaveCount(0);
});
