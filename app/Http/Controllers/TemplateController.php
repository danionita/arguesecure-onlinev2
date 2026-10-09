<?php

namespace App\Http\Controllers;

use App\Domain\Structure\AssessmentCloner;
use App\Domain\Templates\TemplateNodes;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Templates (schema.md §3.14, PLAN.md Phase 8 item 4): mark an assessment
 * as a template, and create a new assessment from one. Creation reuses
 * Structure\AssessmentCloner::cloneAssessment() unmodified (W6) — it
 * already produces exactly the required behaviour, a genuine copy that
 * remaps every uuid, so later edits to the template can never propagate
 * into an assessment already created from it (proved in
 * tests/Feature/Catalog/TemplateTest.php — see that file's own note on
 * the AssessmentCloner defect this package found and does not own).
 *
 * Since 2.5.1: marking/unmarking a template is an admin's decision
 * (`saveAsTemplate`), and every signed-in account can list the templates
 * and build its own private copy of one (`useAsTemplate`). The template
 * library is instance-wide reference material, like the catalogue.
 *
 * No FormRequest class here — this package owns only this controller, not
 * app/Http/Requests/**, matching SnapshotController's own documented
 * reason for doing authorization and validation inline.
 */
class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Every template, for every signed-in account (2.5.1). Filtering on
        // `view` — as this did through 2.5 — hid the bundled templates from
        // everyone but the admin who owned them, because they are not public.
        $templates = Assessment::query()
            ->where('is_template', true)
            ->with(['rootNode:id,assessment_id,title,description', 'owner:id,name'])
            ->get()
            ->filter(fn (Assessment $assessment): bool => Gate::forUser($user)->allows('useAsTemplate', $assessment))
            ->map(fn (Assessment $assessment): array => $this->templateSummary($assessment))
            ->values();

        return Inertia::render('Catalog/Templates', [
            'templates' => $templates,
        ]);
    }

    /**
     * The template list as the editor's "add from template" picker needs it
     * (2.5.1): the same audience as index(), with node counts per type.
     */
    public function options(Request $request, TemplateNodes $nodes): JsonResponse
    {
        $user = $request->user();

        $templates = Assessment::query()
            ->where('is_template', true)
            ->with('rootNode:id,assessment_id,title')
            ->get()
            ->filter(fn (Assessment $assessment): bool => Gate::forUser($user)->allows('useAsTemplate', $assessment))
            ->values();

        return response()->json(['templates' => $nodes->summaries($templates)]);
    }

    /**
     * One template's nodes of one type, to copy into another assessment.
     *
     * `useAsTemplate`, like store(): reading a template's words is what using
     * it means, and anyone who can build a whole assessment from it can take
     * a single node. A non-template is a 404 for the same reason store()
     * gives — otherwise this reads the titles of any assessment by uuid.
     */
    public function nodes(Request $request, Assessment $assessment, TemplateNodes $nodes): JsonResponse
    {
        abort_unless($assessment->is_template, 404);

        if (Gate::forUser($request->user())->denies('useAsTemplate', $assessment)) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(TemplateNodes::TYPES)],
        ]);

        return response()->json(['nodes' => $nodes->ofType($assessment, $validated['type'])]);
    }

    /**
     * What the Share dialog needs to render its template panel (W45).
     *
     * `view`, not `saveAsTemplate`: this reports state, and someone who can
     * read the assessment can already see it is a template by finding it in
     * the template list. `canSaveAsTemplate` is what gates the controls, and
     * it is presentation only — `update()` refuses independently, which is
     * asserted as its own test.
     */
    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }

        return response()->json($this->status($assessment, $request));
    }

    /**
     * Publishes or unpublishes a template (W45; PLAN-2.2.md D12).
     *
     * Admins only since 2.5.1 (see AssessmentPolicy::saveAsTemplate), and it
     * moves `is_template` alone. Through 2.5 publishing also set `is_public`,
     * because using a template needed `view`; `useAsTemplate` replaced that,
     * so a template no longer has to be readable by the whole instance to be
     * usable by it — and the instance's "no public assessments" setting no
     * longer stands between an admin and the template library.
     */
    public function update(Request $request, Assessment $assessment): RedirectResponse|JsonResponse
    {
        if (Gate::forUser($request->user())->denies('saveAsTemplate', $assessment)) {
            abort(403);
        }

        $validated = $request->validate([
            'is_template' => ['required', 'boolean'],
        ]);

        $publishing = (bool) $validated['is_template'];

        $assessment->update(['is_template' => $publishing]);

        // Two callers, two shapes. The Share dialog is an axios panel inside
        // a modal and must stay open, so it gets the new state as JSON; an
        // ordinary form post still gets the redirect it has had since W20,
        // which is what the existing feature tests assert.
        if ($request->wantsJson()) {
            return response()->json($this->status($assessment->fresh(), $request));
        }

        return redirect()
            ->route('assessments.show', $assessment)
            ->with('success', $publishing ? 'Saved as a template.' : 'No longer a template.');
    }

    /**
     * Creates a new assessment from a template — a copy, never a live
     * link (schema.md §3.14): the clone gets its own scales (if the
     * template's were custom), its own tags, and a fresh uuid on every
     * node, so a later edit to the template cannot retroactively change
     * an assessment already built from it. That is the correct behaviour,
     * not a limitation — signing off an assessment must not be undone by
     * someone else tidying up the template it started from.
     */
    public function store(Request $request, Assessment $assessment, AssessmentCloner $cloner): JsonResponse
    {
        // Only an assessment actually marked as a template may be used as
        // one — this would otherwise be a generic "duplicate any assessment"
        // endpoint. 404 rather than 403 so it does not confirm the uuid.
        abort_unless($assessment->is_template, 404);

        if (Gate::forUser($request->user())->denies('useAsTemplate', $assessment)) {
            abort(403);
        }

        /** @var User $user */
        $user = $request->user();

        $clone = $cloner->cloneAssessment($assessment, $user);

        return response()->json(['assessment' => ['uuid' => $clone->uuid]], 201);
    }

    /** @return array{is_template: bool, is_public: bool, canSaveAsTemplate: bool} */
    private function status(Assessment $assessment, Request $request): array
    {
        return [
            'is_template' => (bool) $assessment->is_template,
            'is_public' => (bool) $assessment->is_public,
            'canSaveAsTemplate' => Gate::forUser($request->user())->allows('saveAsTemplate', $assessment),
        ];
    }

    /** @return array<string, mixed> */
    private function templateSummary(Assessment $assessment): array
    {
        return [
            'uuid' => $assessment->uuid,
            'title' => $assessment->rootNode?->title,
            'description' => $assessment->rootNode?->description,
            'owner' => $assessment->owner?->name,
            'currency' => $assessment->currency,
            'updatedAt' => $assessment->updated_at?->toJSON(),
        ];
    }
}
