<?php

namespace App\Models;

use Database\Factories\ScaleLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One band of a Scale. Invariants (contiguity, zero start, open top band,
 * lower <= representative < upper) are enforced by ScaleValidator on save,
 * not by the database — see schema.md §3.2.
 *
 * @property int $id
 * @property int $scale_id
 * @property int $ordinal
 * @property string $code
 * @property string $label
 * @property ?string $color
 * @property float $lower_bound
 * @property ?float $upper_bound
 * @property float $representative
 */
class ScaleLevel extends Model
{
    /** @use HasFactory<ScaleLevelFactory> */
    use HasFactory;

    /**
     * scale_id is deliberately absent — a level belongs to whichever Scale
     * created it via the levels() relation, never to a scale chosen by
     * request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ordinal',
        'code',
        'label',
        'color',
        'lower_bound',
        'upper_bound',
        'representative',
    ];

    /** DECIMAL(20,6) columns arrive from PDO as strings; cast to float (schema.md §2). */
    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'lower_bound' => 'float',
            'upper_bound' => 'float',
            'representative' => 'float',
        ];
    }

    public static function newFactory(): ScaleLevelFactory
    {
        return ScaleLevelFactory::new();
    }

    /** @return BelongsTo<Scale, $this> */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }
}
