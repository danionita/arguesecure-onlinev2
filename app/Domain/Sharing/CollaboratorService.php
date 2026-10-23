<?php

namespace App\Domain\Sharing;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Models\Assessment;
use App\Models\AssessmentUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Adds, re-roles and removes rows in `assessment_user` (schema.md §3.7).
 * The owner is never a row here — `assessments.owner_user_id` is the
 * single source of ownership — so every method defends against a caller
 * accidentally treating the owner as an ordinary collaborator, which is
 * exactly the shape of the classic sharing-feature privilege-escalation
 * bug this package is required not to reproduce (see the W9 brief).
 *
 * There is no accept/decline step: `assessment_user.accepted_at` stays
 * nullable in the schema for a future invite-workflow package, but
 * nothing in Phase 3 item 5 asks for a pending state, and CLAUDE.md rules
 * out an SMTP dependency that a real invite notification would need.
 * Adding a collaborator here is immediate and self-accepted
 * (`accepted_at` = now()) — a judgement call, noted in the W9 report.
 */
class CollaboratorService
{
    /** @var list<string> */
    public const ROLES = ['viewer', 'commenter', 'editor', 'manager'];

    /**
     * Users eligible to appear in the "add a collaborator" search: active
     * accounts, not the owner (already has full access), not already a
     * member (use updateRole instead). Search is deliberately restricted
     * to callers who already hold `manage` on a real assessment — see
     * ShareController — so this is never a general-purpose user directory;
     * it returns only the columns the dialog needs.
     *
     * @return Collection<int, User>
     */
    public static function searchAddable(Assessment $assessment, string $query, int $limit = 10): Collection
    {
        $existingIds = $assessment->members()->pluck('users.id')
            ->push($assessment->owner_user_id);

        return User::query()
            ->select(['id', 'name', 'email'])
            ->where('is_active', true)
            ->whereNotIn('id', $existingIds)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /** @throws \InvalidArgumentException if $target is the assessment's owner. */
    public static function add(Assessment $assessment, User $target, string $role, User $invitedBy): AssessmentUser
    {
        if ($target->id === $assessment->owner_user_id) {
            throw new \InvalidArgumentException('The owner cannot be added as a collaborator.');
        }

        $assessment->members()->attach($target->id, [
            'role' => $role,
            'invited_by' => $invitedBy->id,
            'invited_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);

        SecurityLog::record(SecurityEvent::CollaboratorAdded, $invitedBy, SecurityLog::assessment($assessment), [
            'user' => SecurityLog::user($target),
            'role' => $role,
        ]);

        return AssessmentUser::query()
            ->where('assessment_id', $assessment->id)
            ->where('user_id', $target->id)
            ->firstOrFail();
    }

    /**
     * @throws \InvalidArgumentException if $collaborator's user is the
     *                                   assessment's owner — structurally shouldn't happen (add() refuses
     *                                   to create such a row) but this is the second, independent check
     *                                   the brief asks for: a manager must not be able to demote the owner.
     */
    public static function updateRole(Assessment $assessment, AssessmentUser $collaborator, string $role): void
    {
        self::assertBelongsTo($assessment, $collaborator);
        self::assertNotOwner($assessment, $collaborator);

        $previous = $collaborator->role;
        $collaborator->role = $role;
        $collaborator->save();

        SecurityLog::record(SecurityEvent::CollaboratorRoleChanged, Auth::user(), SecurityLog::assessment($assessment), [
            'user_id' => $collaborator->user_id,
            'from' => $previous,
            'to' => $role,
        ]);
    }

    /** @throws \InvalidArgumentException if $collaborator's user is the assessment's owner. */
    public static function remove(Assessment $assessment, AssessmentUser $collaborator): void
    {
        self::assertBelongsTo($assessment, $collaborator);
        self::assertNotOwner($assessment, $collaborator);

        $collaborator->delete();

        SecurityLog::record(SecurityEvent::CollaboratorRemoved, Auth::user(), SecurityLog::assessment($assessment), [
            'user_id' => $collaborator->user_id,
            'role' => $collaborator->role,
        ]);
    }

    private static function assertBelongsTo(Assessment $assessment, AssessmentUser $collaborator): void
    {
        if ($collaborator->assessment_id !== $assessment->id) {
            throw new \InvalidArgumentException('Collaborator does not belong to this assessment.');
        }
    }

    private static function assertNotOwner(Assessment $assessment, AssessmentUser $collaborator): void
    {
        if ($collaborator->user_id === $assessment->owner_user_id) {
            throw new \InvalidArgumentException('The owner cannot be demoted or removed as a collaborator.');
        }
    }
}
