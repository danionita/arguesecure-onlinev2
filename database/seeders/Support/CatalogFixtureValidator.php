<?php

namespace Database\Seeders\Support;

/**
 * Validates a `database/seeders/templates/*.json` fixture of
 * `"kind": "catalog"` against the schema frozen for W22 (see
 * database/seeders/CatalogLibrarySeeder.php for the full shape
 * documentation).
 *
 * The shape mirrors what `App\Domain\Catalog\CatalogInserter` actually
 * consumes (`CatalogEntry::$defaults`, `catalog_entry_links.defaults`), so
 * an entry seeded from a fixture that passes this validator is guaranteed
 * insertable later through the real insert path — same field names, same
 * "codes not ids" rule, same risk→attack→defence shape restriction
 * (`CatalogInserter::assertValidShape()`).
 */
final class CatalogFixtureValidator
{
    private const IMPACT_CODES = ['negligible', 'minor', 'moderate', 'major', 'severe'];

    private const LIKELIHOOD_CODES = ['rare', 'unlikely', 'possible', 'likely', 'frequent'];

    private const STRENGTH_CODES = ['weak', 'limited', 'moderate', 'strong', 'very_strong'];

    private const DEFENCE_STATUSES = ['implemented', 'todo', 'shelved'];

    private const ENTRY_KINDS = ['risk', 'attack', 'defence'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validate(array $data, string $sourcePath): array
    {
        $key = $this->key($data, $sourcePath);
        $fail = fn (string $message) => throw new SeederFixtureException("Fixture '{$key}' ({$sourcePath}): {$message}");

        if (($data['schema_version'] ?? null) !== 1) {
            $fail('schema_version must be exactly 1.');
        }

        if (($data['kind'] ?? null) !== 'catalog') {
            $fail("kind must be 'catalog'.");
        }

        if (! is_string($data['key'] ?? null) || $data['key'] === '') {
            $fail("'key' is required and must be a non-empty string.");
        }

        $entries = $data['entries'] ?? null;
        if (! is_array($entries) || $entries === []) {
            $fail("'entries' must be a non-empty array.");
        }

        $byRef = [];
        foreach ($entries as $i => $entry) {
            $ref = $entry['ref'] ?? null;
            if (! is_string($ref) || $ref === '') {
                $fail("entries[{$i}].ref is required and must be a non-empty string.");
            }
            if (isset($byRef[$ref])) {
                $fail("duplicate entry ref '{$ref}'.");
            }
            $byRef[$ref] = $entry;
        }

        foreach ($byRef as $ref => $entry) {
            $this->validateEntry($entry, $ref, $byRef, $fail);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, array<string, mixed>>  $byRef
     * @param  callable(string): never  $fail
     */
    private function validateEntry(array $entry, string $ref, array $byRef, callable $fail): void
    {
        $kind = $entry['kind'] ?? null;
        if (! in_array($kind, self::ENTRY_KINDS, true)) {
            $fail("entry '{$ref}': kind must be one of ".implode('/', self::ENTRY_KINDS).'.');
        }

        if (! is_string($entry['title'] ?? null) || $entry['title'] === '') {
            $fail("entry '{$ref}': title is required and must be a non-empty string.");
        }

        // W76: an entry with no description inserts a node whose body is
        // empty. `catalog_entries.description` is nullable and always will
        // be — a user's own saved entry may legitimately have none — but a
        // shipped ISO entry exists to explain a control in this project's
        // own words (the standard's text is copyrighted; see
        // CatalogLibrarySeeder), so a blank one has nothing to offer.
        if (! is_string($entry['description'] ?? null) || trim($entry['description']) === '') {
            $fail("entry '{$ref}': description is required and must be a non-empty string.");
        }

        $this->validateDefaults($entry['defaults'] ?? [], $kind, $ref, $fail);

        $children = $entry['children'] ?? [];
        if (! is_array($children)) {
            $fail("entry '{$ref}': children must be an array if present.");
        }

        // No default arm: the in_array() check above already failed loudly
        // (via $fail(), typed callable(string): never) for anything
        // outside ENTRY_KINDS, so PHPStan narrows $kind to the literal
        // 'risk'|'attack'|'defence' union here — the three arms below are
        // already exhaustive, and a default arm would be dead code.
        $expectedChildKind = match ($kind) {
            'risk' => 'attack',
            'attack' => 'defence',
            'defence' => null,
        };

        // W76: a bundle that carries nothing is the reported emptiness, at
        // the catalogue end of it. Inserting a risk entry with no attack
        // children produces a lone risk card; inserting an attack entry
        // with no defence children produces an attack nothing mitigates.
        // Both are structurally valid rows and neither errors, which is why
        // only a fixture rule catches them. Mirrors the assessment
        // validator's non-empty `attack_refs` / `defence_refs`.
        //
        // Defences are exempt by construction — they cannot have children
        // at all — and a defence entry that no bundle names is still
        // reachable: CatalogController::insert() takes a parent node, so a
        // standalone entry is inserted under an attack the user has already
        // selected. `defence-independent-review` in the ISO 27001 fixture
        // is exactly that case and is not a hole.
        // `$expectedChildKind` is non-null here without a fallback: the
        // match above only returns null for a defence, which this branch
        // has already excluded, and PHPStan knows it.
        if ($kind !== 'defence' && $children === []) {
            $fail("entry '{$ref}' is a {$kind} that bundles no {$expectedChildKind} — inserting it would add a node with nothing under it.");
        }

        $seenChildRefs = [];

        foreach ($children as $j => $child) {
            $childRef = $child['ref'] ?? null;
            if (! is_string($childRef) || ! isset($byRef[$childRef])) {
                $fail("entry '{$ref}'.children[{$j}] references unknown entry ref '{$childRef}'.");
            }
            if ($expectedChildKind === null) {
                $fail("entry '{$ref}' is a defence and cannot bundle children — CatalogInserter's shape rules only permit risk→attack and attack→defence.");
            }
            if (($byRef[$childRef]['kind'] ?? null) !== $expectedChildKind) {
                $fail("entry '{$ref}' (kind {$kind}) can only bundle '{$expectedChildKind}' children; '{$childRef}' is '{$byRef[$childRef]['kind']}'.");
            }

            // W76: `catalog_entry_links` is unique on (parent, child), so a
            // repeated child ref is not a second link — CatalogLibrarySeeder
            // skips the duplicate and the second entry's `defaults` are
            // silently discarded along with it. Reject it here rather than
            // let a fixture author's second strength quietly not happen.
            if (isset($seenChildRefs[$childRef])) {
                $fail("entry '{$ref}' bundles '{$childRef}' more than once; catalog_entry_links is unique on (parent, child), so the repeat and its defaults would be dropped.");
            }
            $seenChildRefs[$childRef] = true;

            $this->validateEdgeDefaults($child['defaults'] ?? [], $kind, $ref, $childRef, $fail);
        }
    }

    /** @param  callable(string): never  $fail */
    private function validateDefaults(mixed $defaults, ?string $kind, string $ref, callable $fail): void
    {
        if (! is_array($defaults)) {
            $fail("entry '{$ref}': defaults must be an object.");
        }

        match ($kind) {
            'risk' => $this->validateLevelDefault($defaults, 'impact_mode', 'impact_amount', 'impact_level_code', self::IMPACT_CODES, $ref, $fail),
            'attack' => $this->validateLevelDefault($defaults, 'likelihood_mode', 'likelihood_aro', 'likelihood_level_code', self::LIKELIHOOD_CODES, $ref, $fail),
            'defence' => $this->validateDefenceDefaults($defaults, $ref, $fail),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $codes
     * @param  callable(string): never  $fail
     */
    private function validateLevelDefault(array $defaults, string $modeKey, string $amountKey, string $codeKey, array $codes, string $ref, callable $fail): void
    {
        if (isset($defaults[$modeKey]) && ! in_array($defaults[$modeKey], ['quantitative', 'qualitative'], true)) {
            $fail("entry '{$ref}': defaults.{$modeKey} must be 'quantitative' or 'qualitative'.");
        }
        if (isset($defaults[$amountKey]) && (! is_numeric($defaults[$amountKey]) || $defaults[$amountKey] < 0)) {
            $fail("entry '{$ref}': defaults.{$amountKey} must be a non-negative number.");
        }
        if (isset($defaults[$codeKey]) && ! in_array($defaults[$codeKey], $codes, true)) {
            $fail("entry '{$ref}': defaults.{$codeKey} '{$defaults[$codeKey]}' is not a valid code (allowed: ".implode('/', $codes).').');
        }
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  callable(string): never  $fail
     */
    private function validateDefenceDefaults(array $defaults, string $ref, callable $fail): void
    {
        if (isset($defaults['status']) && ! in_array($defaults['status'], self::DEFENCE_STATUSES, true)) {
            $fail("entry '{$ref}': defaults.status must be one of ".implode('/', self::DEFENCE_STATUSES).'.');
        }
        if (isset($defaults['is_transfer']) && ! is_bool($defaults['is_transfer'])) {
            $fail("entry '{$ref}': defaults.is_transfer must be a boolean.");
        }
        foreach (['capex_amount', 'opex_annual_amount'] as $costKey) {
            if (isset($defaults[$costKey]) && (! is_numeric($defaults[$costKey]) || $defaults[$costKey] < 0)) {
                $fail("entry '{$ref}': defaults.{$costKey} must be a non-negative number.");
            }
        }
    }

    /**
     * `catalog_entry_links.defaults` — only meaningful on an attack→defence
     * edge (`CatalogInserter::applyEdgeDefaults()` reads it; the
     * risk→attack branch never calls that method), so a risk-level child's
     * defaults are accepted but otherwise inert; documented here rather
     * than rejected outright, so a fixture author is not blocked from
     * annotating intent for a future feature.
     *
     * @param  callable(string): never  $fail
     */
    private function validateEdgeDefaults(mixed $defaults, string $parentKind, string $parentRef, string $childRef, callable $fail): void
    {
        if (! is_array($defaults)) {
            $fail("entry '{$parentRef}'.children['{$childRef}'].defaults must be an object if present.");
        }

        if ($parentKind !== 'attack') {
            return;
        }

        if (isset($defaults['reduction_target']) && ! in_array($defaults['reduction_target'], ['likelihood', 'impact'], true)) {
            $fail("entry '{$parentRef}'.children['{$childRef}'].defaults.reduction_target must be 'likelihood' or 'impact'.");
        }
        if (isset($defaults['strength_fraction']) && (! is_numeric($defaults['strength_fraction']) || $defaults['strength_fraction'] < 0 || $defaults['strength_fraction'] > 1)) {
            $fail("entry '{$parentRef}'.children['{$childRef}'].defaults.strength_fraction must be between 0 and 1.");
        }
        if (isset($defaults['strength_level_code']) && ! in_array($defaults['strength_level_code'], self::STRENGTH_CODES, true)) {
            $fail("entry '{$parentRef}'.children['{$childRef}'].defaults.strength_level_code '{$defaults['strength_level_code']}' is not a valid strength code.");
        }
    }

    /** @param  array<string, mixed>  $data */
    private function key(array $data, string $sourcePath): string
    {
        $key = $data['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : $sourcePath;
    }
}
