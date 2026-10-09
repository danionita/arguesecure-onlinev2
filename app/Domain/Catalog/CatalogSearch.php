<?php

namespace App\Domain\Catalog;

use App\Models\CatalogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The catalogue browser's query (schema.md §3.14, PLAN.md Phase 8 item 4).
 * Visible entries are the system catalogue (owner_user_id IS NULL), any
 * entry marked `is_public`, and the caller's own private entries — the
 * same "system default vs owned, editable" shape Scale uses. Browsing
 * needs only an authenticated user (CLAUDE.md's W20 brief); no assessment
 * is involved here at all.
 */
class CatalogSearch
{
    /** @return Collection<int, CatalogEntry> */
    public function search(?User $user, ?string $kind = null, ?string $query = null): Collection
    {
        return CatalogEntry::query()
            ->where(function ($visibility) use ($user): void {
                $visibility->whereNull('owner_user_id')->orWhere('is_public', true);

                if ($user !== null) {
                    $visibility->orWhere('owner_user_id', $user->id);
                }
            })
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($query !== null && $query !== '', function ($q) use ($query): void {
                $q->where(function ($text) use ($query): void {
                    $text->where('title', 'like', '%'.$query.'%')
                        ->orWhere('description', 'like', '%'.$query.'%');
                });
            })
            ->withCount('catalogChildren')
            ->orderBy('title')
            ->get();
    }
}
