<?php

namespace App\Http\Controllers;

use App\Domain\Dashboard\DashboardData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard's own pages (ui.md §8, W73 item 20, D33).
 *
 * Today that is one page: *Assessments shared with me*. It lives here rather
 * than beside the assessment list because it is the dashboard tab's "see
 * all", and it is built from the same `DashboardData` the tab renders — one
 * definition of "shared with me" read twice, rather than two queries that
 * would eventually disagree about whether a removed collaborator or a
 * soft-deleted root still counts.
 *
 * Until W73 the sidebar's *Shared with me* group header and the dashboard
 * section both pointed at `/assessments`, a list that does not distinguish a
 * shared row from an owned one — so the one collection whose defining
 * property is *whose it is* was reachable only as part of a list that does
 * not say. It is a real collection with its own emptiness, and it now has its
 * own page.
 *
 * The scoping and the AssessmentPolicy pass both live in
 * `DashboardData::sharedWithMePage()`, where the ordering between them is
 * documented — business logic belongs in `app/Domain` and this one is
 * load-bearing enough that a controller is the wrong place to be able to get
 * it the wrong way round.
 *
 * `/dashboard` itself is still rendered by AssessmentLifecycleController,
 * which W73 does not own. Moving it would be a route rewrite for no
 * behavioural gain; `DashboardData` is the seam either way.
 */
class DashboardController extends Controller
{
    public function shared(Request $request, DashboardData $dashboard): Response
    {
        return Inertia::render('Assessment/Shared', [
            'assessments' => $dashboard->sharedWithMePage($request->user()),
        ]);
    }
}
