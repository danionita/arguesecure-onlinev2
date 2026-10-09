<?php

namespace App\Models;

use App\Policies\NodePolicy;
use Database\Factories\RiskFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * PHP attributes are not inherited by reflection, so `Node`'s
 * `#[UsePolicy(NodePolicy::class)]` does not automatically apply to this
 * subclass — Laravel's Gate resolves a policy per concrete class, and
 * without this every ability check against a Risk would silently find no
 * policy and deny by default.
 */
#[UsePolicy(NodePolicy::class)]
class Risk extends Node
{
    public const TYPE = 'risk';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('type', function (Builder $builder): void {
            $builder->where('nodes.type', self::TYPE);
        });

        static::creating(function (Node $node): void {
            $node->type = self::TYPE;
        });
    }

    public static function newFactory(): RiskFactory
    {
        return RiskFactory::new();
    }

    /** @return BelongsToMany<Attack, $this, RiskAttack, 'pivot'> */
    public function attacks(): BelongsToMany
    {
        return $this->belongsToMany(Attack::class, 'risk_attack', 'risk_node_id', 'attack_node_id')
            ->using(RiskAttack::class)
            ->withPivot('id', 'position')
            ->withTimestamps()
            ->orderBy('risk_attack.position');
    }

    /** @return BelongsTo<ScaleLevel, $this> */
    public function impactLevel(): BelongsTo
    {
        return $this->belongsTo(ScaleLevel::class, 'impact_level_id');
    }
}
