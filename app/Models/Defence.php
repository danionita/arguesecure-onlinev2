<?php

namespace App\Models;

use App\Policies\NodePolicy;
use Database\Factories\DefenceFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Transfer is a Defence with is_transfer = 1 — a presentation variant,
 * not a database type (CLAUDE.md, schema.md §1).
 *
 * @property-read AttackDefence $pivot present only when this instance was
 *  loaded through Attack::defences(); absent otherwise.
 *
 * PHP attributes are not inherited by reflection, so `Node`'s
 * `#[UsePolicy(NodePolicy::class)]` must be repeated on every concrete
 * subclass — see Risk for the full explanation.
 */
#[UsePolicy(NodePolicy::class)]
class Defence extends Node
{
    public const TYPE = 'defence';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('type', function (Builder $builder): void {
            $builder->where('nodes.type', self::TYPE);
        });

        static::creating(function (Node $node): void {
            $node->type = self::TYPE;
            $node->status ??= 'todo';
        });
    }

    public static function newFactory(): DefenceFactory
    {
        return DefenceFactory::new();
    }

    /** @return BelongsToMany<Attack, $this, AttackDefence, 'pivot'> */
    public function attacks(): BelongsToMany
    {
        return $this->belongsToMany(Attack::class, 'attack_defence', 'defence_node_id', 'attack_node_id')
            ->using(AttackDefence::class)
            ->withPivot('id', 'position', 'strength_mode', 'strength_fraction', 'strength_level_id', 'reduction_target', 'rationale')
            ->withTimestamps()
            ->orderBy('attack_defence.position');
    }
}
