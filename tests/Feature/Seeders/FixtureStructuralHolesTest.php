<?php

use Database\Seeders\Support\AssessmentFixtureValidator;
use Database\Seeders\Support\CatalogFixtureValidator;
use Database\Seeders\Support\SeederFixtureException;

/*
 * W76 — the assertions the validators gained, and the holes they close.
 *
 * The package's brief: "a fixture that passes validation and seeds an
 * empty tree means the validator is not checking the thing that matters".
 * Each test below builds a fixture that is *valid under the W22 schema*
 * and would seed something a reader would call empty, and asserts that it
 * is now rejected. The positive control at the top proves the base fixture
 * these are mutated from passes, so a test cannot go green because the
 * whole thing was malformed for some unrelated reason.
 */

/**
 * A minimal assessment fixture that satisfies every rule. Each test below
 * breaks exactly one thing about it.
 *
 * @return array<string, mixed>
 */
function w76ValidAssessmentFixture(): array
{
    return [
        'schema_version' => 1,
        'kind' => 'assessment',
        'key' => 'holes-probe',
        'root_uuid' => '11111111-1111-4111-8111-111111111111',
        'title' => 'Probe',
        'description' => 'A minimal but complete fixture used to test the validator.',
        'currency' => 'EUR',
        'risks' => [[
            'ref' => 'risk-a',
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'title' => 'A risk',
            'description' => 'Something bad that could happen to the target.',
            'impact' => ['mode' => 'quantitative', 'amount' => 100],
            'attack_refs' => ['attack-a'],
        ]],
        'attacks' => [[
            'ref' => 'attack-a',
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'title' => 'An attack',
            'description' => 'How somebody would bring the risk about.',
            'likelihood' => ['mode' => 'quantitative', 'amount' => 0.5],
            'defence_refs' => ['defence-a'],
        ]],
        'defences' => [[
            'ref' => 'defence-a',
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'title' => 'A defence',
            'description' => 'What makes the attack less likely or less costly.',
            'status' => 'implemented',
            'links' => [
                'attack-a' => ['strength' => ['mode' => 'quantitative', 'fraction' => 0.5]],
            ],
        ]],
    ];
}

/**
 * The catalogue equivalent.
 *
 * @return array<string, mixed>
 */
function w76ValidCatalogFixture(): array
{
    return [
        'schema_version' => 1,
        'kind' => 'catalog',
        'key' => 'holes-probe',
        'entries' => [[
            'ref' => 'risk-a',
            'kind' => 'risk',
            'title' => 'A risk',
            'description' => 'Something bad that could happen.',
            'defaults' => [],
            'children' => [['ref' => 'attack-a', 'defaults' => []]],
        ], [
            'ref' => 'attack-a',
            'kind' => 'attack',
            'title' => 'An attack',
            'description' => 'How somebody would bring it about.',
            'defaults' => [],
            'children' => [['ref' => 'defence-a', 'defaults' => ['strength_level_code' => 'moderate']]],
        ], [
            'ref' => 'defence-a',
            'kind' => 'defence',
            'title' => 'A defence',
            'description' => 'What reduces it.',
            'defaults' => ['status' => 'implemented'],
        ]],
    ];
}

test('the probe fixtures both validate as they stand', function () {
    expect((new AssessmentFixtureValidator)->validate(w76ValidAssessmentFixture(), 'inline-test'))->toBeArray();
    expect((new CatalogFixtureValidator)->validate(w76ValidCatalogFixture(), 'inline-test'))->toBeArray();
});

// --- assessment fixtures --------------------------------------------------

