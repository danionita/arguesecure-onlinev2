<?php

namespace App\Http\Controllers;

use App\Domain\Dashboard\AssessmentListRows;
use App\Domain\Dashboard\DashboardData;
use App\Domain\Retention\AssessmentEraser;
use App\Domain\Retention\DeletedAssessments;
use App\Domain\Retention\TrashPurger;
use App\Domain\Scales\ScaleSets;
use App\Domain\Structure\AssessmentCloner;
use App\Domain\Structure\AssessmentCreator;
use App\Http\Requests\Assessment\StoreAssessmentRequest;
use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Listing, creating, deleting, restoring and erasing assessments — the
 * entry point the application shipped without. Kept separate from
 * AssessmentController, which owns the tree editor's read route and belongs
 * to W12: this is a different concern (the lifecycle around an assessment
 * rather than the rendering of one) and a different package.
 *
 * **The index deliberately does not list public assessments.** `is_public`
 * grants read to anyone holding the link (AssessmentPolicy::view(), and
 * the e2e run confirms an anonymous visitor can open one), but listing
 * every public assessment here would turn a personal index into a
 * directory of other people's work and quietly make "public" mean
 * "advertised". Owned only (shared ones have their own page); a public
 * assessment is reached by its link, exactly as its owner intended.
 *
 * Titles come from the root node — `assessments` has no title column
 * (schema.md) — so the list eager-loads `rootNode` rather than issuing one
 * query per row, which is the N+1 the legacy tree render was built out of.
 */
class AssessmentLifecycleController extends Controller
{
    /**
     * The arrival page (ui.md §8, W42 item 17). Four sections assembled by
     * `DashboardData`, which is where W46 and W47 add theirs.
     */
    public function dashboard(Request $request, DashboardData $dashboard): Response
    {
        return Inertia::render('Dashboard', $dashboard->for($request->user()));
    }

    /**
     * Also carries the *Deleted* tab (W113): small by construction, since the
     * purge empties it after `trash_retention_days`, so it rides along rather
     * than costing a route of its own. `?show=deleted` opens on it.
     */
    public function index(Request $request, AssessmentListRows $rows, DeletedAssessments $deleted): Response
    {
        $user = $request->user();

        // *My assessments*: owned only, since 2.5.1. Through 2.5 this also
        // listed every assessment shared with the caller, under a heading the
        // sidebar called "My assessments", while "Shared with me" listed the
        // shared ones again on a page of its own.
        $assessments = Assessment::query()
            ->with(AssessmentListRows::WITH)
            ->where('owner_user_id', $user->id)
            ->latest('id')
            ->get();

        return Inertia::render('Assessment/Index', [
            'assessments' => $rows->for($assessments, $user),
            'deleted' => $deleted->for($user),
            'retentionDays' => TrashPurger::retentionDays(),
            'scaleOptions' => ScaleSets::options(),
            'show' => $request->query('show') === 'deleted' ? 'deleted' : 'active',
        ]);
    }

    public function store(StoreAssessmentRequest $request, AssessmentCreator $creator): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $assessment = $creator->create(
                $request->user(),
                $validated['title'],
                $validated['description'] ?? null,
                $validated['scale_set_key'] ?? null,
            );
        } catch (\InvalidArgumentException) {
            // The scale was deleted between the form opening and being sent.
            return back()->withErrors(['scale_set_key' => 'That scale no longer exists. Pick another.'])->withInput();
        }

        return redirect()->route('assessments.show', $assessment);
    }

    /**
     * Duplicate (W42 item 20): a private copy, owned by whoever asked for it.
     *
     * Gated on `view`, the same ability `templates.use` uses for the same
     * reason — the clone touches nothing in the source, so anyone who can
     * read a thing can build their own copy of it. It is *not* gated on
     * `manage`: needing manager rights to take a copy of something you are
     * allowed to read would be a rule with no property behind it.
     *
     * `AssessmentCloner` does the work unmodified. Read its docblock before
     * touching this: it has broken twice on silently mass-assigned identity
     * columns, and `nodes.root_marker` is a generated column that MariaDB
     * rejects an INSERT for (CLAUDE.md).
     *
     * The copy is renamed on the way out. `cloneAssessment` reproduces the
     * root's title verbatim, which is right for a template — you name the new
     * assessment yourself — and wrong here: two rows reading "Payment
     * platform" in the list, and two more in the sidebar, with nothing to
     * tell them apart. The e2e template scenario already had to work around
     * exactly this by renaming the copy by hand.
     */
    public function duplicate(Request $request, Assessment $assessment, AssessmentCloner $cloner): RedirectResponse
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }

        $user = $request->user();
        $clone = $cloner->cloneAssessment($assessment, $user);

        $root = $clone->rootNode;
        if ($root !== null) {
            $root->title = Str::limit(
                (string) ($assessment->title ?? 'Untitled assessment'),
                // 255 is the column, and " (copy)" has to fit inside it.
                248,
                ''
            ).' (copy)';
            $root->updated_by = $user->id;
            $root->save();
        }

        // Back to the list rather than into the editor. Duplicating is a
        // list-management action — the user is looking at their assessments
        // and wants to see the new one appear among them. `templates.use`
        // lands in the editor because starting work is the point there.
        return redirect()->route('assessments.index');
    }

    public function destroy(Request $request, Assessment $assessment): RedirectResponse
    {
        // A template is deleted on the Templates page (W141), which erases it;
        // soft-deleting it here would hide it from the library and leave it in a
        // trash it cannot be restored from.
        abort_if($assessment->is_template, 404);

        if (Gate::forUser($request->user())->denies('delete', $assessment)) {
            abort(403);
        }

        // Soft delete: the nodes stay, so a restore is possible and an
        // audit trail survives. Deleting the assessment is what removes it
        // from every list, since every query scopes through it. It stays
        // under *Deleted* until restored, erased, or purged (W113).
        $assessment->delete();

        return redirect()->route('assessments.index');
    }

    /**
     * Back from *Deleted* (W113). The `delete` ability, the one that put it
     * there: whoever could delete it can take the decision back.
     *
     * The route binds trashed rows too (`withTrashed()`), so a live assessment
     * reaches here and is answered 404 — there is nothing to restore — after
     * the policy, so the answer says nothing to someone without the ability.
     */
    public function restore(Request $request, Assessment $assessment): RedirectResponse
    {
        if (Gate::forUser($request->user())->denies('delete', $assessment)) {
            abort(403);
        }

        if (! $assessment->trashed()) {
            abort(404);
        }

        $assessment->restore();

        return redirect()->route('assessments.show', $assessment);
    }

    /**
     * *Erase permanently* (W113): `forceDelete`, owner and admin only
     * (security.md §3.1, schema.md §3.7) — a manager can delete and restore
     * but cannot destroy the owner's work. Only from *Deleted*: erasing is two
     * deliberate steps, never one click on a live assessment.
     */
    public function erase(Request $request, Assessment $assessment, AssessmentEraser $eraser): RedirectResponse
    {
        if (Gate::forUser($request->user())->denies('forceDelete', $assessment)) {
            abort(403);
        }

        if (! $assessment->trashed()) {
            abort(404);
        }

        // False means a restore got there first.
        if (! $eraser->erase($assessment)) {
            abort(404);
        }

        return redirect()->route('assessments.index', ['show' => 'deleted']);
    }
}
