<?php

namespace App\Models;

use Database\Factories\NodeReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CVE and friends (schema.md §3.12). identifier/url are validated and the
 * url is derived from the identifier, never taken from input, except for
 * kind = 'url' — that validation belongs to the Form Request layer, not
 * this model (security.md §7).
 */
class NodeReference extends Model
{
    /** @use HasFactory<NodeReferenceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'kind',
        'identifier',
        'url',
        'title',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public static function newFactory(): NodeReferenceFactory
    {
        return NodeReferenceFactory::new();
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
