<?php

use App\Domain\Structure\LinkService;
use App\Domain\Structure\ReorderService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->service = new ReorderService;
    $this->links = new LinkService;
});

it('re-sequences node positions in the given order', function () {
    $assessment = Assessment::factory()->create();
    $riskA = Risk::factory()->create(['assessment_id' => $assessment->id, 'position' => 0]);
    $riskB = Risk::factory()->create(['assessment_id' => $assessment->id, 'position' => 1]);
    $riskC = Risk::factory()->create(['assessment_id' => $assessment->id, 'position' => 2]);

    $this->service->reorderNodes([$riskC->id, $riskA->id, $riskB->id]);

    expect(Node::withoutGlobalScopes()->find($riskC->id)->position)->toBe(0)
        ->and(Node::withoutGlobalScopes()->find($riskA->id)->position)->toBe(1)
        ->and(Node::withoutGlobalScopes()->find($riskB->id)->position)->toBe(2);
});

it('re-sequences attacks under a specific risk without disturbing the same attack under another risk', function () {
    $assessment = Assessment::factory()->create();
    $riskA = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $riskB = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack1 = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $attack2 = Attack::factory()->create(['assessment_id' => $assessment->id]);

    // Same two attacks under both risks, in the same initial order.
    $this->links->linkRiskToAttack($riskA, $attack1);
    $this->links->linkRiskToAttack($riskA, $attack2);
    $this->links->linkRiskToAttack($riskB, $attack1);
    $this->links->linkRiskToAttack($riskB, $attack2);

    $this->service->reorderAttacksUnderRisk($riskA->id, [$attack2->id, $attack1->id]);

    expect($riskA->fresh()->attacks->pluck('id')->all())->toBe([$attack2->id, $attack1->id])
        ->and($riskB->fresh()->attacks->pluck('id')->all())->toBe([$attack1->id, $attack2->id]);
});

it('re-sequences defences under a specific attack without disturbing the same defence under another attack', function () {
    $assessment = Assessment::factory()->create();
    $attackA = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $attackB = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence1 = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $defence2 = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $this->links->linkAttackToDefence($attackA, $defence1);
    $this->links->linkAttackToDefence($attackA, $defence2);
    $this->links->linkAttackToDefence($attackB, $defence1);
    $this->links->linkAttackToDefence($attackB, $defence2);

    $this->service->reorderDefencesUnderAttack($attackA->id, [$defence2->id, $defence1->id]);

    expect($attackA->fresh()->defences->pluck('id')->all())->toBe([$defence2->id, $defence1->id])
        ->and($attackB->fresh()->defences->pluck('id')->all())->toBe([$defence1->id, $defence2->id]);
});

/**
 * schema.md §4: re-sequencing runs inside a transaction. Exercised here by
 * wrapping the call in an outer transaction that then rolls back — the
 * re-sequence must roll back with it rather than partially surviving.
 */
it('is transactional: an outer rollback discards a reorderNodes call entirely', function () {
    $assessment = Assessment::factory()->create();
    $riskA = Risk::factory()->create(['assessment_id' => $assessment->id, 'position' => 0]);
    $riskB = Risk::factory()->create(['assessment_id' => $assessment->id, 'position' => 1]);

    try {
        DB::transaction(function () use ($riskA, $riskB) {
            $this->service->reorderNodes([$riskB->id, $riskA->id]);
            throw new RuntimeException('force rollback for this assertion');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Node::withoutGlobalScopes()->find($riskA->id)->position)->toBe(0)
        ->and(Node::withoutGlobalScopes()->find($riskB->id)->position)->toBe(1);
});
