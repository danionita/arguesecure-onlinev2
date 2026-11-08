<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Help\AdminHelpLibrary;
use App\Domain\Help\MarkdownRenderer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrators' help and its search. Registered inside the `admin`
 * route group, so it is as closed as every other admin page; the pages are
 * not in the public help and not in the assistant's corpus.
 */
class HelpController extends Controller
{
    public function index(Request $request): Response
    {
        // Trimmed first, then capped once: the cap never cuts into a word
        // that follows a run of spaces.
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return Inertia::render('Admin/Help', [
            'pages' => AdminHelpLibrary::all(),
            'query' => $query,
            'results' => AdminHelpLibrary::search($query),
            'minQuery' => AdminHelpLibrary::MIN_QUERY,
        ]);
    }

    public function show(string $slug): Response
    {
        $title = AdminHelpLibrary::title($slug);
        abort_if($title === null || ! File::exists(AdminHelpLibrary::path($slug)), 404);

        return Inertia::render('Admin/HelpPage', [
            'slug' => $slug,
            'title' => $title,
            'html' => MarkdownRenderer::toHtml(File::get(AdminHelpLibrary::path($slug))),
            'pages' => AdminHelpLibrary::all(),
        ]);
    }
}
