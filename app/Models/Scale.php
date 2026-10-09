<?php

namespace App\Models;

use Database\Factories\ScaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of three kinds — impact, likelihood, strength (schema.md §3.2).
 * `assessment_id` NULL means a shared, read-only system template; a
 * non-null value means an assessment-owned, editable calibration.
 *
 * @property int $id
 * @property ?int $assessment_id
 * @property string $kind
 * @property string $name
 * @property string $unit
 * @property bool $is_system
 * @property ?string $set_key which shared system set this scale belongs to
 *                            (W77); NULL on every assessment-owned scale.
 *                            Deliberately absent from $fillable — see below.
 */
class Scale extends Model
{
    /** @use HasFactory<ScaleFactory> */
    use HasFactory;

    /**
     * assessment_id is deliberately absent: which assessment (if any) owns a
     * scale is a service decision (AssessmentCloner, scale customisation),
     * never a value taken directly from request input. `set_key` is absent
     * for the same reason — it is the grouping identity of a shared system
     * set, written only by SystemScaleSeeder and assigned as a property, so
     * that a `firstOrCreate()` carrying it fails loudly at review rather than
     * having Eloquent drop it and write a set-less trio (CLAUDE.md).
     *
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'name',
        'unit',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public static function newFactory(): ScaleFactory
    {
        return ScaleFactory::new();
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return HasMany<ScaleLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(ScaleLevel::class)->orderBy('ordinal');
    }
}
