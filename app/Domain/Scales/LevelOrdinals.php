<?php

namespace App\Domain\Scales;

use App\Models\Scale;
use App\Models\ScaleLevel;
use Illuminate\Support\Facades\DB;

/**
 * Moves a scale's existing ordinals out of the way before a calibration
 * rewrites them (W87).
 *
 * ## The collision this exists to prevent
 *
 * `scale_levels_ordinal_unique (scale_id, ordinal)` means ordinals cannot be
 * renumbered one row at a time. Removing the lowest band renumbers every band
 * above it downwards, and the very first of those writes — the old band 2
 * becoming band 1 — collides with the band 1 that has not been deleted yet.
 * The save dies on a duplicate-key error partway through, on a database
 * constraint the user cannot see and did not cause.
 *
 * It was reachable from both editors and from neither's first save, which is
 * why it survived W65: an assessment's *first* calibration clones the scale,
 * so the new scale starts with no levels and nothing has to be renumbered.
 * Only a second edit that removes or reorders a band reaches it — and the
 * admin Defaults tab, which never clones, reaches it on its first.
 *
 * ## Parking, not swapping
 *
 * Every existing ordinal is pushed up by 100 in one statement, so the whole
 * 1..n range is free before any final value is written. `ordinal` is an
 * `unsignedTinyInteger` and `bands` is capped at 12 by both editors, so 101-112
 * cannot overflow 255. A pairwise swap would need a correct permutation and
 * would still collide on a cycle; one bulk shift has neither problem.
 *
 * ## `syncOriginal()` is not incidental
 *
 * The models the caller holds were loaded before the shift, so Eloquent still
 * believes their ordinal is the old one. A band whose final ordinal happens to
 * equal that old value would then look *unchanged*, `save()` would write
 * nothing, and the row would keep its parked 10x ordinal — a scale whose bands
 * are ordered 101, 2, 3 renders in an order nobody chose. So the in-memory
 * models are moved with the rows and re-synced.
 */
final class LevelOrdinals
{
    /** Clear of any legal ordinal, and clear of 255 for the 12 bands an editor allows. */
    private const PARK_OFFSET = 100;

    /**
     * @param  array<string, ScaleLevel>  $levels  the scale's current levels, as the caller holds them
     */
    public static function park(Scale $scale, array $levels): void
    {
        if ($levels === []) {
            return;
        }

        DB::table('scale_levels')
            ->where('scale_id', $scale->id)
            ->update(['ordinal' => DB::raw('ordinal + '.self::PARK_OFFSET)]);

        foreach ($levels as $level) {
            $level->ordinal = (int) $level->ordinal + self::PARK_OFFSET;
            $level->syncOriginal();
        }
    }
}
