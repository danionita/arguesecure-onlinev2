<?php

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Threaded exactly two levels deep (schema.md §3.8): a top-level comment
 * plus replies. Comments attach to nodes only, never to links.
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * assessment_id, node_id, parent_comment_id and user_id are identity
     * columns set by the controller/service from the authenticated route
     * context, never from request input directly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'body',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id')->orderBy('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
