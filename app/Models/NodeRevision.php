<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable per-field change log (schema.md §3.9). Always written by
 * History\RevisionRecorder (a later package), never by mass assignment from
 * a request, hence no $fillable restriction is meaningful here — the model
 * is not exposed to user input at all.
 *
 * The annotations below were added by W48, which reads this table for the
 * history timeline: without them PHPStan infers the cast columns from the
 * schema — `changes` as a string, `created_at` as a string — and every reader
 * has to work around casts this class already declares. Every other model in
 * this codebase carries the same block.
 *
 * @property int $id
 * @property int $assessment_id
 * @property ?int $node_id
 * @property string $node_uuid
 * @property ?int $user_id
 * @property string $action
 * @property array<string, mixed> $changes
 * @property ?int $operation_id
 * @property ?Carbon $created_at
 * @property ?User $user
 */
class NodeRevision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AssessmentOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(AssessmentOperation::class, 'operation_id');
    }
}
