<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The name of one admin-defined scale (W126, D93): a complete impact,
 * likelihood and strength calibration. The three `scales` rows it names are
 * found by `scales.set_key = key`; there is deliberately no foreign key.
 *
 * `key`, `is_builtin` and `position` are identity-like and assigned as
 * properties by `ScaleSetManager` and the seeder, never mass-assigned.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_builtin
 * @property int $position
 */
class ScaleSet extends Model
{
    /** @var list<string> */
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['is_builtin' => 'boolean', 'position' => 'integer'];
    }
}
