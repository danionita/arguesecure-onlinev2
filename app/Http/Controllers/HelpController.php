<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Availability;
use App\Domain\Help\HelpLibrary;
use App\Domain\Help\MarkdownRenderer;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Renders the markdown help pages under resources/markdown/help/**
 * (PLAN.md Phase 9 item 2, W23). Deliberately outside the `auth` group —
 * see routes/web.php's W23 block for the full reasoning: help is
 * general product documentation, not assessment data, and a facilitator
 * running a public workshop or a guest reading a shared assessment through
 * a read-only link should be able to reach it without an account.
 *
 * The route slug never becomes a filesystem path: {@see HelpLibrary} is a
 * hardcoded manifest, and an unknown slug 404s before any file I/O happens
 * — the same "no dynamic path from request input" discipline
 * NodeController's typed endpoints follow for column names.
 */
class HelpController extends Controller
{
    public function index(Availability $availability): Response
    {
        return Inertia::render('Help/Index', [
            'pages' => HelpLibrary::all(),
            'assistant' => $this->assistantState($availability),
        ]);
    }

    public function show(string $slug): Response
    {
        $title = HelpLibrary::title($slug);

        if ($title === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $path = HelpLibrary::path($slug);

        // Belt-and-suspenders: the manifest is the real guard, but a page
        // listed in it with no matching file on disk should still 404
        // rather than throw — this is exactly the failure mode the W23
        // "every markdown file the help index lists actually exists and
        // parses" test exists to catch before it ever reaches production.
        if (! File::exists($path)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $html = MarkdownRenderer::toHtml(File::get($path));

        return Inertia::render('Help/Show', [
            'slug' => $slug,
            'title' => $title,
            'html' => $html,
            'pages' => HelpLibrary::all(),
        ]);
    }

    /**
     * Whether to offer the "ask a question" control (W51).
     *
     * **A guest never gets it, even on a deployment where it works.** This
     * route is deliberately outside `auth` so a workshop facilitator or
     * share-link reader can reach the documentation without an account — but
     * the generating endpoint behind the control is authenticated, because it
     * spends seconds of CPU per call and an anonymous one is a free
     * denial-of-service primitive. Rendering a control that would 401 is
     * exactly the "looks like it works and doesn't" failure W8b's
     * `registration_locked` was deleted to avoid.
     *
     * @return array{available: bool, reason: string|null}
     */
    private function assistantState(Availability $availability): array
    {
        if (request()->user() === null) {
            return ['available' => false, 'reason' => null];
        }

        return [
            'available' => $availability->isAvailable(Availability::FEATURE_HELP),
            'reason' => $availability->reason(Availability::FEATURE_HELP),
        ];
    }
}
