<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The risk_attack edge. Carries only ordering — the strength/mode/rationale
 * columns live on AttackDefence instead, because a defence's strength
 * varies per attack while an attack's membership in a risk does not.
 */
/**
 * @property int $id
 * @property int $risk_node_id
 * @property int $attack_node_id
 * @property int $position
 */
class RiskAttack extends Pivot
{
    protected $table = 'risk_attack';

    public $incrementing = true;

    protected $fillable = [
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Risk, $this> */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'risk_node_id');
    }

    /** @return BelongsTo<Attack, $this> */
    public function attack(): BelongsTo
    {
        return $this->belongsTo(Attack::class, 'attack_node_id');
    }
}
