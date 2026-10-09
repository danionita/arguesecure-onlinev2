<?php

namespace App\Observers;

use App\Events\LinkChanged;
use App\Models\RiskAttack;
use App\Observers\Concerns\TriggersRecalculation;
use Illuminate\Support\Facades\Auth;

/**
 * PLAN.md Phase 6 item 4 — link changed. See NodeObserver's docblock for
 * why this lives as a pivot-model observer rather than inline in
 * LinkController (outside this package's ownership).
 *
 * Same known gap as AttackDefenceObserver: LinkService::unlinkRiskFromAttack()
 * deletes via the query builder, not `$model->delete()`, so `deleted()`
 * below does not fire through today's only caller — see that observer's
 * docblock and the W16 session's final report.
 */
class RiskAttackObserver
{
    public function created(RiskAttack $riskAttack): void
    {
        event(LinkChanged::forRiskAttack($riskAttack, 'created', Auth::id(), Auth::user()?->name));
        $this->debounce($riskAttack);
    }

    public function updated(RiskAttack $riskAttack): void
    {
        event(LinkChanged::forRiskAttack($riskAttack, 'updated', Auth::id(), Auth::user()?->name));
        $this->debounce($riskAttack);
    }

    public function deleted(RiskAttack $riskAttack): void
    {
        event(LinkChanged::forRiskAttack($riskAttack, 'deleted', Auth::id(), Auth::user()?->name));
        $this->debounce($riskAttack);
    }

    /**
     * calculation-engine.md §11.4: the pivot carries no assessment_id of
     * its own (schema.md §3.5 — only the two node foreign keys), so it is
     * resolved through the risk side of the edge, which shares an
     * assessment with the attack side by LinkService's own same-assessment
     * invariant.
     */
    private function debounce(RiskAttack $riskAttack): void
    {
        $assessmentId = $riskAttack->risk?->assessment_id;
        if ($assessmentId !== null) {
            TriggersRecalculation::bumpCalcVersionAndDebounce($assessmentId);
        }
    }
}
