<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Availability;
use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Domain\Reporting\AssessmentJsonExporter;
use App\Domain\Reporting\Export\AssessmentCsvExporter;
use App\Domain\Reporting\Export\ExcelExporter;
use App\Domain\Reporting\Export\ExportFormat;
use App\Domain\Reporting\Export\HtmlExporter;
use App\Domain\Reporting\Export\WordExporter;
use App\Domain\Reporting\ReportBuilder;
use App\Domain\Reporting\ReportOptions;
use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The report (PLAN.md Phase 7, ui.md §9) and the five exports behind its one
 * menu (W74, D34): HTML, CSV, JSON, Excel and Word. PDF is not among them
 * and is not missing — it is the browser's print pipeline (D6), which is why
 * `dompdf` stays gone.
 *
 * Every action here is a read — same authorization shape as
 * AssessmentController::show (ui.md §3, CLAUDE.md "Every route touching an
 * assessment goes through AssessmentPolicy. No exceptions"): `view` covers
 * the owner, a collaborator of any role, an admin, a public assessment
 * (**read only** — `is_public` never granted write, and security.md defect
 * #4 is the record of what happened when the legacy app thought otherwise)
 * and a valid share-link session, matching app/Policies/AssessmentPolicy.php
 * exactly. This package owns no app/Http/Requests/** path, so the check is
 * inline via Gate::forUser() rather than a dedicated FormRequest — same
 * reasoning ShowAssessmentRequest documents: Gate::forUser() (not
 * `$request->user()?->can(...)`) so a null/guest user still reaches
 * AssessmentPolicy::view()'s is_public and share-token branches instead of
 * short-circuiting to false beforehand.
 *
 * **Every method assembles the report exactly once, through ReportBuilder,
 * and hands the same array to whichever renderer was asked for.** There is
 * one assembled report and five renderings of it; nothing here — and nothing
 * in App\Domain\Reporting\Export — re-queries or re-derives a figure the
 * engine already produced. Two exporters reading two sources for the same
 * number is how two documents about the same assessment come to disagree.
 *
 * **ReportOptions binds all five.** Each export resolves its options from
 * the query string through the same `ReportOptions::fromQuery()` the page
 * uses, so "include shelved" means the same thing on a spreadsheet as on
 * screen — the failure W33 named for the download button — and `download=1`
 * still forces the whole report on any of them. The whole input surface here
 * is `$request->query()`, and `ReportOptions` validates every value it reads
 * out of it against a fixed list — nothing reaches a query, a column name or
 * a header from the request bag unchecked.
 */
class ReportController extends Controller
{
    public function show(
        Request $request,
        Assessment $assessment,
        ReportBuilder $builder,
    ): Response {
        $this->authorizeView($request, $assessment);

        $options = ReportOptions::fromQuery($request->query());
        $report = $builder->build($assessment, $options);

        // W54. The written executive summary, and — only for someone who could
        // accept it — the draft waiting to become one.
        //
        // The draft is withheld from everyone else on purpose. It is
        // un-reviewed model text, and the whole control on job C is that a
        // person with `update` reads it before it becomes part of the report.
        // Sending it to a public reader or a share-link visitor would put that
        // text in front of exactly the audience the accept step exists to
        // protect. `narrative` and `generated` are for everyone: the second is
        // the disclosure, and a disclosure shown only to insiders is not one.
        $canEdit = Gate::forUser($request->user())->allows('update', $assessment);

        // W83, D40/D41. `AssessmentActions` gates Settings and Share on
        // `abilities.manage` — the same `AssessmentPolicy::manage()` the
        // editor's own mount of that component reads off `store.abilities`.
        // The report has no store, so this is the one place to resolve it.
        // Computed for every viewer, including a guest: `Show.vue` does not
        // mount the action bar for a guest at all (see its file header for
        // why — Settings and History both sit behind `auth` middleware, so
        // there is nothing for an anonymous share-link session to navigate
        // to), so a guest's `false` here is never read.
        $canManage = Gate::forUser($request->user())->allows('manage', $assessment);

        return Inertia::render('Report/Show', [
            'assessment' => [
                'uuid' => $assessment->uuid,
                'summaryNarrative' => $assessment->summary_narrative,
                'summaryNarrativeGenerated' => (bool) $assessment->summary_narrative_generated,
                'summaryDraft' => $canEdit ? $assessment->summary_draft : null,
                'canEditSummary' => $canEdit,
                // The summary switch by name (W87, D48). The only assistant
                // control on the report drafts the executive summary, which is
                // the ~250s job an admin is most likely to decline.
                'assistantAvailable' => $canEdit
                    && $request->user() !== null
                    && app(Availability::class)->isAvailable(Availability::FEATURE_SUMMARY),
            ],
            'abilities' => [
                'manage' => $canManage,
            ],
            'report' => $report,
        ]);
    }

    /**
     * The whole report as one self-contained HTML file. Rendered from the
     * same forced-full URL W33 defined when `download=1` is present, which is
     * why that query-parameter contract does not go away with the button
     * (D35) — it is now one of five formats rather than a button of its own.
     */
    public function exportHtml(Request $request, Assessment $assessment, ReportBuilder $builder, HtmlExporter $exporter): HttpResponse
    {
        return $this->file($request, $assessment, $builder, ExportFormat::Html, $exporter->export(...));
    }

    /**
     * The route is still `defences.csv` and the name still
     * `assessments.report.defences-csv`. The file it returns is the whole
     * report now (item 25), but the URL is in e2e coverage this package does
     * not own and in whatever bookmarks and scripts have accumulated since
     * W17 — renaming it would break those for a cosmetic gain.
     */
    public function defencesCsv(Request $request, Assessment $assessment, ReportBuilder $builder, AssessmentCsvExporter $exporter): HttpResponse
    {
        return $this->file($request, $assessment, $builder, ExportFormat::Csv, $exporter->export(...));
    }

    public function exportExcel(Request $request, Assessment $assessment, ReportBuilder $builder, ExcelExporter $exporter): HttpResponse
    {
        return $this->file($request, $assessment, $builder, ExportFormat::Excel, $exporter->export(...));
    }

    public function exportWord(Request $request, Assessment $assessment, ReportBuilder $builder, WordExporter $exporter): HttpResponse
    {
        return $this->file($request, $assessment, $builder, ExportFormat::Word, $exporter->export(...));
    }

    /**
     * The interchange format, and the one export whose shape is a promise:
     * it is what snapshot/restore reads, so it keeps the shape it has had
     * since W17 rather than being re-cut to match the other four.
     */
    public function exportJson(
        Request $request,
        Assessment $assessment,
        AssessmentGraphLoader $loader,
        RiskCalculator $calculator,
        CalculationCache $cache,
        AssessmentJsonExporter $exporter,
    ): HttpResponse {
        $this->authorizeView($request, $assessment);

        $graph = $loader->load($assessment);
        $results = $cache->remember($assessment, fn () => $calculator->compute($graph));
        $nodesByUuid = Node::query()
            ->where('assessment_id', $assessment->id)
            ->orderBy('position')
            ->get()
            ->keyBy('uuid');

        $payload = $exporter->export($assessment, $graph, $results, $nodesByUuid);

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$this->filename($assessment, ExportFormat::Json).'"',
        ]);
    }

    /**
     * Assemble once, render once, send as an attachment.
     *
     * @param  callable(array<string, mixed>, ReportOptions): string  $render
     */
    private function file(
        Request $request,
        Assessment $assessment,
        ReportBuilder $builder,
        ExportFormat $format,
        callable $render,
    ): HttpResponse {
        $this->authorizeView($request, $assessment);

        $options = ReportOptions::fromQuery($request->query());
        $report = $builder->build($assessment, $options);

        return response($render($report, $options), 200, [
            'Content-Type' => $format->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$this->filename($assessment, $format).'"',
        ]);
    }

    /**
     * The uuid, not the title. A title is user input on its way into a
     * `Content-Disposition` header, which is a place where a quote or a
     * newline stops being a character and starts being syntax; the uuid
     * cannot carry either.
     */
    private function filename(Assessment $assessment, ExportFormat $format): string
    {
        return $format->filenamePrefix().'-'.$assessment->uuid.'.'.$format->extension();
    }

    private function authorizeView(Request $request, Assessment $assessment): void
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }
    }
}
