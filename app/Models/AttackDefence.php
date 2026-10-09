<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The attack_defence edge. Strength lives here, not on the defence, because
 * the same control may be 80% effective against one attack and 20% against
 * another (schema.md §3.6).
 */
/**
 * @property int $id
 * @property int $attack_node_id
 * @property int $defence_node_id
 * @property int $position
 * @property ?string $strength_mode
 * @property ?float $strength_fraction
 * @property ?int $strength_level_id
 * @property ?string $reduction_target
 * @property ?string $rationale
 */
class AttackDefence extends Pivot
{
    protected $table = 'attack_defence';

    public $incrementing = true;

    protected $fillable = [
        'position',
        'strength_mode',
        'strength_fraction',
        'strength_level_id',
        'reduction_target',
        'rationale',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            // DECIMAL(7,6) arrives from PDO as a string; cast to float per schema.md §2.
            'strength_fraction' => 'float',
        ];
    }

    /** @return BelongsTo<Attack, $this> */
    public function attack(): BelongsTo
    {
        return $this->belongsTo(Attack::class, 'attack_node_id');
    }

    /** @return BelongsTo<Defence, $this> */
    public function defence(): BelongsTo
    {
        return $this->belongsTo(Defence::class, 'defence_node_id');
    }

    /** @return BelongsTo<ScaleLevel, $this> */
    public function strengthLevel(): BelongsTo
    {
        return $this->belongsTo(ScaleLevel::class, 'strength_level_id');
    }

    /**
     * Resolves the link's strength to a float in [0,1], honouring the
     * missing-value semantics of calculation-engine.md §6.1: null iff mode is
     * null, or the mode-appropriate column is null. Never coerces to 0 here —
     * that coercion is the calculation engine's job, not the model's.
     */
    public function strengthFraction(): ?float
    {
        return match ($this->strength_mode) {
            'quantitative' => $this->strength_fraction,
            'qualitative' => $this->strengthLevel?->representative,
            default => null,
        };
    }

    /** NULL = combined loss, the default (calculation-engine.md §5). */
    public function effectiveTarget(): ?string
    {
        return $this->reduction_target;
    }
}
