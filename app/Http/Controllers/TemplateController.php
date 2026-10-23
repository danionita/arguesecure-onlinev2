<?php

namespace App\Http\Controllers;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Retention\AssessmentEraser;
use App\Domain\Structure\AssessmentCloner;
use App\Domain\Structure\AssessmentCreator;
use App\Domain\Templates\TemplateNodes;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Templates (schema.md §3.14, PLAN.md Phase 8 item 4): publish an assessment
 * as a template, manage templates, and create a new assessment from one. Creation reuses
 * Structure\AssessmentCloner::cloneAssessment() unmodified (W6) — it
 * already produces exactly the required behaviour, a genuine copy that
 * remaps every uuid, so later edits to the template can never propagate
 * into an assessment already created from it (proved in
 * tests/Feature/Catalog/TemplateTest.php — see that file's own note on
 * the AssessmentCloner defect this package found and does not own).
 *
 * Since 2.5.1: publishing a template is an admin's decision
 * (`saveAsTemplate`), and every signed-in account can list the templates
 * and build its own private copy of one (`useAsTemplate`). The template
 * library is instance-wide reference material, like the catalogue.
 *
 * Since W141 (2.7.0) a template is not anyone's assessment: it has no owner,
 * admins create, edit (in the ordinary editor) and delete it from the
 * Templates page, and publishing copies an assessment into one.
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
            ->with(['rootNode:id,assessment_id,title,description'])
            ->get()
            ->filter(fn (Assessment $assessment): bool => Gate::forUser($user)->allows('useAsTemplate', $assessment))
            ->map(fn (Assessment $assessment): array => $this->templateSummary($assessment))
            ->values();

        return Inertia::render('Catalog/Templates', [
            'templates' => $templates,
            // Presentation only; every route behind the controls refuses a
            // non-admin on its own.
            'canManage' => (bool) $user?->is_admin,
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
     * Publishes an assessment as a template (W141; PLAN-2.7-admin-templates.md T3).
     *
     * A copy, never a flag: the template is a separate, ownerless assessment
     * made by AssessmentCloner, and nothing is written to the original or
     * remembered about it. Editing either afterwards changes only itself,
     * and there is nothing to unpublish — a template is removed by deleting
     * it on the Templates page. Through 2.6 this moved `is_template` on the
     * assessment itself, so the template and its source were one row.
     *
     * Admins only (`saveAsTemplate`): the copy is offered to every account on
     * the instance and cannot be withdrawn from whoever has used it. A
     * template cannot be published again — it already is one (404).
     */
    public function publish(Request $request, Assessment $assessment, AssessmentCloner $cloner): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('saveAsTemplate', $assessment)) {
            abort(403);
        }

        abort_if($assessment->is_template, 404);

        $template = $cloner->cloneAsTemplate($assessment);

        SecurityLog::record(
            SecurityEvent::TemplatePublished,
            $request->user(),
            SecurityLog::assessment($template),
            ['source' => $assessment->uuid],
        );

        return response()->json(['template' => ['uuid' => $template->uuid]], 201);
    }

    /**
     * A new, empty template (W141, T4). It opens in the ordinary editor, where
     * its title and everything else are set, so this asks for nothing.
     */
    public function create(Request $request, AssessmentCreator $creator): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (Gate::forUser($user)->denies('createTemplate', Assessment::class)) {
            abort(403);
        }

        $template = $creator->createTemplate($user, 'Untitled template');

        SecurityLog::record(SecurityEvent::TemplateCreated, $user, SecurityLog::assessment($template));

        return redirect()->route('assessments.show', $template);
    }

    /**
     * Deletes a template for good (W141, T5): there is no owner to restore it
     * for and nothing depends on it, because every assessment made from it is
     * an independent copy. Soft-deleted first so AssessmentEraser, the one
     * place that knows how to take an assessment's scales and nodes apart,
     * does the work.
     */
    public function destroy(Request $request, Assessment $assessment, AssessmentEraser $eraser): RedirectResponse
    {
        abort_unless($assessment->is_template, 404);

        if (Gate::forUser($request->user())->denies('delete', $assessment)) {
            abort(403);
        }

        // One transaction: a failure part-way must not leave the template
        // soft-deleted, which would hide it from the library without erasing it.
        // Read before the row goes: the uuid is the only thing the log keeps.
        $target = SecurityLog::assessment($assessment);

        DB::transaction(function () use ($assessment, $eraser): void {
            $assessment->delete();

            if (! $eraser->erase($assessment)) {
                throw new \RuntimeException('The template could not be erased.');
            }
        });

        SecurityLog::record(SecurityEvent::TemplateDeleted, $request->user(), $target);

        return redirect()->route('templates.index')->with('success', 'Template deleted.');
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

    /** @return array<string, mixed> */
    private function templateSummary(Assessment $assessment): array
    {
        return [
            'uuid' => $assessment->uuid,
            'title' => $assessment->rootNode?->title,
            'description' => $assessment->rootNode?->description,
            'currency' => $assessment->currency,
            'updatedAt' => $assessment->updated_at?->toJSON(),
        ];
    }
}
