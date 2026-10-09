<?php

namespace App\Models;

use App\Policies\NodePolicy;
use Database\Factories\RootNodeFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The single root per assessment (enforced by `nodes_root_unique` on the
 * generated `root_marker` column). Carries the assessment's title and
 * description — Assessment itself has no title column (schema.md §3.3).
 *
 * PHP attributes are not inherited by reflection, so `Node`'s
 * `#[UsePolicy(NodePolicy::class)]` must be repeated on every concrete
 * subclass — see Risk for the full explanation.
 */
#[UsePolicy(NodePolicy::class)]
class RootNode extends Node
{
    public const TYPE = 'root';

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

    public static function newFactory(): RootNodeFactory
    {
        return RootNodeFactory::new();
    }

    /**
     * Risks attach by assessment_id rather than a parent pointer: a risk
     * belongs to exactly one assessment and there is exactly one root per
     * assessment, so the join is unambiguous without a DAG edge.
     */
    /** @return HasMany<Risk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'assessment_id', 'assessment_id')->orderBy('position');
    }
}
