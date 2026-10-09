<?php

namespace App\Models;

use App\Policies\NodePolicy;
use Database\Factories\AttackFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read RiskAttack|AttackDefence $pivot present only when this
 *  instance was loaded through Risk::attacks() (RiskAttack) or
 *  Defence::attacks() (AttackDefence); absent otherwise.
 *
 * PHP attributes are not inherited by reflection, so `Node`'s
 * `#[UsePolicy(NodePolicy::class)]` must be repeated on every concrete
 * subclass — see Risk for the full explanation.
 */
#[UsePolicy(NodePolicy::class)]
class Attack extends Node
{
    public const TYPE = 'attack';

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

    public static function newFactory(): AttackFactory
    {
        return AttackFactory::new();
    }

    /** @return BelongsToMany<Risk, $this, RiskAttack, 'pivot'> */
    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'risk_attack', 'attack_node_id', 'risk_node_id')
            ->using(RiskAttack::class)
            ->withPivot('id', 'position')
            ->withTimestamps()
            ->orderBy('risk_attack.position');
    }

    /** @return BelongsToMany<Defence, $this, AttackDefence, 'pivot'> */
    public function defences(): BelongsToMany
    {
        return $this->belongsToMany(Defence::class, 'attack_defence', 'attack_node_id', 'defence_node_id')
            ->using(AttackDefence::class)
            ->withPivot('id', 'position', 'strength_mode', 'strength_fraction', 'strength_level_id', 'reduction_target', 'rationale')
            ->withTimestamps()
            ->orderBy('attack_defence.position');
    }

    /** @return BelongsTo<ScaleLevel, $this> */
    public function likelihoodLevel(): BelongsTo
    {
        return $this->belongsTo(ScaleLevel::class, 'likelihood_level_id');
    }
}
