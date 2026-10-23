<?php

namespace App\Models;

use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Owns everything. Deliberately has no `title` column — the title and
 * description live on the root node (schema.md §3.3); listings join
 * `nodes` on `type = 'root'`. `title`/`description` below are read-only
 * accessors proxying that node, never real columns.
 *
 * @property ?string $scale_set_key which admin-defined scale the bands were last
 *                                  set to (W126); NULL is Custom. A label, not an
 *                                  input to any calculation, and assigned as a
 *                                  property by `AssessmentScaleApplier` only.
 */
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * uuid, owner_user_id, is_template (which the database ties to
     * owner_user_id being null, W141), share_token(_created_at) and
     * calc_version are deliberately absent — identity, ownership-transfer and cache-key
     * columns set only by services (security.md §7).
     *
     * @var list<string>
     */
    protected $fillable = [
        'currency',
        'display_mode',
        'impact_scale_id',
        'likelihood_scale_id',
        'strength_scale_id',
        'amortization_years',
        // W44. Display only (PLAN-2.2.md D14): a threshold the report and the
        // ToS card draw a marker against. It never reaches RiskCalculator,
        // and it must never band-map a computed figure into low/medium/high,
        // which calculation-engine.md forbids outright.
        'risk_tolerance',
        'is_public',
    ];

    /**
     * Mirrors Node's own hook. `assessments.uuid` has no database default,
     * and until W26 the only things that created an assessment were
     * AssessmentFactory and AssessmentCloner, both of which set it
     * themselves — so the gap was invisible until a real creation path
     * existed and died on "Field 'uuid' doesn't have a default value".
     *
     * `??=` so the factory and the cloner keep assigning their own.
     */
    protected static function booted(): void
    {
        static::creating(function (Assessment $assessment): void {
            $assessment->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'amortization_years' => 'integer',
            // Float, not 'decimal:2': every other money value in this
            // application reaches the client as a JSON number, and casting
            // this one to a decimal string would make it the single figure
            // the front end had to parse before comparing it to a total.
            'risk_tolerance' => 'float',
            'is_public' => 'boolean',
            'is_template' => 'boolean',
            // W54. Deliberately absent from $fillable, along with
            // summary_narrative and summary_draft: the narrative is written
            // through one controller that assigns it as a property, and the
            // draft is written only by a queued job. Neither should be
            // reachable from an array of request input.
            'summary_narrative_generated' => 'boolean',
            'calc_version' => 'integer',
            'share_token_created_at' => 'datetime',
        ];
    }

    public static function newFactory(): AssessmentFactory
    {
        return AssessmentFactory::new();
    }

    /** @return HasOne<RootNode, $this> */
    public function rootNode(): HasOne
    {
        return $this->hasOne(RootNode::class, 'assessment_id');
    }

    /** @return HasMany<Node, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /** @return HasMany<Risk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'assessment_id')->orderBy('position');
    }

    /** @return HasMany<Attack, $this> */
    public function attacks(): HasMany
    {
        return $this->hasMany(Attack::class, 'assessment_id')->orderBy('position');
    }

    /** @return HasMany<Defence, $this> */
    public function defences(): HasMany
    {
        return $this->hasMany(Defence::class, 'assessment_id')->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsToMany<User, $this, AssessmentUser, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'assessment_user')
            ->using(AssessmentUser::class)
            ->withPivot('role', 'invited_by', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }

    /** @return BelongsTo<Scale, $this> */
    public function impactScale(): BelongsTo
    {
        return $this->belongsTo(Scale::class, 'impact_scale_id');
    }

    /** @return BelongsTo<Scale, $this> */
    public function likelihoodScale(): BelongsTo
    {
        return $this->belongsTo(Scale::class, 'likelihood_scale_id');
    }

    /** @return BelongsTo<Scale, $this> */
    public function strengthScale(): BelongsTo
    {
        return $this->belongsTo(Scale::class, 'strength_scale_id');
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** @return HasMany<Snapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class)->orderByDesc('created_at');
    }

    /** @return HasMany<AssessmentOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(AssessmentOperation::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return Attribute<?string, never> */
    protected function title(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->rootNode?->title);
    }

    /** @return Attribute<?string, never> */
    protected function description(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->rootNode?->description);
    }
}
