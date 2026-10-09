<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\CsvImportRow;
use App\Domain\Accounts\CsvUserImporter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportCommitRequest;
use App\Http\Requests\Admin\ImportPreviewRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk CSV account creation, split into a stateless preview step and a
 * confirm step (ui.md §10). Nothing is persisted between the two calls —
 * the browser re-sends the CSV content it already has, so there is no
 * "pending import" row this controller must clean up or that could be
 * hijacked by another admin.
 */
class UserImportController extends Controller
{
    /**
     * Bulk import is a dialog on the Users tab since 2.5.1; this URL — the
     * old tab, and the preview page's "back" — opens it there.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('admin.users.index', ['import' => 1]);
    }

    public function preview(ImportPreviewRequest $request, CsvUserImporter $importer): Response
    {
        $rows = $importer->parse($request->csvContent());

        return Inertia::render('Admin/Users/ImportPreview', [
            'csvText' => $request->csvContent(),
            'rows' => array_map(fn (CsvImportRow $row): array => $row->toArray(), $rows),
            'validCount' => count(array_filter($rows, fn (CsvImportRow $row): bool => $row->isValid())),
            'rejectedCount' => count(array_filter($rows, fn (CsvImportRow $row): bool => ! $row->isValid())),
        ]);
    }

    public function commit(ImportCommitRequest $request, CsvUserImporter $importer): Response
    {
        $validated = $request->validated();
        $excluded = $validated['excluded_lines'] ?? [];

        $rows = $importer->parse($validated['csv_text']);
        $rows = array_values(array_filter(
            $rows,
            fn (CsvImportRow $row): bool => ! in_array($row->line, $excluded, true),
        ));

        $created = $importer->commit($rows);

        return Inertia::render('Admin/Users/ImportResult', [
            // Shown exactly once: this response is the only place these
            // plaintext passwords ever exist outside the user's own head
            // (ui.md §10: "never re-derivable, never stored in plain
            // text, never emailed").
            'created' => array_map(fn (array $entry): array => [
                'name' => $entry['user']->name,
                'email' => $entry['user']->email,
                'password' => $entry['password'],
            ], $created),
        ]);
    }
}
