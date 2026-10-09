<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen graph + frozen results (schema.md §3.13). `results` is never
 * recomputed once written, and scales are inlined into `graph` so a later
 * recalibration cannot retroactively rewrite a snapshot's numbers.
 * Written by a later package's snapshot service; not mass-assigned from a
 * bare request body, so no $fillable restriction is meaningful.
 */
class Snapshot extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'schema_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
