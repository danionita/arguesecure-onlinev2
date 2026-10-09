<?php

namespace App\Domain\Scales;

use App\Models\Scale;

/**
 * What a calibration actually did (W65).
 *
 * Returned rather than logged, for the same reason `RestoreOutcome` is: the
 * two things that can go *quietly* wrong here are invisible in the result
 * otherwise, and both need saying on the page.
 *
 * - `cloned` — the assessment was using a shared system scale and now has its
 *   own copy. Worth stating, because it is the moment this assessment's
 *   numbers stop tracking the system default, and someone who expected to be
 *   editing the default for everyone needs to find out here rather than later.
 * - `clearedValues` — bands were removed, and values that pointed at them have
 *   been unset. Those risks and attacks now have no impact or likelihood, drop
 *   out of the total, and need re-entering. Silently dropping them would
 *   understate risk, which is the one direction this codebase never rounds in.
 */
final readonly class CalibrationOutcome
{
    public function __construct(
        public Scale $scale,
        public bool $cloned,
        public int $clearedValues,
    ) {}
}
