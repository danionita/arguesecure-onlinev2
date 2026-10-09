<?php

use Database\Seeders\Support\AssessmentFixtureValidator;
use Database\Seeders\Support\CatalogFixtureValidator;
use Database\Seeders\Support\SeederFixtureException;

/*
 * W22's "every JSON fixture parses, and validates against the frozen
 * schema" requirement. Every fixture under database/seeders/templates/ is
 * routed to the validator matching its declared `kind`, entirely without a
 * database — both validators are pure array-in/array-out, so this proves
 * the fixture set itself is well-formed independent of any seeding.
 */

/** @return list<string> */
function fixturePaths(): array
{
    return glob(database_path('seeders/templates/*.json')) ?: [];
}

test('at least the seven W22 fixtures exist', function () {
    $names = array_map(fn (string $p): string => basename($p), fixturePaths());

    expect($names)->toContain('demo-webshop.json')
        ->and($names)->toContain('archetype-public-web-app.json')
        ->and($names)->toContain('archetype-cloud-saas.json')
        ->and($names)->toContain('archetype-internal-network.json')
        ->and($names)->toContain('archetype-iot-embedded.json')
        ->and($names)->toContain('catalog-iso27001-27002.json')
        ->and($names)->toContain('catalog-iso42001-ai-act.json');
});

test('every fixture file is valid JSON and validates against its declared schema', function () {
    $paths = fixturePaths();
    expect($paths)->not->toBeEmpty();

    $assessmentValidator = new AssessmentFixtureValidator;
    $catalogValidator = new CatalogFixtureValidator;

    foreach ($paths as $path) {
        $raw = file_get_contents($path);
        expect($raw)->not->toBeFalse("Could not read {$path}");

        $decoded = json_decode((string) $raw, true);
        expect(json_last_error())->toBe(JSON_ERROR_NONE, "Invalid JSON in {$path}: ".json_last_error_msg())
            ->and($decoded)->toBeArray();

        // Both validators throw on a bad fixture, so reaching the end of the
        // loop is itself the assertion. `->throwsNoExceptions()` is not the
        // way to say that here: it declares the test performs no assertions,
        // while the expect() calls above do, which PHPUnit reports as risky.
        match ($decoded['kind'] ?? null) {
            'assessment' => $assessmentValidator->validate($decoded, $path),
            'catalog' => $catalogValidator->validate($decoded, $path),
            default => throw new RuntimeException("{$path} has no recognized 'kind'."),
        };
    }
});

test('every assessment fixture is a template, the demo web shop included (2.5.1)', function () {
    // The demo used to be the one fixture that was not, seeded only by a
    // deliberate `db:seed` — which no longer exists.
    foreach (fixturePaths() as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (($decoded['kind'] ?? null) !== 'assessment') {
            continue;
        }

        expect($decoded['is_template'] ?? false)->toBeTrue("{$path} should be is_template=true");
    }
});

test('every assessment fixture has at least one attack shared by two or more risks (the DAG case)', function () {
    $validator = new AssessmentFixtureValidator;

    foreach (fixturePaths() as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (($decoded['kind'] ?? null) !== 'assessment') {
            continue;
        }

        $validator->validate($decoded, $path);

        $counts = [];
        foreach ($decoded['risks'] as $risk) {
            foreach ($risk['attack_refs'] as $ref) {
                $counts[$ref] = ($counts[$ref] ?? 0) + 1;
            }
        }

        expect(max($counts))->toBeGreaterThanOrEqual(2, "{$path}: no attack is shared by two or more risks");
    }
});

test('every illustrative fixture has at least one implemented, one todo, one shelved, and one transfer defence', function () {
    foreach (fixturePaths() as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (($decoded['kind'] ?? null) !== 'assessment' || str_starts_with((string) $decoded['key'], 'standard-')) {
            continue;
        }

        $statuses = array_unique(array_column($decoded['defences'], 'status'));
        $transfers = array_filter($decoded['defences'], fn (array $d): bool => (bool) ($d['is_transfer'] ?? false));

        // toContain() treats every argument as a further needle, so the
        // explanation cannot ride along as a second parameter — it has to be
        // the message of a boolean assertion instead.
        foreach (['implemented', 'todo', 'shelved'] as $status) {
            expect(in_array($status, $statuses, true))->toBeTrue("{$path}: no {$status} defence");
        }

        expect($transfers === [])->toBeFalse("{$path}: no transfer defence");
    }
});

/*
 * The standards templates are the exception to the rule above, on purpose.
 * The demo and the archetypes illustrate a system mid-assessment, so they
 * show every status. A standard's template is the start of a gap analysis:
 * it claims nothing about the organization using it, so every control is
 * `todo` until somebody marks what they actually have. A template that
 * shipped a control as `implemented` would put a figure in the *current*
 * baseline that nobody measured.
 */
test('every standards template starts with every control todo', function () {
    $standards = 0;

    foreach (fixturePaths() as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (($decoded['kind'] ?? null) !== 'assessment' || ! str_starts_with((string) $decoded['key'], 'standard-')) {
            continue;
        }

        $standards++;
        foreach ($decoded['defences'] as $defence) {
            expect($defence['status'])->toBe('todo', "{$path}: '{$defence['title']}' is not todo");
        }
    }

    expect($standards)->toBe(7);
});

test('a malformed assessment fixture fails loudly instead of silently', function () {
    $validator = new AssessmentFixtureValidator;

    $broken = [
        'schema_version' => 1,
        'kind' => 'assessment',
        'key' => 'broken',
        'root_uuid' => '11111111-1111-4111-8111-111111111111',
        'title' => 'Broken',
        'currency' => 'EUR',
        'risks' => [[
            'ref' => 'risk-a',
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'title' => 'A risk',
            'impact' => ['mode' => 'quantitative', 'amount' => 100],
            // References an attack that is never declared.
            'attack_refs' => ['attack-nonexistent'],
        ]],
        'attacks' => [],
        'defences' => [],
    ];

    expect(fn () => $validator->validate($broken, 'inline-test'))->toThrow(SeederFixtureException::class);
});

test('a malformed catalog fixture fails loudly instead of silently', function () {
    $validator = new CatalogFixtureValidator;

    $broken = [
        'schema_version' => 1,
        'kind' => 'catalog',
        'key' => 'broken',
        'entries' => [[
            'ref' => 'risk-a',
            'kind' => 'risk',
            'title' => 'A risk',
            'defaults' => [],
            // A risk may only bundle 'attack' children.
            'children' => [['ref' => 'defence-b']],
        ], [
            'ref' => 'defence-b',
            'kind' => 'defence',
            'title' => 'A defence',
            'defaults' => [],
        ]],
    ];

    expect(fn () => $validator->validate($broken, 'inline-test'))->toThrow(SeederFixtureException::class);
});
