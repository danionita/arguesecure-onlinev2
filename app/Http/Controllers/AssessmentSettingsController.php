<?php

namespace App\Http\Controllers;

use App\Domain\History\RevisionRecorder;
use App\Domain\Scales\ScaleDescriber;
use App\Http\Requests\Assessment\UpdateAssessmentSettingsRequest;
use App\Models\Assessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One settings screen for an assessment (W44, item 12; ui.md §8).
 *
 * These settings were scattered or absent: the amortisation period was on the
 * assessment row with no control anywhere, the currency likewise, the title
 * and description were editable only by clicking the root node's title in the
 * tree, and a risk tolerance did not exist at all. Gathering them is the
 * point — a facilitator setting up an assessment should not have to know that
 * "how long do we spread a capital cost over" lives in a different place from
 * "what currency are we in".
 */
class AssessmentSettingsController extends Controller
{
    public function __construct(private readonly RevisionRecorder $revisions) {}

    public function edit(Request $request, Assessment $assessment): Response
    {
        abort_unless((bool) $request->user()?->can('manage', $assessment), 403);

        return Inertia::render('Assessment/Settings', [
            'assessment' => [
                'uuid' => $assessment->uuid,
                // Title and description are deliberately absent (W86, item 9):
                // they live on the root node and are edited there. Carrying
                // them here again is the two-editors-for-one-field mistake
                // this package removed.
                'currency' => $assessment->currency,
                'amortization_years' => (int) $assessment->amortization_years,
                'risk_tolerance' => $assessment->risk_tolerance,
            ],
            'currencies' => UpdateAssessmentSettingsRequest::currencies(),
            'maxAmortizationYears' => (int) config('arguesecure.max_amortization_years', 10),
            /*
             * Editable here since W87 (D46), where it was a read-only summary
             * linking to a screen of its own.
             *
             * The screen and its route are deleted rather than unlinked: a
             * second editor that still answers its URL is how a consolidation
             * ends up with two of the thing it was consolidating. The bands
             * are described by `ScaleDescriber`, which is also what the admin
             * Defaults tab sends, so the two screens cannot be handed
             * different shapes and drift apart.
             *
             * `code` is in the payload and is drawn nowhere (D44). It is the
             * round-trip token that freezes a code across a rename; an id
             * cannot do that job, because a custom scale is cloned per
             * assessment and its level ids do not exist until the clone runs.
             */
            'scales' => ScaleDescriber::forAssessment($assessment),
            'topBandMultiplier' => ScaleDescriber::topBandMultiplier(),
            /*
             * The message a scale save or reset leaves behind.
             *
             * Read here and passed as a prop, rather than relied on as a
             * shared one: `HandleInertiaRequests::share()` does not share the
             * session's flash bag, so a `->with('success', …)` reaches the
             * server-side session and stops there. That matters for these two
             * writes in particular — a calibration that removes a band reports
             * how many stored values it emptied, and someone spent a workshop
             * arriving at them.
             */
            'flashSuccess' => $request->session()->get('success'),
        ]);
    }

    public function update(UpdateAssessmentSettingsRequest $request, Assessment $assessment): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $assessment, $request): void {
            /*
             * Snapshotted before `fill()`, because the recorder runs after the
             * save and Eloquent has resynced the originals by then.
             *
             * A node's history is written by NodeObserver, which fires inside
             * the save and can read `getOriginal()`. There is no equivalent
             * for the assessment row — `Assessment` has no observer, and
             * adding one to record three columns from one screen would put a
             * global hook in the path of every assessment write for the
             * benefit of a single controller. So this one caller passes the
             * before-state explicitly, and W60's coverage test is what stops a
             * second settings writer appearing without one.
             */
            $before = $assessment->only(['currency', 'amortization_years', 'risk_tolerance']);

            $assessment->fill([
                'currency' => $validated['currency'],
                'amortization_years' => $validated['amortization_years'],
                'risk_tolerance' => $validated['risk_tolerance'] ?? null,
            ]);

            /*
             * The amortisation period is the only setting here that changes a
             * computed figure: DefenceRanker spreads capex over it, so every
             * annual cost, cost-benefit ratio and ROSI moves with it. The
             * calculation cache keys on `calc_version`
             * (CalculationCache), so without this bump the report would keep
             * serving figures amortised over the old period until some
             * unrelated edit happened to invalidate them.
             *
             * Currency and risk tolerance deliberately do **not** bump it.
             * Neither reaches RiskCalculator — they change how a number is
             * labelled and what line is drawn beside it, not the number — and
             * invalidating the cache for them would throw away a correct
             * result to recompute the identical one.
             */
            if ($assessment->isDirty('amortization_years')) {
                $assessment->calc_version = $assessment->calc_version + 1;
            }

            $assessment->save();

            /*
             * W60. The amortisation period is the one setting on this screen
             * that moves a figure, and until now neither history log recorded
             * it — so a reconstruction restored the nodes and left the divisor
             * at today's value (schema.md §3.9).
             *
             * Until W86 a settings save that also renamed the assessment wrote
             * **two** revisions: this one, and the ordinary root-node revision
             * the observer wrote for the title. That is gone now — title and
             * description no longer reach this controller at all (item 9) —
             * but the reasoning is left here because a settings-scoped
             * revision folding into the tree's own would still be wrong if
             * some other field ever moved the other way: they are two
             * different subjects, and folding them into one entry would need
             * an `operation_id`, which means an `assessment_operations` row,
             * which means putting a settings edit on the undo stack. It is
             * not a structural change and does not belong there (schema.md
             * §3.10).
             */
            $this->revisions->recordSettingsUpdate($assessment, $before, $request->user());

            // Title and description used to be written to the root node
            // here (W44) — removed in W86 (item 9). They live on the root
            // node and are edited there; this screen writing them too was
            // the second editor for one field CLAUDE.md warns is how they
            // diverge, and `UpdateAssessmentSettingsRequest` now rejects
            // both fields outright so this write path cannot come back
            // through a scripted request either.
        });

        return redirect()
            ->route('assessments.settings.edit', $assessment->uuid)
            ->with('status', 'Settings saved.');
    }
}
