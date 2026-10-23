<?php

namespace App\Domain\Scales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

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
 * - the **names**, which live in `scale_sets` (W126);
 * - **resolution**, which turns a key into the three scale ids an assessment
 *   is created with.
 *
 * ## Names are data since W126
 *
 * They were a constant while a set was not a row. An admin can now create one,
 * so the name lives in `scale_sets`, one row per set, and the three scales of
 * a set stay keyed by `set_key` alone. "Set" is the code's word for what the
 * interface calls a scale.
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

    /**
     * The options the settings select renders, in a stable order with the
     * default set first.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (array $set): array => ['value' => $set['key'], 'label' => $set['name']],
            self::catalogue(),
        );
    }

    /**
     * Every selectable scale with its name, in display order, from one query.
     *
     * DEFAULT_KEY is always present even when nothing has been seeded. That
     * is not defensive padding: it is the column's default, so an instance
     * that has migrated but not yet seeded — a fresh container between its
     * two entrypoint steps, or any test that touches the settings form
     * without building scales — must still be able to save the value it is
     * already holding. Anything else makes an unrelated setting unsaveable
     * for a reason that has nothing to do with scales.
     *
     * Built-in scales come first, in their seeded order, then custom ones by
     * name; a key `scale_sets` has no row for goes last, alphabetically,
     * shown by its key.
     *
     * @return list<array{key: string, name: string, is_builtin: bool}>
     */
    public static function catalogue(): array
    {
        $sets = self::completeSets();

        if (! isset($sets[self::DEFAULT_KEY])) {
            $sets[self::DEFAULT_KEY] = ['name' => self::DEFAULT_KEY, 'is_builtin' => false, 'position' => 0, 'known' => false];
        }

        return self::arranged($sets);
    }

    /**
     * The complete sets, with what `scale_sets` knows about each.
     *
     * @return array<string, array{name: string, is_builtin: bool, position: int, known: bool}>
     */
    private static function completeSets(): array
    {
        $kindsByKey = [];
        $meta = [];

        foreach (self::systemScaleRows() as $row) {
            $key = (string) $row->set_key;
            $kindsByKey[$key][$row->kind] = true;
            $meta[$key] = [
                'name' => $row->name !== null ? (string) $row->name : $key,
                'is_builtin' => $row->is_builtin !== null && (bool) $row->is_builtin,
                'position' => (int) ($row->position ?? 0),
                'known' => $row->name !== null,
            ];
        }

        $sets = [];

        foreach ($kindsByKey as $key => $kinds) {
            if (count($kinds) === count(self::KINDS)) {
                $sets[(string) $key] = $meta[(string) $key];
            }
        }

        return $sets;
    }

    /**
     * @param  array<string, array{name: string, is_builtin: bool, position: int, known: bool}>  $sets
     * @return list<array{key: string, name: string, is_builtin: bool}>
     */
    private static function arranged(array $sets): array
    {
        $keys = array_map('strval', array_keys($sets));
        usort($keys, static function (string $a, string $b) use ($sets): int {
            $rank = static fn (string $k): array => $sets[$k]['known']
                ? [$sets[$k]['is_builtin'] ? 0 : 1, $sets[$k]['is_builtin'] ? $sets[$k]['position'] : 0, mb_strtolower($sets[$k]['name'])]
                : [2, 0, $k];

            return $rank($a) <=> $rank($b);
        });

        return array_map(
            static fn (string $k): array => ['key' => $k, 'name' => $sets[$k]['name'], 'is_builtin' => $sets[$k]['is_builtin']],
            $keys,
        );
    }

    /**
     * The keys an admin may save.
     *
     * @return list<string>
     */
    public static function selectableKeys(): array
    {
        return array_map(static fn (array $set): string => $set['key'], self::catalogue());
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
        return array_map(
            static fn (array $set): string => $set['key'],
            self::arranged(self::completeSets()),
        );
    }

    /**
     * A scale's name. A set seeded under a key `scale_sets` does not know
     * (a deployment's own seeder) is shown by its key: legible, and better
     * than a blank option.
     */
    public static function label(string $key): string
    {
        foreach (self::catalogue() as $set) {
            if ($set['key'] === $key) {
                return $set['name'];
            }
        }

        return $key;
    }

    /** @return array<string, string> key => name */
    public static function names(): array
    {
        $names = [];

        foreach (self::catalogue() as $set) {
            $names[$set['key']] = $set['name'];
        }

        return $names;
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
     * Every shared system scale that belongs to a set, with its `scale_sets`
     * name and order when it has a row there (a left join, so a set seeded
     * under a key `scale_sets` does not know still comes back).
     *
     * `set_key IS NULL` rows are excluded: a system scale predating the W77
     * migration cannot exist (it backfilled them all), so a NULL here would
     * be a row written by something that bypassed the seeder, and it belongs
     * to no set by definition.
     *
     * @return Collection<int, stdClass>
     */
    private static function systemScaleRows(?string $key = null): Collection
    {
        return DB::table('scales')
            ->leftJoin('scale_sets', 'scale_sets.key', '=', 'scales.set_key')
            ->whereNull('scales.assessment_id')
            ->where('scales.is_system', true)
            ->whereNotNull('scales.set_key')
            ->when($key !== null, fn ($query) => $query->where('scales.set_key', $key))
            ->get(['scales.id', 'scales.kind', 'scales.set_key', 'scale_sets.name', 'scale_sets.is_builtin', 'scale_sets.position']);
    }
}
