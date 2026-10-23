<?php

namespace App\Domain\Sharing;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moves `assessments.owner_user_id` (security.md §3.1: only the owner or
 * an admin may transfer ownership — enforced by
 * `AssessmentPolicy::transferOwnership()`, checked in
 * `TransferOwnershipRequest`, never re-derived here).
 *
 * Judgement call: the transfer target must already be a collaborator
 * (any role) on the assessment. The brief only says "transfer
 * ownership," not who may receive it, but allowing a transfer to an
 * arbitrary searched-up user would hand full control of a confidential
 * assessment (security.md §12) to someone who has never seen it and may
 * not know the transfer happened — there is no notification system
 * (CLAUDE.md: no SMTP dependency) to tell them. Requiring prior
 * membership means the recipient already has a relationship to the
 * assessment and the transferring owner already made a deliberate
 * decision to share with them.
 */
class OwnershipTransferService
{
    /**
     * @throws \InvalidArgumentException if $newOwner is already the owner
     *                                   or is not currently a collaborator on the assessment.
     */
    public static function transfer(Assessment $assessment, User $newOwner): void
    {
        if ($newOwner->id === $assessment->owner_user_id) {
            throw new \InvalidArgumentException('That user already owns this assessment.');
        }

        $membership = $assessment->members()->where('users.id', $newOwner->id)->exists();

        if (! $membership) {
            throw new \InvalidArgumentException('Ownership can only be transferred to an existing collaborator.');
        }

        $previousOwnerId = DB::transaction(function () use ($assessment, $newOwner) {
            $previousOwnerId = $assessment->owner_user_id;

            // The incoming owner is no longer an ordinary collaborator —
            // ownership is tracked solely via owner_user_id (schema.md
            // §3.7), so leaving their old assessment_user row in place
            // would create two conflicting sources of truth for the same
            // person.
            $assessment->members()->detach($newOwner->id);

            $assessment->owner_user_id = $newOwner->id;
            $assessment->save();

            // The outgoing owner keeps access rather than being silently
            // locked out of work they own the history of — added as
            // 'manager', the highest non-owner role, unless they already
            // hold a row for some other reason.
            $alreadyMember = $assessment->members()->where('users.id', $previousOwnerId)->exists();

            if (! $alreadyMember) {
                $assessment->members()->attach($previousOwnerId, [
                    'role' => 'manager',
                    'invited_by' => $newOwner->id,
                    'invited_at' => Carbon::now(),
                    'accepted_at' => Carbon::now(),
                ]);
            }

            return $previousOwnerId;
        });

        // After the commit: a rolled-back transfer must not be in the log.
        SecurityLog::record(SecurityEvent::OwnershipTransferred, Auth::user(), SecurityLog::assessment($assessment), [
            'from_user_id' => $previousOwnerId,
            'to_user_id' => $newOwner->id,
        ]);
    }
}
