<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Assessment-scoped, a deliberate change from the legacy global tags table
 * (schema.md §3.11): one user's rename used to hit everyone.
 *
 * @property int $id
 * @property int $assessment_id
 * @property string $name
 * @property string $slug
 * @property ?string $color
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'color',
    ];

    public static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsToMany<Node, $this> */
    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'node_tag');
    }
}
