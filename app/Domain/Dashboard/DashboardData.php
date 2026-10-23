<?php

namespace App\Domain\Dashboard;

use App\Domain\Notifications\MentionedInComment;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The four questions someone has when they arrive (ui.md §8, W42 item 17):
 * what is assigned to me, who mentioned me, what do I own, and what have I
 * been invited to.
 *
 * A domain class rather than a fat controller because two of the four
 * sections are not finished yet, and the seam matters more than the code
 * currently behind it. `assignedToMe()` and `mentions()` return empty lists
 * today and are the *only* places W46 and W47 have to change — neither
 * package should have to rewrite `Dashboard.vue`, and the page is built so
 * that it will not need to: the sections render, the empty states already say
 * what will fill them, and the rows arrive in the shape the real data will
 * have.
 *
 * That is deliberate rather than lazy. Building the page around two sections
 * that do not exist yet, and then adding them afterwards, is how a layout
 * ends up designed for two boxes and retrofitted for four.
 *
 * ## Plain arrays, not Collections
 *
 * Every method here returns `list<array<string, mixed>>`. `Collection`'s
 * `TValue` is invariant, so a method declared to return a collection of
 * `array<string, mixed>` cannot return one of `non-empty-array<...>` — which
 * is what PHPStan infers the moment a mapper builds a row with a known key.
 * The value is an Inertia prop and is JSON either way; a list says exactly as
 * much with no generics to fight.
 *
 * ## Scoping
 *
 * Owned and shared only. A public assessment is reached by its link, exactly
 * as its owner intended when they shared it — listing every public one here
 * would turn a personal dashboard into a directory of other people's work and
 * quietly make "public" mean "advertised". Same rule, same words, as
 * AssessmentLifecycleController::index().
 */
