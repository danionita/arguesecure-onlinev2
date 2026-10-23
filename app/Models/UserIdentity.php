<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account's link to the identity provider (W117, D76): the provider's
 * issuer and its stable `sub` for this person. No column is fillable: the
 * identity columns are assigned as properties (.claude/rules/identity-columns.md).
 *
 * @property int $id
 * @property int $user_id
 * @property string $issuer
 * @property string $subject
 * @property Carbon|null $last_used_at
 */
class UserIdentity extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