test('an attack no risk lists is rejected instead of seeding an unreachable node', function () {
    $fixture = w76ValidAssessmentFixture();

    // Declared, valued, defended — and named by nothing. Under the W22
    // rules this passed: every downward reference resolves. It seeds a
    // `nodes` row with no `risk_attack` edge, so the assessment's node
    // count includes it and the tree the reader walks does not.
    $fixture['attacks'][] = [
        'ref' => 'attack-orphan',
        'uuid' => '55555555-5555-4555-8555-555555555555',
        'title' => 'An orphan attack',
        'description' => 'Reachable from no risk.',
        'likelihood' => ['mode' => 'quantitative', 'amount' => 0.2],
        'defence_refs' => ['defence-a'],
    ];
    $fixture['defences'][0]['links']['attack-orphan'] = [
        'strength' => ['mode' => 'quantitative', 'fraction' => 0.3],
    ];

    expect(fn () => (new AssessmentFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, "attack 'attack-orphan' is listed in no risk's attack_refs");
});

/*
 * The defence side of the same question, asserted as behaviour and
 * labelled with the rule that actually enforces it.
 *
 * W76 first added a symmetric "defence in no attack's defence_refs" loop
 * to assertNothingIsOrphaned() and mutation-testing found it dead:
 * neutering it left every test green, because a defence must have
 * non-empty `links` and the W22 cross-check already refuses a `links` key
 * whose attack does not name the defence back. The loop was deleted; this
 * test stays, because the *behaviour* is what matters and it should keep
 * being true however it is enforced.
 */
test('a defence no attack lists is rejected — by the W22 links cross-check, not by an orphan rule', function () {
    $fixture = w76ValidAssessmentFixture();

    $fixture['defences'][] = [
        'ref' => 'defence-orphan',
        'uuid' => '66666666-6666-4666-8666-666666666666',
        'title' => 'An orphan defence',
        'description' => 'Reachable from no attack.',
        'status' => 'todo',
        'links' => ['attack-a' => ['strength' => ['mode' => 'quantitative', 'fraction' => 0.1]]],
    ];

    expect(fn () => (new AssessmentFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, "defence 'defence-orphan' has links['attack-a']");

    // And the other way an author might write it — no links at all — is
    // refused too, so there is no gap between the two rules.
    $fixture['defences'][1]['links'] = [];
    expect(fn () => (new AssessmentFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, 'links must be a non-empty object');
});

test('a risk listing the same attack twice is rejected', function () {
    $fixture = w76ValidAssessmentFixture();
    $fixture['risks'][0]['attack_refs'] = ['attack-a', 'attack-a'];

    expect(fn () => (new AssessmentFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, "lists attack 'attack-a' more than once");
});

test('an attack listing the same defence twice is rejected', function () {
    $fixture = w76ValidAssessmentFixture();
    $fixture['attacks'][0]['defence_refs'] = ['defence-a', 'defence-a'];

    expect(fn () => (new AssessmentFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, "lists defence 'defence-a' more than once");
});

test('a node with no description is rejected, at every level of the fixture', function () {
    $cases = [
        'root' => function (array $f): array {
            unset($f['description']);

            return $f;
        },
        'risk' => function (array $f): array {
            unset($f['risks'][0]['description']);

            return $f;
        },
        'attack' => function (array $f): array {
            unset($f['attacks'][0]['description']);

            return $f;
        },
        'defence' => function (array $f): array {
            unset($f['defences'][0]['description']);

            return $f;
        },
    ];

    foreach ($cases as $level => $break) {
        expect(fn () => (new AssessmentFixtureValidator)->validate($break(w76ValidAssessmentFixture()), 'inline-test'))
            ->toThrow(SeederFixtureException::class, '', "a fixture with no {$level} description was accepted");
    }
});

test('a title or description longer than its column is rejected, not truncated mid-seed', function () {
    // 2.5.1: the standards templates' first drafts passed validation and
    // then failed first-run setup on "Data too long for column
    // 'description'". The limits are the nodes columns: 255 and 500
    // characters — characters, not bytes, so a string of multibyte
    // characters exactly at the limit must still pass.
    $atLimit = w76ValidAssessmentFixture();
    $atLimit['description'] = str_repeat('é', 500);
    $atLimit['defences'][0]['title'] = str_repeat('é', 255);
    (new AssessmentFixtureValidator)->validate($atLimit, 'inline-test');

    $cases = [
        'root description' => fn (array $f): array => [...$f, 'description' => str_repeat('x', 501)],
        'root title' => fn (array $f): array => [...$f, 'title' => str_repeat('x', 256)],
        'risk description' => function (array $f): array {
            $f['risks'][0]['description'] = str_repeat('x', 501);

            return $f;
        },
        'attack title' => function (array $f): array {
            $f['attacks'][0]['title'] = str_repeat('x', 256);

            return $f;
        },
        'defence description' => function (array $f): array {
            $f['defences'][0]['description'] = str_repeat('x', 501);

            return $f;
        },
    ];

    foreach ($cases as $field => $break) {
        expect(fn () => (new AssessmentFixtureValidator)->validate($break(w76ValidAssessmentFixture()), 'inline-test'))
            ->toThrow(SeederFixtureException::class, 'the column holds', "an over-long {$field} was accepted");
    }
});

// --- catalogue fixtures ---------------------------------------------------

test('a catalogue risk that bundles no attack is rejected', function () {
    $fixture = w76ValidCatalogFixture();
    $fixture['entries'][0]['children'] = [];

    expect(fn () => (new CatalogFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, 'bundles no attack');
});

test('a catalogue attack that bundles no defence is rejected', function () {
    $fixture = w76ValidCatalogFixture();
    $fixture['entries'][1]['children'] = [];

    expect(fn () => (new CatalogFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, 'bundles no defence');
});

test('a catalogue entry bundling the same child twice is rejected', function () {
    $fixture = w76ValidCatalogFixture();
    $fixture['entries'][1]['children'] = [
        ['ref' => 'defence-a', 'defaults' => ['strength_level_code' => 'moderate']],
        ['ref' => 'defence-a', 'defaults' => ['strength_level_code' => 'strong']],
    ];

    expect(fn () => (new CatalogFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, 'more than once');
});

test('a catalogue entry with no description is rejected', function () {
    foreach ([0, 1, 2] as $index) {
        $fixture = w76ValidCatalogFixture();
        unset($fixture['entries'][$index]['description']);

        expect(fn () => (new CatalogFixtureValidator)->validate($fixture, 'inline-test'))
            ->toThrow(SeederFixtureException::class, 'description is required');
    }
});

test('a catalogue entry whose description is only whitespace is rejected too', function () {
    $fixture = w76ValidCatalogFixture();
    $fixture['entries'][2]['description'] = "  \n\t ";

    expect(fn () => (new CatalogFixtureValidator)->validate($fixture, 'inline-test'))
        ->toThrow(SeederFixtureException::class, 'description is required');
});

// --- the shipped fixtures, held to the same rules -------------------------

test('every shipped fixture satisfies the rules W76 added, not just the W22 ones', function () {
    $assessment = new AssessmentFixtureValidator;
    $catalog = new CatalogFixtureValidator;

    foreach (glob(database_path('seeders/templates').'/*.json') ?: [] as $path) {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);

        if ($decoded['kind'] === 'assessment') {
            expect($assessment->validate($decoded, $path))->toBeArray();
        } else {
            expect($catalog->validate($decoded, $path))->toBeArray();
        }
    }
});
