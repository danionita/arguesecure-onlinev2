<?php

namespace App\Models;

use App\Policies\NodePolicy;
use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Single-table-inheritance base for the `nodes` table (schema.md §3.4, §4).
 *
 * Node itself carries NO type scope, unlike its four subclasses (RootNode,
 * Risk, Attack, Defence). That is deliberate: polymorphic relations that
 * point at "a node" (a comment's node, a revision's node, a tag's nodes)
 * must resolve to the correct subclass regardless of type, and a scope here
 * would silently exclude three of every four rows.
 *
 * @property int $id
 * @property string $uuid
 * @property int $assessment_id
 * @property string $type
 * @property ?string $title
 * @property ?string $description
 * @property int $position
 * @property ?string $impact_mode
 * @property ?float $impact_amount
 * @property ?int $impact_level_id
 * @property ?string $likelihood_mode
 * @property ?float $likelihood_aro
 * @property ?int $likelihood_level_id
 * @property bool $is_transfer
 * @property ?string $status
 * @property ?float $capex_amount
 * @property ?float $opex_annual_amount
 * @property ?int $created_by
 * @property ?int $updated_by
 */
#[UsePolicy(NodePolicy::class)]
class Node extends Model
{
    /** @use HasFactory<NodeFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Explicit, not inferred: Eloquent's default table-name guess is based
     * on the *runtime* class (Str::pluralStudly(class_basename($this))), so
     * without this every STI subclass would silently query its own guessed
     * table — `risks`, `attacks`, `defences`, `root_nodes` — instead of the
     * single shared `nodes` table (schema.md §3.4). Declared once here;
     * Risk/Attack/Defence/RootNode inherit it unmodified.
     */
    protected $table = 'nodes';

    /**
     * Maps the `type` column to its STI subclass for newFromBuilder(). This is
     * the only place a node type resolves to a class, and it is a hardcoded
     * map, never class_exists()/reflection on request input — see
     * security.md §7 defect #7.
     *
     * @var array<string, class-string<Node>>
     */
    protected static array $typeMap = [
        'root' => RootNode::class,
        'risk' => Risk::class,
        'attack' => Attack::class,
        'defence' => Defence::class,
    ];

    /**
     * assessment_id, type, uuid, created_by and updated_by are deliberately
     * absent: they are identity/audit columns set by services, never by
     * mass assignment from request input (security.md §7).
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'position',
        'impact_mode',
        'impact_amount',
        'impact_level_id',
        'likelihood_mode',
        'likelihood_aro',
        'likelihood_level_id',
        'is_transfer',
        'status',
        'capex_amount',
        'opex_annual_amount',
    ];

    protected static function booted(): void
    {
        static::creating(function (Node $node): void {
            $node->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * DECIMAL columns arrive from PDO as strings (schema.md §2); cast
     * explicitly to float here so nothing downstream — least of all the
     * calculation engine — ever sees a numeric string.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'impact_amount' => 'float',
            'likelihood_aro' => 'float',
            'is_transfer' => 'boolean',
            'capex_amount' => 'float',
            'opex_annual_amount' => 'float',
        ];
    }

    /**
     * Instantiates the STI subclass mapped from the `type` column instead of
     * always building a base Node, so every polymorphic-by-foreign-key
     * relation resolves to Risk/Attack/Defence/RootNode automatically.
     *
     * This deliberately does not return `static`: hydrating a `risk` row
     * while querying through `Node` (e.g. `Node::withoutGlobalScopes()`)
     * must yield a `Risk`, not a `Node`, which is exactly what breaks the
     * `static` contract the parent declares. `Node` is the honest common
     * return type for every row this method can ever hydrate.
     *
     * @param  array<string, mixed>  $attributes
     * @param  \UnitEnum|string|null  $connection
     */
    public function newFromBuilder($attributes = [], $connection = null): Node
    {
        $attributes = (array) $attributes;
        $class = static::$typeMap[$attributes['type'] ?? null] ?? static::class;

        $model = new $class;
        $model->exists = true;
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());
        $model->fireModelEvent('retrieved', false);

        return $model;
    }

    /** @return NodeFactory<Node> */
    public static function newFactory(): NodeFactory
    {
        return NodeFactory::new();
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

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
     * Every relation below names `node_id` explicitly, and must keep doing so.
     * Eloquent otherwise guesses the key from the *runtime* class name, and
     * newFromBuilder() above hydrates every row as an STI subclass — so the
     * guess was `risk_id`, `attack_id`, `defence_id`: columns that do not
     * exist. Lazy-loading any of these on a node you already had failed as a
     * hard SQL error, not an empty result.
     *
     * Eager-loading from Node::query() masked it, because that builder's model
     * is a plain `new Node` whose guess is correct — which is why the graph
     * loader and the report never tripped over it. NodeRelationKeysTest pins
     * both paths.
     */

    /**
     * Top-level comments only; replies come off Comment::replies().
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'node_id')->whereNull('parent_comment_id');
    }

    /** @return HasMany<NodeRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(NodeRevision::class, 'node_id')->orderByDesc('id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'node_tag', 'node_id', 'tag_id');
    }

    /** @return HasMany<NodeReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(NodeReference::class, 'node_id')->orderBy('position');
    }

    /**
     * Who is responsible for this node (W46, item 16).
     *
     * Ordered by name so the modal, the dashboard and the report list the
     * same people in the same order — a set with no order reads as a
     * different set each time it is rendered.
     *
     * An assignment grants nothing. Every ability still comes from the
     * assignee's collaborator role via AssessmentPolicy, and there is no
     * node-scoped grant anywhere in this codebase; NodePolicy delegates
     * upward without ever reading a node's own attributes, and this relation
     * does not change that.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'node_assignees', 'node_id', 'user_id')
            ->orderBy('users.name');
    }
}
