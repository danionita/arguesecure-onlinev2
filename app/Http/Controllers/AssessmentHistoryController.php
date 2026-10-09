<?php

namespace App\Http\Controllers;

use App\Domain\History\TimelineReader;
use App\Domain\History\VersionTimeline;
use App\Domain\Snapshots\AutoVersioner;
use App\Domain\Snapshots\SnapshotService;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The assessment history page (W48, item 21; PLAN-2.2.md D15; reshaped in
 * 2.5.1).
 *
 * `view`, the same audience as the assessment itself. History is a record of
 * what was done to something you can already read; gating it higher would mean
 * a viewer can see the current numbers but not how they got there, which is
 * the opposite of what a defensible assessment is for.
 *
 * ## Versions first, changes inside them (2.5.1)
 *
 * Through 2.5 this page was a flat list of changes with a long note at its
 * foot explaining that whole versions could be restored from a separate
 * "snapshots page" — reachable only from that note — and that there usually
 * was not one, because nobody had saved one. Versions are now saved
 * automatically (AutoVersioner) and by hand from this page, and they are the
 * page's structure: VersionTimeline groups every change under the version it
 * went into, with the unsaved ones on top under **Current version**.
 *
 * Restoring stays whole-version only, through the existing restore route and
 * dialog: reconstructing an arbitrary moment between two versions from the
 * logs is still not offered, because comments never enter either log.
 */
class AssessmentHistoryController extends Controller
{
    public function index(Request $request, Assessment $assessment, TimelineReader $timeline, VersionTimeline $versions): Response
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }

        // Never the whole request bag (security.md §7): named filters, each
        // validated, and `action` checked against a fixed list rather than
        // reaching a `where` clause as free text.
        //
        // Worded without the literal call, deliberately: SourceAuditTest's
        // "it never calls the whole request bag, anywhere" scans raw source,
        // not comment-stripped source, so a file that merely *names* the
        // banned pattern trips it.
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'in:'.implode(',', $this->actions())],
            'node_uuid' => ['nullable', 'string', 'uuid'],
            // Which group to send in full; every other one sends its newest
            // few. `current` or `v{snapshot id}`, nothing else.
            'expand' => ['nullable', 'string', 'regex:/^(current|v[0-9]+)$/'],
        ]);

        $filters = [
            'user_id' => $validated['user_id'] ?? null,
            'action' => $validated['action'] ?? null,
            'node_uuid' => $validated['node_uuid'] ?? null,
        ];

        return Inertia::render('Assessment/History', [
            'assessment' => [
                'uuid' => $assessment->uuid,
                'title' => $assessment->title ?? 'Untitled assessment',
            ],
            'versions' => $versions->build($assessment, $filters, $validated['expand'] ?? null),
            'expanded' => $validated['expand'] ?? null,
            'filters' => $filters,
            'actors' => $timeline->actors($assessment),
            'actions' => $this->actions(),
            'autoSave' => [
                'idleMinutes' => AutoVersioner::IDLE_MINUTES,
                'maxChanges' => AutoVersioner::MAX_CHANGES,
            ],
            'abilities' => [
                'canSave' => Gate::forUser($request->user())->allows('update', $assessment),
                'canRestore' => Gate::forUser($request->user())->allows('update', $assessment),
            ],
        ]);
    }

    /**
     * Saves the current version by hand (2.5.1): everything listed under
     * "Current version" becomes a version of its own. `update`, the same
     * ability creating a snapshot has always needed. The name is optional —
     * the page numbers versions anyway — and defaults to who saved it.
     */
    public function store(Request $request, Assessment $assessment, SnapshotService $snapshots): RedirectResponse
    {
        if (Gate::forUser($request->user())->denies('update', $assessment)) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        // The route sits behind `auth`, so there is always a user here.
        /** @var User $user */
        $user = $request->user();
        $name = trim((string) ($validated['name'] ?? ''));

        $snapshots->create(
            assessment: $assessment,
            name: $name !== '' ? $name : mb_substr('Saved by '.$user->name, 0, 120),
            note: null,
            createdByUserId: $user->id,
            kind: 'manual',
        );

        return redirect()
            ->route('assessments.history.index', $assessment)
            ->with('success', 'Version saved.');
    }

    /**
     * The fixed filter vocabulary.
     *
     * `UndoService::KNOWN_KINDS` for the structural half, prefixed `field.` for
     * the revision half so one dropdown covers both logs without the client
     * having to know there are two.
     *
     * @return list<string>
     */
    private function actions(): array
    {
        return [
            'node.create',
            'node.delete',
            'node.move',
            'link.create',
            'link.delete',
            'subtree.clone',
            'catalog.insert',
            'field.update',
        ];
    }
}
