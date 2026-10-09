<?php

namespace Database\Seeders;

use App\Models\CatalogEntry;
use Database\Seeders\Support\CatalogFixtureValidator;
use Database\Seeders\Support\SeederFixtureException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads `database/seeders/templates/*.json` fixtures of `"kind":
 * "catalog"` — ISO/IEC 27001+27002 and ISO/IEC 42001+EU AI Act (PLAN.md
 * Phase 9 item 1) — into `catalog_entries` / `catalog_entry_links`.
 *
 * # Fixture schema (frozen for W22)
 *
 * ```json
 * {
 *   "schema_version": 1,
 *   "kind": "catalog",
 *   "key": "iso27001-27002",
 *   "entries": [{
 *     "ref": "defence-access-control-policy",
 *     "kind": "risk" | "attack" | "defence",
 *     "title": "...", "description": "...",
 *     "defaults": {
 *       // risk:    impact_mode, impact_amount | impact_level_code
 *       // attack:  likelihood_mode, likelihood_aro | likelihood_level_code
 *       // defence: is_transfer?, status?, capex_amount?, opex_annual_amount?
 *     },
 *     "children": [{
 *       "ref": "<another entry's ref — risk children must be 'attack',
 *                attack children must be 'defence', matching
 *                CatalogInserter::assertValidShape()>",
 *       "defaults": {
 *         // meaningful only when the parent is an attack (consumed by
 *         // CatalogInserter::applyEdgeDefaults()):
 *         "reduction_target": "likelihood" | "impact",
 *         "strength_fraction": 0.0..1.0,
 *         "strength_level_code": "weak|limited|moderate|strong|very_strong"
 *       }
 *     }]
 *   }]
 * }
 * ```
 *
 * `defaults` and `children[*].defaults` store scale level *codes*, never
 * ids (schema.md §3.14, CatalogEntry's own docblock) — this seeder never
 * resolves them against any scale; that is `CatalogInserter`'s job, at the
 * moment someone actually inserts the entry into a real assessment. This
 * class only persists the flat library rows.
 *
 * **ISO standard text is copyrighted.** Fixture authors reference control
 * *identifiers* only (e.g. "A.8.7", "Article 15") and write original
 * descriptions — this seeder does not and cannot enforce that at load
 * time, so it is a fixture-authoring rule, not a runtime check.
 *
 * Idempotent per entry: `firstOrCreate` on (`kind`, `title`,
 * `owner_user_id` = null) — the system catalogue has no natural unique key
 * of its own (catalog_entries carries no uuid, schema.md §3.14), so title
 * is the stable identity a fixture author controls. Running `db:seed`
 * twice does not duplicate an entry or its bundle links.
 */
class CatalogLibrarySeeder extends Seeder
{
    private readonly CatalogFixtureValidator $validator;

    public function __construct()
    {
        $this->validator = new CatalogFixtureValidator;
    }

    /**
     * @param  list<string>  $filePaths
     * @return array{seeded: list<string>, entries: int, links: int}
     */
    public function seedFiles(array $filePaths): array
    {
        $seededKeys = [];
        $entryCount = 0;
        $linkCount = 0;

        foreach ($filePaths as $path) {
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new SeederFixtureException("Could not read fixture file: {$path}");
            }

            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new SeederFixtureException("Fixture file is not valid JSON: {$path}");
            }

            $fixture = $this->validator->validate($decoded, $path);

            [$entries, $links] = DB::transaction(fn (): array => $this->seedOne($fixture));

            $seededKeys[] = $fixture['key'];
            $entryCount += $entries;
            $linkCount += $links;
        }

        return ['seeded' => $seededKeys, 'entries' => $entryCount, 'links' => $linkCount];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array{0: int, 1: int} entries touched, links touched
     */
    private function seedOne(array $fixture): array
    {
        $entriesByRef = [];
        $entryCount = 0;

        foreach ($fixture['entries'] as $entrySpec) {
            $entry = CatalogEntry::query()->firstOrCreate(
                ['kind' => $entrySpec['kind'], 'title' => $entrySpec['title'], 'owner_user_id' => null],
                [
                    'description' => $entrySpec['description'] ?? null,
                    'defaults' => $entrySpec['defaults'] ?? null,
                    'is_public' => true,
                ],
            );

            $entriesByRef[$entrySpec['ref']] = $entry;
            $entryCount++;
        }

        $linkCount = 0;
        foreach ($fixture['entries'] as $entrySpec) {
            $parent = $entriesByRef[$entrySpec['ref']];

            foreach ((array) ($entrySpec['children'] ?? []) as $childSpec) {
                $child = $entriesByRef[$childSpec['ref']];

                $exists = DB::table('catalog_entry_links')
                    ->where('parent_entry_id', $parent->id)
                    ->where('child_entry_id', $child->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $parent->catalogChildren()->attach($child->id, [
                    'defaults' => isset($childSpec['defaults']) ? json_encode($childSpec['defaults']) : null,
                ]);
                $linkCount++;
            }
        }

        return [$entryCount, $linkCount];
    }
}
