<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The assessment_user edge (schema.md §3.1, security.md §3.1). Carries the
 * role ladder — viewer < commenter < editor < manager — that
 * AssessmentPolicy resolves membership access from. The owner is
 * assessments.owner_user_id, not a row here.
 *
 * @property int $id
 * @property int $assessment_id
 * @property int $user_id
 * @property string $role
 * @property ?int $invited_by
 * @property Carbon $invited_at
 * @property ?Carbon $accepted_at
 */
class AssessmentUser extends Pivot
{
    protected $table = 'assessment_user';

    public $incrementing = true;

    /**
     * Removing a collaborator removes their assignments (W46).
     *
     * This is the decision the brief asked to be made explicitly rather than
     * left to whatever happened. The alternative — orphaning them — keeps a
     * row saying "assigned to Dana" on a node Dana can no longer open. Either
     * the dashboard then shows her work she cannot reach, or it filters her
     * out and the assessment goes on naming her indefinitely to everyone
     * else. Both are worse than the assignment visibly disappearing, which is
     * at least true and noticeable at the moment someone's access is revoked.
     *
     * A *role change* is a different event and deliberately does not fire
     * this: a viewer can still be the person responsible for a node. That is
     * why the hook is on `deleted` rather than on `saved`.
     *
     * It lives on the model rather than in `CollaboratorService::remove()`
     * for two reasons — that class belongs to W9, and a hook here fires
     * however the row is deleted, including from a future bulk path that
     * never learns to call the service.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $membership): void {
            DB::table('node_assignees')
                ->where('user_id', $membership->user_id)
                ->whereIn('node_id', Node::query()
                    ->withTrashed()
                    ->where('assessment_id', $membership->assessment_id)
                    ->select('id'))
                ->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
