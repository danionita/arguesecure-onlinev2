<?php

namespace Database\Seeders\Support;

use App\Domain\References\ControlId;

/**
 * Validates a `database/seeders/templates/*.json` fixture of
 * `"kind": "assessment"` against the schema frozen for W22 (see
 * database/seeders/TemplateAssessmentSeeder.php for the full shape
 * documentation and a worked example at
 * database/seeders/templates/demo-webshop.json).
 *
 * Deliberately a plain array-in, array-out validator with no Eloquent and
 * no side effects — TemplateAssessmentSeeder is the only thing that
 * persists anything, so a fixture can be validated (and tested) without a
 * database at all.
 *
 * Every failure throws SeederFixtureException with a message naming the
 * fixture key and the exact field at fault, per W22's "fail loudly, never
 * silently" rule (the same rule CatalogInserter enforces at insert time).
 *
 * ## What W76 added, and why
 *
 * The schema froze in W22 with `description` optional and reachability
 * checked downward only. Both are legal for the *database* — `description`
 * is nullable and an unlinked node is a valid row — and both let a fixture
 * pass validation and still seed something a reader would call empty: a
 * card with a title and nothing under it, or an attack that exists in the
 * node counts and appears nowhere in the tree. W76's brief is explicit
 * that "a fixture that passes validation and seeds an empty tree means the
 * validator is not checking the thing that matters", so a non-empty
 * `description` is now required on the root and on every risk, attack and
 * defence, and assertNothingIsOrphaned() checks the DAG upward as well as
 * down. These are content rules for *shipped fixtures*, deliberately
 * stricter than the schema: a user's own assessment may hold an
 * undescribed node, an archetype somebody is meant to start a workshop
 * from may not.
 */
final class AssessmentFixtureValidator
{
    private const IMPACT_CODES = ['negligible', 'minor', 'moderate', 'major', 'severe'];

    private const LIKELIHOOD_CODES = ['rare', 'unlikely', 'possible', 'likely', 'frequent'];

    private const STRENGTH_CODES = ['weak', 'limited', 'moderate', 'strong', 'very_strong'];

    private const DEFENCE_STATUSES = ['implemented', 'todo', 'shelved'];

    private const REFERENCE_KINDS = ['cve', 'cwe', 'capec', 'attack_technique', 'control', 'url', 'other'];

    /** `nodes.title` and `nodes.description`. */
    private const TITLE_MAX = 255;

    private const DESCRIPTION_MAX = 500;

