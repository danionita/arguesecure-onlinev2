<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One linear undo stack per assessment, not per user (schema.md §3.10).
 * Always written by History\UndoService (a later package); no $fillable
 * restriction is meaningful since the model is never mass-assigned from a
 * request.
 *
 * Annotations added by W48 for the same reason as NodeRevision's: this table
 * is read by the history timeline, and without them PHPStan infers the cast
 * columns as strings.
 *
 * @property int $id
 * @property int $assessment_id
 * @property ?int $user_id
 * @property string $kind
 * @property string $summary
 * @property array<string, mixed> $inverse
 * @property ?Carbon $undone_at
 * @property ?int $undone_by
 * @property ?Carbon $created_at
 * @property ?User $user
 */
class AssessmentOperation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'inverse' => 'array',
            'undone_at' => 'datetime',
            'created_at' => 'datetime',
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
    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    /** @return HasMany<NodeRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(NodeRevision::class, 'operation_id');
    }
}
