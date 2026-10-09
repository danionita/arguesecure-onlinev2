<?php

namespace App\Observers;

use App\Domain\History\RevisionRecorder;
use App\Events\NodeCreated;
use App\Events\NodeDeleted;
use App\Events\NodeUpdated;
use App\Models\Node;
use App\Observers\Concerns\TriggersRecalculation;
use Illuminate\Support\Facades\Auth;

/**
 * PLAN.md Phase 6 item 4 — granular node broadcasts, carrying the changed
 * row rather than an id to re-fetch (CLAUDE.md, ui.md §6). Lives as a model
 * observer rather than inline in NodeController (outside this package's
 * ownership) so every path that creates/updates/deletes a node — today
 * only NodeController, but also whatever later packages add (catalogue
 * insert, snapshot restore, bulk import) — broadcasts consistently without
 * each of them having to remember to.
 *
 * Also the single place that fires History\RevisionRecorder (W18b,
 * schema.md §3.9): every ordinary field save, from wherever it originates,
 * goes through `updated` here exactly once, so this is the one writer of
 * per-field history rather than duplicating that call in every controller
 * that saves a Node.
 *
 * Registered once per concrete STI subclass in bootstrap/app.php
 * (RootNode, Risk, Attack, Defence): Eloquent's model events fire under the
 * *runtime* class name, and Node itself declares no `booted()` global
 * scope of its own for that reason (Node.php's own docblock) — the same
 * fact means `Node::observe()` alone would silently observe nothing.
 */
class NodeObserver
{
    /**
     * Laravel resolves an observer class registered by name (`::observe(
     * NodeObserver::class)`, bootstrap/app.php) through the container, so
     * this constructor-injected dependency is satisfied automatically —
     * no change to that registration is needed.
     */
    public function __construct(private readonly RevisionRecorder $revisions) {}

    public function created(Node $node): void
    {
        event(new NodeCreated($node, Auth::id(), Auth::user()?->name));
        TriggersRecalculation::bumpCalcVersionAndDebounce($node->assessment_id);
    }

    public function updated(Node $node): void
    {
        // A soft-delete's internal touch of `deleted_at` fires `updated`
        // too; the dedicated `deleted` handler below already covers that
        // case; broadcasting it a second time here would tell a
        // collaborator's client to revive a node it just watched vanish.
        // Recording a revision for a delete-triggered touch would be wrong
        // for the same reason (schema.md §3.9/§3.10: create/delete are not
        // this recorder's job) — deleted_at is not one of RevisionRecorder's
        // tracked fields anyway, but skipping the call here keeps intent
        // and behaviour lined up rather than relying on that incidentally.
        if ($node->isForceDeleting() || $node->trashed()) {
            return;
        }

        event(new NodeUpdated($node, Auth::id(), Auth::user()?->name));
        $this->revisions->recordUpdate($node, Auth::user());

        // calculation-engine.md §11.4: any value the engine reads —
        // impact/likelihood/status/cost columns, but also title (it is
        // cheaper to always bump than to diff which columns changed, and a
        // stale cache is the unsafe direction) — invalidates the cache.
        TriggersRecalculation::bumpCalcVersionAndDebounce($node->assessment_id);
    }

    public function deleted(Node $node): void
    {
        event(new NodeDeleted($node, Auth::id(), Auth::user()?->name));
        TriggersRecalculation::bumpCalcVersionAndDebounce($node->assessment_id);
    }

    public function restored(Node $node): void
    {
        event(new NodeCreated($node, Auth::id(), Auth::user()?->name));
        TriggersRecalculation::bumpCalcVersionAndDebounce($node->assessment_id);
    }
}