    /**
     * @param  array<string, mixed>  $data  json_decode($json, true) of the fixture file
     * @return array<string, mixed> the same array, unchanged — validated only
     */
    public function validate(array $data, string $sourcePath): array
    {
        $key = $this->key($data, $sourcePath);
        $fail = fn (string $message) => throw new SeederFixtureException("Fixture '{$key}' ({$sourcePath}): {$message}");

        if (($data['schema_version'] ?? null) !== 1) {
            $fail('schema_version must be exactly 1.');
        }

        if (($data['kind'] ?? null) !== 'assessment') {
            $fail("kind must be 'assessment'.");
        }

        foreach (['key', 'root_uuid', 'title', 'currency', 'description'] as $required) {
            if (! is_string($data[$required] ?? null) || $data[$required] === '') {
                $fail("'{$required}' is required and must be a non-empty string.");
            }
        }
        $this->assertNonEmptyString($data['title'], 'title', $fail, self::TITLE_MAX);
        $this->assertNonEmptyString($data['description'], 'description', $fail, self::DESCRIPTION_MAX);

        if (! preg_match('/^[A-Z]{3}$/', (string) $data['currency'])) {
            $fail("currency '{$data['currency']}' must be a 3-letter uppercase ISO 4217 code.");
        }

        $this->assertUuid($data['root_uuid'], 'root_uuid', $fail);

        $risks = $data['risks'] ?? null;
        $attacks = $data['attacks'] ?? null;
        $defences = $data['defences'] ?? null;

        if (! is_array($risks) || $risks === []) {
            $fail("'risks' must be a non-empty array.");
        }
        if (! is_array($attacks) || $attacks === []) {
            $fail("'attacks' must be a non-empty array.");
        }
        if (! is_array($defences) || $defences === []) {
            $fail("'defences' must be a non-empty array.");
        }

        $tagNames = [];
        foreach ((array) ($data['tags'] ?? []) as $i => $tag) {
            $name = $tag['name'] ?? null;
            if (! is_string($name) || $name === '') {
                $fail("tags[{$i}].name is required and must be a non-empty string.");
            }
            $tagNames[] = $name;
        }

        $uuids = [$data['root_uuid'] => 'root_uuid'];
        $attackRefs = [];
        $defenceRefs = [];

        foreach ($attacks as $i => $attack) {
            $ref = $this->assertRef($attack, "attacks[{$i}]", $fail);
            if (isset($attackRefs[$ref])) {
                $fail("duplicate attack ref '{$ref}'.");
            }
            $attackRefs[$ref] = $attack;
        }

        foreach ($defences as $i => $defence) {
            $ref = $this->assertRef($defence, "defences[{$i}]", $fail);
            if (isset($defenceRefs[$ref])) {
                $fail("duplicate defence ref '{$ref}'.");
            }
            $defenceRefs[$ref] = $defence;
        }

        // Every node's uuid must be unique across the whole file, alongside
        // root_uuid — this is the seeder's idempotency anchor, so a
        // collision would silently merge two logically distinct nodes on
        // the next run.
        foreach ($risks as $i => $risk) {
            $this->validateRisk($risk, "risks[{$i}]", $attackRefs, $tagNames, $uuids, $fail);
        }
        foreach ($attackRefs as $ref => $attack) {
            $this->validateAttack($attack, "attacks[{$ref}]", $defenceRefs, $uuids, $fail);
        }
        foreach ($defenceRefs as $ref => $defence) {
            $this->validateDefence($defence, "defences[{$ref}]", $attackRefs, $uuids, $fail);
        }

        // Cross-check: every (attack, defence) pair implied by an attack's
        // defence_refs must have a matching entry in that defence's own
        // `links` map, and vice versa — the two arrays describe the same
        // edges from each side and must agree.
        foreach ($attackRefs as $attackRef => $attack) {
            foreach ((array) ($attack['defence_refs'] ?? []) as $defenceRef) {
                $links = $defenceRefs[$defenceRef]['links'] ?? [];
                if (! is_array($links) || ! array_key_exists($attackRef, $links)) {
                    $fail("attack '{$attackRef}' lists defence '{$defenceRef}' in defence_refs, but that defence has no links['{$attackRef}'] entry.");
                }
            }
        }
        foreach ($defenceRefs as $defenceRef => $defence) {
            foreach (array_keys((array) ($defence['links'] ?? [])) as $attackRef) {
                if (! isset($attackRefs[$attackRef]) || ! in_array($defenceRef, (array) ($attackRefs[$attackRef]['defence_refs'] ?? []), true)) {
                    $fail("defence '{$defenceRef}' has links['{$attackRef}'], but attack '{$attackRef}' does not list it in defence_refs.");
                }
            }
        }

        $this->assertNothingIsOrphaned($risks, $attackRefs, $fail);

        return $data;
    }

