<?php

namespace App\Domain\Scales;

use App\Models\Scale;

/**
 * Turns a band's label into its `scale_levels.code` (W87, PLAN-2.5.md D44).
 *
 * ## Why a code still exists at all
 *
 * `code` left the interface in W87 and stayed in the machinery, and those are
 * two different things. It is the `(scale_id, code)` unique key; it is
 * `impact_level_code`/`aro_level_code` in calculation-engine.md §8.3's
 * normative payload; `AssessmentCloner::resolveLevelDefault()` and
 * `CatalogInserter::resolveLevelDefault()` both look a level up by it; and
 * `ScaleCalibrator::repoint()` matches bands by it across a clone.
 *
 * An id cannot do any of that. A custom scale is cloned *per assessment* by
 * `ScaleCalibrator::cloneForAssessment()`, so the row id of "major" is
 * different in every assessment and does not exist until the clone runs. A
 * catalogue fixture is written once and has to resolve in all of them, which
 * is why `catalog-iso27001-27002.json` says `"impact_level_code": "major"`.
 *
 * ## Derive on create, freeze on rename
 *
 * This class is called **only** for a band that has no code yet. Renaming a
 * band never reaches it. That is the whole point: re-deriving on rename would
 * silently detach every catalogue entry naming the old value, and it would
 * fail invisibly — the band still renders, the scale still validates, and the
 * only symptom is a template that quietly stops pre-filling a value months
 * later.
 *
 * ## Collisions are resolved here, not by the database
 *
 * `scale_levels_code_unique` would catch a duplicate, but as a 500 partway
 * through a save, on a value the user never typed and cannot see. Two bands
 * both labelled "Moderate" is an ordinary thing to do now that the label is
 * the only field there is, so the second one becomes `moderate_2`.
 */
final class LevelCodeDeriver
{
    /** `scale_levels.code` is `string(32)`. */
    public const MAX_LENGTH = 32;

    /**
     * Gives every band in a payload the code it will be stored under.
     *
     * Used by both editors — the assessment's three cards and the admin
     * Defaults tab — because both post the same band shape and neither one
     * asks for a code.
     *
     * ## Two passes, and the order is the point
     *
     * Carried codes are claimed first, then derived ones fill in around them.
     * Deriving in one pass would let a new band labelled "Moderate" take
     * `moderate` before the existing `moderate` band later in the list had
     * claimed it — and the calibrators key existing rows by code, so the new
     * band would adopt the old band's row and the old band would be deleted
     * along with every value pointing at it.
     *
     * ## An unrecognised code is treated as a new band, not an error
     *
     * A code naming no level on this scale cannot be a rename of anything. It
     * is either a stale form posting bands from a calibration that has since
     * been replaced, or a hand-made request. Deriving a fresh code for it is
     * the conservative reading: it adds a band rather than silently rebinding
     * whatever the string happened to name.
     *
     * ## The taken-set holds codes that are about to be deleted, too
     *
     * It starts as every code on the scale, including bands this save removes.
     * So deleting "Minor" and adding a band labelled "Minor" in the same save
     * produces `minor_2`, not `minor` — the new band does not inherit the
     * deleted one's row, its stored values, or the catalogue entries that
     * named it. Reusing the code would rebind all three to a band the user
     * thinks they have just created.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<array<string, mixed>>
     */
    public static function assign(Scale $scale, array $bands): array
    {
        /** @var list<string> $known */
        $known = $scale->levels()->pluck('code')->all();
        $lookup = array_flip($known);

        $taken = $known;
        $carried = [];

        foreach ($bands as $index => $band) {
            $code = $band['code'] ?? null;

            if (is_string($code) && isset($lookup[$code]) && ! in_array($code, $carried, true)) {
                $bands[$index]['code'] = $code;
                $carried[] = $code;

                continue;
            }

            // Nulled rather than left as it arrived, so the second pass cannot
            // mistake a rejected code for a claimed one.
            $bands[$index]['code'] = null;
        }

        foreach ($bands as $index => $band) {
            if ($bands[$index]['code'] !== null) {
                continue;
            }

            $code = self::derive(is_string($band['label'] ?? null) ? $band['label'] : '', $taken);
            $bands[$index]['code'] = $code;
            $taken[] = $code;
        }

        return $bands;
    }

    /**
     * The code for a label, avoiding every code in `$taken`.
     *
     * The result always matches `/^[a-z][a-z0-9_]*$/` — the shape
     * `ScaleCalibrationController` validates a carried code against, so a
     * derived code and a carried one are the same kind of string.
     *
     * @param  list<string>  $taken  every code already spoken for on this scale
     */
    public static function derive(string $label, array $taken): string
    {
        $base = self::slug($label);
        $lookup = array_flip($taken);

        if (! isset($lookup[$base])) {
            return $base;
        }

        // Starts at 2 because the collision is with something that is already
        // there: "moderate" and "moderate_2" reads as a first and a second,
        // where "moderate_1" would imply a "moderate_0" that does not exist.
        for ($n = 2; $n < 1000; $n++) {
            $suffix = '_'.$n;
            $candidate = self::truncate($base, self::MAX_LENGTH - strlen($suffix)).$suffix;

            if (! isset($lookup[$candidate])) {
                return $candidate;
            }
        }

        // A thousand bands with the same label on one scale is not a state any
        // interface can reach — `bands.*` is capped at 12 — so this is
        // unreachable rather than defensive. Falling back to something unique
        // beats returning a duplicate that dies on the unique index.
        return self::truncate($base, self::MAX_LENGTH - 11).'_'.substr((string) mt_rand(1000000000, 9999999999), 0, 10);
    }

    /**
     * The label reduced to the slug shape the column accepts.
     *
     * Non-ASCII is transliterated rather than dropped, so a scale calibrated
     * in a language with accents produces a legible key rather than a row of
     * underscores. `iconv` returns `false` on an input it cannot convert at
     * all, which is why the result is checked rather than used directly.
     */
    private static function slug(string $label): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $label);

        if ($ascii === false) {
            $ascii = $label;
        }

        $slug = strtolower($ascii);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');

        // The column's shape requires a leading letter, and both a label that
        // is entirely punctuation ("???") and one that starts with a digit
        // ("100k+") are things a person will type. `band` is a prefix rather
        // than a replacement so "100k+" stays recognisable as `band_100k`.
        if ($slug === '') {
            $slug = 'band';
        } elseif (! preg_match('/^[a-z]/', $slug)) {
            $slug = 'band_'.$slug;
        }

        return self::truncate($slug, self::MAX_LENGTH);
    }

    /**
     * Cuts to a length without leaving a trailing underscore, which would make
     * `severe_` and `severe__2` out of a label that only needed one.
     */
    private static function truncate(string $value, int $length): string
    {
        if ($length < 1) {
            return 'band';
        }

        $cut = rtrim(substr($value, 0, $length), '_');

        return $cut === '' ? 'band' : $cut;
    }
}
