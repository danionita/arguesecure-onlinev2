<?php

namespace App\Models;

use Database\Factories\CatalogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A reusable library item (schema.md §3.14). `defaults` stores scale level
 * *codes*, never ids, so an entry resolves correctly against any
 * assessment's calibration at insert time — resolution is
 * Structure\AssessmentCloner's job, not this model's.
 */
class CatalogEntry extends Model
{
    /** @use HasFactory<CatalogEntryFactory> */
    use HasFactory;

    /**
     * owner_user_id is deliberately absent — NULL (system catalogue) vs a
     * specific user is a service decision, not request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'title',
        'description',
        'defaults',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'defaults' => 'array',
            'is_public' => 'boolean',
        ];
    }

    public static function newFactory(): CatalogEntryFactory
    {
        return CatalogEntryFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Bundle children — e.g. a "Phishing" entry shipping with MFA pre-linked.
     *
     * @return BelongsToMany<self, $this>
     */
    public function catalogChildren(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'catalog_entry_links', 'parent_entry_id', 'child_entry_id')
            ->withPivot('defaults')
            ->withTimestamps();
    }

    /** @return BelongsToMany<self, $this> */
    public function catalogParents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'catalog_entry_links', 'child_entry_id', 'parent_entry_id')
            ->withPivot('defaults')
            ->withTimestamps();
    }
}