    /**
     * Reachability, checked downward *and* upward (W76).
     *
     * The rules above only ever look down the DAG — a risk must name at
     * least one attack, an attack at least one defence — which says nothing
     * about a node nothing names. An attack absent from every risk's
     * `attack_refs` still seeds: it becomes a row in `nodes` with no
     * `risk_attack` edge, so it counts towards the fixture's totals, holds
     * a likelihood, contributes to no pair in the calculation, and is
     * invisible in a tree the reader navigates from the root down. Its
     * defences go with it. That is the shape of the emptiness PLAN-2.4 item
     * 2 reports — a fixture whose numbers look right and whose tree is not
     * — and it is exactly what a validator checking only one direction
     * cannot see.
     *
     * There is deliberately no matching check for defences; see the note
     * further down for why one would be a guard that can never fire.
     *
     * @param  array<int, mixed>  $risks
     * @param  array<string, mixed>  $attackRefs
     * @param  callable(string): never  $fail
     */
    private function assertNothingIsOrphaned(array $risks, array $attackRefs, callable $fail): void
    {
        $attacksNamed = [];
        foreach ($risks as $risk) {
            $seen = [];
            foreach ((array) ($risk['attack_refs'] ?? []) as $ref) {
                if (isset($seen[$ref])) {
                    // `risk_attack` is unique on (risk, attack): listing the
                    // same attack twice under one risk is a duplicate edge
                    // the database would reject, not a second link.
                    $fail("risk '{$risk['ref']}' lists attack '{$ref}' more than once.");
                }
                $seen[$ref] = true;
                $attacksNamed[$ref] = true;
            }
        }

        foreach (array_keys($attackRefs) as $ref) {
            if (! isset($attacksNamed[$ref])) {
                $fail("attack '{$ref}' is listed in no risk's attack_refs — it would seed as a node no risk reaches.");
            }
        }

        // No "defence no attack lists" check to match the attack one above,
        // and that is not an oversight. A defence's `links` must be
        // non-empty (validateDefence), and validate()'s W22 cross-check
        // already refuses any `links` key whose attack does not name the
        // defence back — so an unreferenced defence cannot get this far.
        // Writing the symmetric loop anyway produced a guard that could
        // never fire, which mutation-testing showed at once: neutering it
        // left every test green. That is CLAUDE.md's "a presence assertion
        // is not a guard" from the other side, and the honest answer is to
        // delete it and say why here. The behaviour is still asserted, in
        // FixtureStructuralHolesTest, which names the rule that does the
        // rejecting.
        //
        // The duplicate-edge check has no such cover, so it stays.
        foreach ($attackRefs as $attackRef => $attack) {
            $seen = [];
            foreach ((array) ($attack['defence_refs'] ?? []) as $ref) {
                if (isset($seen[$ref])) {
                    // `attack_defence` is unique on (attack, defence) — the
                    // same reasoning as the risk side above.
                    $fail("attack '{$attackRef}' lists defence '{$ref}' more than once.");
                }
                $seen[$ref] = true;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attackRefs
     * @param  list<string>  $tagNames
     * @param  array<string, string>  $uuids
     * @param  callable(string): never  $fail
     */
    private function validateRisk(mixed $risk, string $path, array $attackRefs, array $tagNames, array &$uuids, callable $fail): void
    {
        if (! is_array($risk)) {
            $fail("{$path} must be an object.");
        }
        $this->assertRef($risk, $path, $fail);
        $this->assertUuidField($risk, "{$path}.uuid", $uuids, $fail);
        $this->assertNonEmptyString($risk['title'] ?? null, "{$path}.title", $fail, self::TITLE_MAX);
        $this->assertNonEmptyString($risk['description'] ?? null, "{$path}.description", $fail, self::DESCRIPTION_MAX);
        $this->assertValueSpec($risk['impact'] ?? null, 'impact', self::IMPACT_CODES, "{$path}.impact", $fail);

        $refs = $risk['attack_refs'] ?? null;
        if (! is_array($refs) || $refs === []) {
            $fail("{$path}.attack_refs must be a non-empty array of attack refs.");
        }
        foreach ($refs as $r) {
            if (! isset($attackRefs[$r])) {
                $fail("{$path}.attack_refs references unknown attack ref '{$r}'.");
            }
        }

        foreach ((array) ($risk['tags'] ?? []) as $tagName) {
            if (! in_array($tagName, $tagNames, true)) {
                $fail("{$path}.tags references '{$tagName}', which is not declared in the fixture's top-level tags list.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $defenceRefs
     * @param  array<string, string>  $uuids
     * @param  callable(string): never  $fail
     */
    private function validateAttack(mixed $attack, string $path, array $defenceRefs, array &$uuids, callable $fail): void
    {
        $this->assertUuidField($attack, "{$path}.uuid", $uuids, $fail);
        $this->assertNonEmptyString($attack['title'] ?? null, "{$path}.title", $fail, self::TITLE_MAX);
        $this->assertNonEmptyString($attack['description'] ?? null, "{$path}.description", $fail, self::DESCRIPTION_MAX);
        $this->assertValueSpec($attack['likelihood'] ?? null, 'likelihood', self::LIKELIHOOD_CODES, "{$path}.likelihood", $fail);

        $refs = $attack['defence_refs'] ?? null;
        if (! is_array($refs) || $refs === []) {
            $fail("{$path}.defence_refs must be a non-empty array of defence refs.");
        }
        foreach ($refs as $r) {
            if (! isset($defenceRefs[$r])) {
                $fail("{$path}.defence_refs references unknown defence ref '{$r}'.");
            }
        }

        $this->validateReferences($attack, $path, false, $fail);
    }

    /**
     * @param  callable(string): never  $fail
     */
    private function validateReferences(mixed $node, string $path, bool $isDefence, callable $fail): void
    {
        foreach ((array) ($node['references'] ?? []) as $j => $reference) {
            $kind = $reference['kind'] ?? null;
            if (! in_array($kind, self::REFERENCE_KINDS, true)) {
                $fail("{$path}.references[{$j}].kind is not a valid reference kind.");
            }

            // PLAN-2.7 D64: a control is cited by a defence, and in the
            // exact form the request path would store — a fixture is not
            // allowed a citation a user could not type.
            if ($kind === 'control') {
                if (! $isDefence) {
                    $fail("{$path}.references[{$j}]: only a defence can cite a control.");
                }
                $identifier = $reference['identifier'] ?? null;
                if (! is_string($identifier) || ControlId::parse($identifier) === null) {
                    $fail("{$path}.references[{$j}]: '".(is_string($identifier) ? $identifier : '')."' is not a FRAMEWORK:CONTROL citation of a configured framework.");
                }
            }

            // schema.md Â§3.12: identifier required for every kind except
            // `url`; url required for `url` and `other`.
            if ($kind !== 'url' && (! is_string($reference['identifier'] ?? null) || $reference['identifier'] === '')) {
                $fail("{$path}.references[{$j}]: kind '{$kind}' requires a non-empty identifier.");
            }
            if (in_array($kind, ['url', 'other'], true) && (! is_string($reference['url'] ?? null) || ! str_starts_with($reference['url'], 'http'))) {
                $fail("{$path}.references[{$j}]: kind '{$kind}' requires an http(s) url.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attackRefs
     * @param  array<string, string>  $uuids
     * @param  callable(string): never  $fail
     */
    private function validateDefence(mixed $defence, string $path, array $attackRefs, array &$uuids, callable $fail): void
    {
        $this->assertUuidField($defence, "{$path}.uuid", $uuids, $fail);
        $this->assertNonEmptyString($defence['title'] ?? null, "{$path}.title", $fail, self::TITLE_MAX);
        $this->assertNonEmptyString($defence['description'] ?? null, "{$path}.description", $fail, self::DESCRIPTION_MAX);

        if (! in_array($defence['status'] ?? null, self::DEFENCE_STATUSES, true)) {
            $fail("{$path}.status must be one of ".implode('/', self::DEFENCE_STATUSES).'.');
        }

        $links = $defence['links'] ?? null;
        if (! is_array($links) || $links === []) {
            $fail("{$path}.links must be a non-empty object keyed by attack ref.");
        }

        foreach ($links as $attackRef => $link) {
            if (! isset($attackRefs[$attackRef])) {
                $fail("{$path}.links references unknown attack ref '{$attackRef}'.");
            }
            $this->assertValueSpec($link['strength'] ?? null, 'strength', self::STRENGTH_CODES, "{$path}.links[{$attackRef}].strength", $fail);

            $target = $link['reduction_target'] ?? null;
            if ($target !== null && ! in_array($target, ['likelihood', 'impact'], true)) {
                $fail("{$path}.links[{$attackRef}].reduction_target must be null, 'likelihood' or 'impact'.");
            }
        }

        $this->validateReferences($defence, $path, true, $fail);
    }

    /** @param  callable(string): never  $fail */
    private function assertRef(mixed $node, string $path, callable $fail): string
    {
        $ref = $node['ref'] ?? null;
        if (! is_string($ref) || $ref === '') {
            $fail("{$path}.ref is required and must be a non-empty string.");
        }

        return $ref;
    }

    /**
     * @param  array<string, string>  $uuids
     * @param  callable(string): never  $fail
     */
    private function assertUuidField(mixed $node, string $path, array &$uuids, callable $fail): void
    {
        $rawUuid = $node['uuid'] ?? null;
        $this->assertUuid($rawUuid, $path, $fail);
        // assertUuid() has already failed loudly (never returns) for
        // anything that is not a well-formed UUID string, so the cast
        // below is lossless — it exists only to give $uuids a string key
        // rather than the mixed one $rawUuid started as.
        $uuid = (string) $rawUuid;
        if (isset($uuids[$uuid])) {
            $fail("uuid '{$uuid}' at {$path} duplicates the one already used at '{$uuids[$uuid]}' — every node's uuid must be unique within the fixture and stable across runs.");
        }
        $uuids[$uuid] = $path;
    }

    /** @param  callable(string): never  $fail */
    private function assertUuid(mixed $uuid, string $path, callable $fail): void
    {
        if (! is_string($uuid) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            $fail("{$path} must be a valid UUID string.");
        }
    }

    /** @param  callable(string): never  $fail */
    private function assertNonEmptyString(mixed $value, string $path, callable $fail, ?int $maxLength = null): void
    {
        if (! is_string($value) || $value === '') {
            $fail("{$path} is required and must be a non-empty string.");
        }

        // The nodes columns are VARCHAR(255) and VARCHAR(500), counted in
        // characters. Without this a fixture that is too long passes
        // validation and then dies mid-seed on "Data too long" — inside
        // first-run setup, which is the worst place to find out.
        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            $fail("{$path} is ".mb_strlen($value)." characters; the column holds {$maxLength}.");
        }
    }

    /**
     * @param  list<string>  $allowedCodes
     * @param  callable(string): never  $fail
     */
    private function assertValueSpec(mixed $spec, string $kind, array $allowedCodes, string $path, callable $fail): void
    {
        if (! is_array($spec)) {
            $fail("{$path} must be an object with a 'mode' key.");
        }

        $mode = $spec['mode'] ?? null;
        $amountKey = match ($kind) {
            'impact', 'likelihood' => 'amount',
            'strength' => 'fraction',
            default => $fail("{$path}: unknown ValueSpec kind '{$kind}'."),
        };

        if ($mode === 'quantitative') {
            if (! isset($spec[$amountKey]) || ! is_numeric($spec[$amountKey])) {
                $fail("{$path}: quantitative mode requires a numeric '{$amountKey}'.");
            }
            if ((float) $spec[$amountKey] < 0) {
                $fail("{$path}.{$amountKey} must be >= 0.");
            }
            if ($kind === 'strength' && (float) $spec[$amountKey] > 1) {
                $fail("{$path}.fraction must be <= 1.");
            }
        } elseif ($mode === 'qualitative') {
            $code = $spec['level_code'] ?? null;
            if (! in_array($code, $allowedCodes, true)) {
                $fail("{$path}.level_code '{$code}' is not a valid {$kind} code (allowed: ".implode('/', $allowedCodes).').');
            }
        } else {
            $fail("{$path}.mode must be 'quantitative' or 'qualitative'.");
        }
    }

    /** @param  array<string, mixed>  $data */
    private function key(array $data, string $sourcePath): string
    {
        $key = $data['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : $sourcePath;
    }
}
