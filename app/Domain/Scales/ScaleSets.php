<?php

namespace App\Domain\Scales;

use App\Models\Scale;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use RuntimeException;

/**
 * The catalogue of shared system scale *sets* (W77, PLAN-2.4.md D36).
 *
 * A set is a trio of `is_system = 1, assessment_id IS NULL` scales — one
 * impact, one likelihood, one strength — sharing a `set_key`. Before W77 the
 * trio existed but the key did not, so there was no way to have two of them
 * and no way for `instance_settings.default_scale_calibration` to name one.
 *
 * Three things live here rather than in the seeder, the form request or
 * AssessmentCreator, because all three need them and none of them may
 * disagree with the others:
 *
 * - the **keys**, which the seeder writes and the settings form validates
 *   against;
 * - the **labels**, which only the settings form renders;
 * - **resolution**, which turns a key into the three scale ids an assessment
 *   is created with.
 *
 * ## Why labels are a constant and not a column
 *
 * A set is not a row, so a `label` column would have to be repeated on three
 * rows that could then disagree. A deployment that seeds its own set under an
 * unknown key gets the key itself as its label — legible, and better than
 * either a blank option or a fourth place for the name to drift.
 */
final class ScaleSets
{
    /**
     * The set every instance has.
     *
     * This is the value the W77 migration backfilled every pre-existing
     * system scale to, the value `instance_settings.default_scale_calibration`
     * defaults to, and the set AssessmentCreator falls back to when the
     * configured one has been removed. Removing it from the seeder without
     * changing that fallback would leave a deployment unable to create an
     * assessment at all.
     */
    public const DEFAULT_KEY = 'default';

    /**
     * A second, genuinely different calibration — not a copy of the first.
     *
     * D36 notes that a select with one option is the same non-control in a
     * different shape, which is the whole reason a second set had to exist
     * before the setting could mean anything.
     */
    public const SMALL_ORGANISATION_KEY = 'small-organisation';

    /** The three kinds a complete set has exactly one of (schema.md §3.2). */
    public const KINDS = ['impact', 'likelihood', 'strength'];

    /** @var array<string, string> */
    private const LABELS = [
        self::DEFAULT_KEY => 'Standard',
        self::SMALL_ORGANISATION_KEY => 'Small organisation',
    ];

    /**
     * The options the settings select renders, in a stable order with the
     * default set first.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (string $key): array => ['value' => $key, 'label' => self::label($key)],
            self::selectableKeys(),
        );
    }

    /**
     * The keys an admin may save.
     *
     * DEFAULT_KEY is always present even when nothing has been seeded. That
     * is not defensive padding: it is the column's default, so an instance
     * that has migrated but not yet seeded — a fresh container between its
     * two entrypoint steps, or any test that touches the settings form
     * without building scales — must still be able to save the value it is
     * already holding. Anything else makes an unrelated setting unsaveable
     * for a reason that has nothing to do with scales.
     *
     * @return list<string>
     */
    public static function selectableKeys(): array
    {
        $keys = self::seededKeys();

        if (! in_array(self::DEFAULT_KEY, $keys, true)) {
            array_unshift($keys, self::DEFAULT_KEY);
        }

        return $keys;
    }

    /**
     * The keys of the **complete** sets a deployment actually has.
     *
     * Incomplete sets are omitted rather than offered and then failed at
     * creation time: an assessment's three scale FKs are NOT NULL, so a set
     * missing a kind cannot start an assessment and must not be selectable.
     *
     * @return list<string>
     */
    public static function seededKeys(): array
    {
        /** @var array<string, array<string, true>> $kindsByKey */
        $kindsByKey = [];

        foreach (self::systemScaleRows() as $row) {
            /** @var string $key */
            $key = $row->getAttribute('set_key');
            $kindsByKey[$key][$row->kind] = true;
        }

        $complete = [];

        foreach ($kindsByKey as $key => $kinds) {
            if (count($kinds) === count(self::KINDS)) {
                $complete[] = (string) $key;
            }
        }

        sort($complete);

        // The default set leads the list wherever it exists; the rest keep a
        // stable alphabetical order so the select does not reshuffle itself
        // when a deployment seeds another one.
        if (($at = array_search(self::DEFAULT_KEY, $complete, true)) !== false) {
            unset($complete[$at]);
            array_unshift($complete, self::DEFAULT_KEY);
        }

        return array_values($complete);
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    /**
     * The three scale ids a set names, or null when it has none.
     *
     * **This replaces `pluck('id', 'kind')` over every system scale**, which
     * was correct only for as long as exactly one set existed. With two, two
     * rows share `kind = 'impact'`, `pluck` keeps whichever the database
     * happened to return last, and every new assessment gets an arbitrary
     * calibration with nothing raised anywhere — the failure W75 pinned and
     * confirmed empirically against a green suite.
     *
     * A duplicate kind *within* one set throws rather than returning null.
     * Null means "this set is not here", which the caller answers by falling
     * back; a set that is here and malformed is a seeding bug, and quietly
     * substituting another calibration for it would be the same silent-wrong-
     * answer failure by a longer route.
     *
     * @return array{impact: int, likelihood: int, strength: int}|null
     *
     * @throws RuntimeException when the set holds more than one scale of a kind
     */
    public static function resolve(string $key): ?array
    {
        /** @var array<string, int> $byKind */
        $byKind = [];

        foreach (self::systemScaleRows($key) as $row) {
            if (isset($byKind[$row->kind])) {
                throw new RuntimeException(
                    "System scale set '{$key}' holds more than one {$row->kind} scale, ".
                    'so which calibration a new assessment would get is undefined. '.
                    'Deduplicate the `scales` rows for that set_key.'
                );
            }

            $byKind[$row->kind] = $row->id;
        }

        foreach (self::KINDS as $kind) {
            if (! isset($byKind[$kind])) {
                return null;
            }
        }

        return [
            'impact' => $byKind['impact'],
            'likelihood' => $byKind['likelihood'],
            'strength' => $byKind['strength'],
        ];
    }

    /**
     * The kinds a set is missing, in the canonical order, so a failure can
     * name the row that is actually absent.
     *
     * "No complete set exists" is true but useless to whoever has to fix it;
     * "no system impact scale exists in set X" says which row to look for.
     *
     * @return list<string>
     */
    public static function missingKinds(string $key): array
    {
        $present = self::systemScaleRows($key)->pluck('kind')->all();

        return array_values(array_filter(
            self::KINDS,
            static fn (string $kind): bool => ! in_array($kind, $present, true),
        ));
    }

    /**
     * Every shared system scale that belongs to a set.
     *
     * `set_key IS NULL` rows are excluded: a system scale predating the W77
     * migration cannot exist (it backfilled them all), so a NULL here would
     * be a row written by something that bypassed the seeder, and it belongs
     * to no set by definition.
     *
     * @return EloquentCollection<int, Scale>
     */
    private static function systemScaleRows(?string $key = null): EloquentCollection
    {
        return Scale::query()
            ->whereNull('assessment_id')
            ->where('is_system', true)
            ->whereNotNull('set_key')
            ->when($key !== null, fn ($query) => $query->where('set_key', $key))
            ->get(['id', 'kind', 'set_key']);
    }
}
