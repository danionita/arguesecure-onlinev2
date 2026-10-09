<?php

namespace App\Observers;

use App\Events\LinkChanged;
use App\Models\AttackDefence;
use App\Observers\Concerns\TriggersRecalculation;
use Illuminate\Support\Facades\Auth;

/**
 * PLAN.md Phase 6 item 4 — link changed. See NodeObserver's docblock for
 * why this lives as a pivot-model observer rather than inline in
 * LinkController (outside this package's ownership).
 *
 * Known gap, reported rather than worked around per this package's brief:
 * App\Domain\Structure\LinkService::unlinkAttackFromDefence() (outside this
 * package's ownership) removes a link via a query-builder `->delete()`
 * against the pivot table, not `$model->delete()` — Eloquent only fires
 * model events for the latter, so `deleted()` below never actually runs
 * through that path today. It is kept because it is the correct handler
 * for any *future* caller that deletes a retrieved AttackDefence instance,
 * and because doing anything else (parsing raw SQL, adding a DB::listen()
 * shim) would be working around a file this package does not own rather
 * than reporting it — see the W16 session's final report for the full note.
 */
class AttackDefenceObserver
{
    public function created(AttackDefence $attackDefence): void
    {
        event(LinkChanged::forAttackDefence($attackDefence, 'created', Auth::id(), Auth::user()?->name));
        $this->debounce($attackDefence);
    }

    public function updated(AttackDefence $attackDefence): void
    {
        event(LinkChanged::forAttackDefence($attackDefence, 'updated', Auth::id(), Auth::user()?->name));
        $this->debounce($attackDefence);
    }

    public function deleted(AttackDefence $attackDefence): void
    {
        event(LinkChanged::forAttackDefence($attackDefence, 'deleted', Auth::id(), Auth::user()?->name));
        $this->debounce($attackDefence);
    }

    /** calculation-engine.md §11.4 — see RiskAttackObserver::debounce()'s docblock; the same reasoning applies via the attack side here. */
    private function debounce(AttackDefence $attackDefence): void
    {
        $assessmentId = $attackDefence->attack?->assessment_id;
        if ($assessmentId !== null) {
            TriggersRecalculation::bumpCalcVersionAndDebounce($assessmentId);
        }
    }
}
