<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of the stored security event log (W128). Written only by
 * SecurityEventStoreHandler; no column is mass-assignable.
 *
 * @property int $id
 * @property Carbon $created_at
 * @property string $level
 * @property string $event
 * @property int|null $actor_id
 * @property string|null $actor_email
 * @property array<string, mixed>|null $target
 * @property string|null $ip
 * @property array<string, mixed>|null $details
 */
class SecurityEventRecord extends Model
{
    protected $table = 'security_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'target' => 'array',
            'details' => 'array',
        ];
    }
}