class DashboardData
{
    /**
     * Enough to scan, not enough to read. The full lists are one click away
     * on `/assessments`, and a dashboard that paginates has stopped being a
     * dashboard.
     */
    private const SECTION_LIMIT = 6;

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        return [
            'assignedToMe' => $this->assignedToMe($user),
            'mentions' => $this->mentions($user),
            'myAssessments' => $this->myAssessments($user),
            'sharedWithMe' => $this->sharedWithMe($user),
            'counts' => $this->counts($user),
        ];
    }

    /**
     * The tab and section counts (D91, W125): the real totals, not the length
     * of the excerpt above. Same scopes as the lists — owned for *Mine*
     * (`/assessments`), membership for *Shared with me* (`/assessments/shared`)
     * — and `SoftDeletes` already leaves a trashed assessment out of both.
     *
     * @return array{mine: int, shared: int}
     */
    private function counts(User $user): array
    {
        return [
            'mine' => Assessment::query()->where('owner_user_id', $user->id)->count(),
            'shared' => $this->sharedQuery($user)->count(),
        ];
    }

    /**
     * Nodes assigned to this user (item 16, W46).
     *
     * The row shape is the one W42 wrote down here before the data existed,
     * unchanged:
     *
     *   ['assessment_uuid', 'assessment_title', 'node_uuid', 'type',
     *    'title', 'missing']
     *
     * where `missing` names what the node still needs, which is the whole
     * reason an assignment is worth showing on a dashboard rather than only
     * in the tree: the question is not "what am I on" but "what is waiting
     * for me".
     *
     * ## Scoping, and why it is not just the join
     *
     * Only assessments this user can still reach. Removing a collaborator
     * deletes their assignments (see AssessmentUser), so in the ordinary case
     * this filter changes nothing — but it is the difference between a
     * dashboard that is wrong for one request and one that is wrong forever
     * if any future path ever removes access without going through that hook.
     * A dashboard is the wrong place to discover a stale grant.
     *
     * `node_assignees_user_index (user_id, node_id)` is what makes this cheap;
     * without it this query scans the pivot on every sign-in.
     *
     * @return list<array<string, mixed>>
     */
    private function assignedToMe(User $user): array
    {
        $nodes = Node::query()
            ->with('assessment.rootNode')
            ->whereHas('assignees', fn ($assignee) => $assignee->where('users.id', $user->id))
            ->whereHas('assessment', fn ($assessment) => $assessment
                ->where('owner_user_id', $user->id)
                ->orWhereHas('members', fn ($member) => $member->where('users.id', $user->id)))
            ->where('type', '!=', 'root')
            ->latest('updated_at')
            ->limit(self::SECTION_LIMIT)
            ->get();

        return $nodes->map(fn (Node $node): array => [
            'assessment_uuid' => $node->assessment->uuid,
            'assessment_title' => $node->assessment->title ?? 'Untitled assessment',
            'node_uuid' => $node->uuid,
            'type' => $node->type,
            'title' => (string) $node->title,
            'missing' => $this->missingValue($node),
        ])->values()->all();
    }

    /**
     * What this node still needs, or null when nothing does.
     *
     * Mirrors the modal's own `?` badge exactly (NodeModal.vue): a mode that
     * was never chosen counts as missing, and so does a chosen mode with no
     * value under it. A defence is never "missing" here — its strength lives
     * on the `attack_defence` edge, not on the node, so one defence can be
     * complete against one attack and blank against another, and a single
     * word on a dashboard row cannot say which.
     */
    private function missingValue(Node $node): ?string
    {
        if ($node->type === 'risk') {
            $missing = $node->impact_mode === null
                || ($node->impact_mode === 'quantitative' && $node->impact_amount === null)
                || ($node->impact_mode === 'qualitative' && $node->impact_level_id === null);

            return $missing ? 'needs an impact' : null;
        }

        if ($node->type === 'attack') {
            $missing = $node->likelihood_mode === null
                || ($node->likelihood_mode === 'quantitative' && $node->likelihood_aro === null)
                || ($node->likelihood_mode === 'qualitative' && $node->likelihood_level_id === null);

            return $missing ? 'needs a likelihood' : null;
        }

        return null;
    }

    /**
     * Comments mentioning this user, unresolved first (item 15, W47).
     *
     * Read from the notifications this user has already been sent, not by
     * re-scanning comment bodies. W42 warned that scanning would be slow and
     * wrong — two people called Dana, or a comment about a node called "Dana's
     * laptop" — and `MentionParser` resolves that once, at write time, against
     * the assessment's collaborators only. Re-deriving it here would mean two
     * implementations of "who was mentioned" that could disagree, and the
     * dashboard would be the one nobody checked.
     *
     * `resolved` is read live rather than from the notification, because it is
     * the one field that changes after the mention: a thread someone has since
     * closed should sort down, and a notification is otherwise a record of
     * what was true when it was sent.
     *
     * Row shape, as W42 specified it:
     *
     *   ['assessment_uuid', 'assessment_title', 'node_uuid', 'node_title',
     *    'excerpt', 'author', 'resolved', 'created_at']
     *
     * @return list<array<string, mixed>>
     */
    private function mentions(User $user): array
    {
        $notifications = $user->notifications()
            ->where('type', MentionedInComment::class)
            ->latest()
            ->limit(self::SECTION_LIMIT * 2)
            ->get();

        /** @var array<int, string> $commentIds */
        $commentIds = $notifications
            ->map(fn (DatabaseNotification $row): mixed => $row->data['comment_id'] ?? null)
            ->filter()
            ->all();

        // One query for the resolved flags, not one per row.
        $resolved = Comment::query()
            ->whereIn('id', $commentIds)
            ->pluck('resolved_at', 'id');

        return $notifications
            ->map(function (DatabaseNotification $row) use ($resolved): array {
                /** @var array<string, mixed> $data */
                $data = $row->data;
                $commentId = $data['comment_id'] ?? null;

                return [
                    'assessment_uuid' => $data['assessment_uuid'] ?? null,
                    'assessment_title' => $data['assessment_title'] ?? 'Untitled assessment',
                    'node_uuid' => $data['node_uuid'] ?? null,
                    'node_title' => $data['node_title'] ?? '',
                    'excerpt' => $data['excerpt'] ?? '',
                    'author' => $data['author'] ?? null,
                    'resolved' => $commentId !== null && ($resolved[$commentId] ?? null) !== null,
                    'created_at' => $row->created_at?->toJSON(),
                ];
            })
            // Unresolved first, newest within each group — the section exists
            // to answer "what still wants me", and a closed thread does not.
            ->sortBy(fn (array $row): int => $row['resolved'] ? 1 : 0)
            ->take(self::SECTION_LIMIT)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function myAssessments(User $user): array
    {
        return Assessment::query()
            ->with('rootNode')
            ->where('owner_user_id', $user->id)
            ->latest('updated_at')
            ->limit(self::SECTION_LIMIT)
            ->get()
            ->map(fn (Assessment $assessment): array => $this->summarise($assessment))
            ->values()
            ->all();
    }

    /**
     * Assessments someone else owns and this user has been invited to.
     *
     * Public since W73 (D33), because the dashboard tab is now an excerpt of
     * a real page — `/assessments/shared` — and the two must not be two
     * queries that could disagree about what "shared with me" means. The
     * *only* difference between them is `$limit`: the tab shows six, the page
     * shows all of them.
     *
     * **The membership clause is the authorization, not a filter applied
     * afterwards.** `whereHas('members')` means a row cannot appear here
     * without an `assessment_user` row for this user, which is what keeps a
     * public assessment they have nothing to do with off both surfaces —
     * `AssessmentPolicy::view()` would say yes to that one, and saying yes is
     * correct for opening it by its link and wrong for advertising it here.
     * `sharedWithMePage()` re-checks every row through the policy on top of
     * this; that check can only remove rows, never add them, which is the
     * ordering that keeps both statements true at once.
     *
     * @return list<array<string, mixed>>
     */
    public function sharedWithMe(User $user): array
    {
        return $this->mapShared(
            $this->sharedQuery($user)->limit(self::SECTION_LIMIT)->get()
        );
    }

    /**
     * The same collection, unabridged and policy-checked, for the page the
     * tab's "see all" leads to (D33, W73 item 20).
     *
     * Unpaginated on purpose, exactly as the assessment list is: the page
     * filters client-side, and a collection sized by how many people have
     * invited you does not need pages.
     *
     * The `view` pass is the route's AssessmentPolicy check, which CLAUDE.md
     * requires of every route touching an assessment and which a
     * collection route otherwise has no executable claim to. It runs
     * *second*, after the membership scope, and being a filter it can only
     * remove rows — so it cannot reintroduce the leak it would be on its own,
     * where `is_public` grants `view` to a stranger's assessment that must
     * never be advertised here.
     *
     * It costs no queries: `members:id` is eager-loaded above and
     * `AssessmentPolicy::roleFor()` prefers an already-loaded relation.
     *
     * @return list<array<string, mixed>>
     */
    public function sharedWithMePage(User $user): array
    {
        $assessments = $this->sharedQuery($user)
            ->with(AssessmentListRows::WITH)
            ->get()
            ->filter(fn (Assessment $assessment): bool => Gate::forUser($user)->allows('view', $assessment))
            ->values();

        // 2.5.1: the same rows as *My assessments* (AssessmentListRows), so
        // the page can render the same list — filter, icon actions and all —
        // rather than the dashboard's action-less rows.
        return (new AssessmentListRows)->for($assessments, $user);
    }

    /**
     * @return Builder<Assessment>
     */
    private function sharedQuery(User $user): Builder
    {
        return Assessment::query()
            // `owner` eager-loaded because every row names who shared it —
            // "the thing that is missing today" (PLAN-2.2.md W42). Without
            // the eager load that is one query per row.
            // `members` eager-loaded since W73 for the page's per-row Gate
            // check: AssessmentPolicy resolves a role through that relation,
            // so without it that check would be one query per row — the N+1
            // CLAUDE.md names as the legacy tree render's defining defect.
            ->with(['rootNode', 'owner', 'members:id'])
            ->where('owner_user_id', '!=', $user->id)
            ->whereHas('members', fn ($member) => $member->where('users.id', $user->id))
            ->latest('updated_at');
    }

    /**
     * @param  Collection<int, Assessment>  $assessments
     * @return list<array<string, mixed>>
     */
    private function mapShared(Collection $assessments): array
    {
        return $assessments
            ->map(function (Assessment $assessment): array {
                $row = $this->summarise($assessment);
                // Display name only, never the address. An assessment list is
                // a page about other people's work, which is exactly where an
                // address leaks (security.md, and the W17 report cover).
                $row['owner_name'] = $assessment->owner?->name;

                return $row;
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function summarise(Assessment $assessment): array
    {
        return [
            'uuid' => $assessment->uuid,
            // Assessment::title proxies the root node (schema.md — there is
            // no title column) and is nullable, because a soft-deleted root
            // makes the relation null.
            'title' => $assessment->title ?? 'Untitled assessment',
            'description' => $assessment->description,
            'updated_at' => $assessment->updated_at?->toJSON(),
        ];
    }
}
