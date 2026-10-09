<?php

use App\Models\Scale;
use Database\Seeders\Support\SeederFixtureException;
use Database\Seeders\SystemScaleSeeder;
use Database\Seeders\TemplateAssessmentSeeder;

/*
 * W22's "every scale level code referenced by a fixture actually resolves
 * against the system default scales — a code that does not resolve must
 * fail loudly in a test, not silently at insert time" requirement.
 *
 * This walks every fixture's ValueSpecs (assessment risks/attacks/links,
 * catalog entries/edges) collecting every `*_level_code` it uses, seeds
 * the real system scales the same way `db:seed` would, and asserts every
 * collected code is present on the matching scale. If a fixture author
 * typos a code, this fails here — at test time — never silently at seed
 * time via TemplateAssessmentSeeder's own defensive throw.
 */
/** @return array{impact: list<string>, likelihood: list<string>, strength: list<string>} */
function collectLevelCodes(): array
{
    $codes = ['impact' => [], 'likelihood' => [], 'strength' => []];

    foreach (glob(database_path('seeders/templates/*.json')) ?: [] as $path) {
        $data = json_decode((string) file_get_contents($path), true);

        if (($data['kind'] ?? null) === 'assessment') {
            foreach ($data['risks'] as $risk) {
                if (($risk['impact']['mode'] ?? null) === 'qualitative') {
                    $codes['impact'][] = $risk['impact']['level_code'];
                }
            }
            foreach ($data['attacks'] as $attack) {
                if (($attack['likelihood']['mode'] ?? null) === 'qualitative') {
                    $codes['likelihood'][] = $attack['likelihood']['level_code'];
                }
            }
            foreach ($data['defences'] as $defence) {
                foreach ($defence['links'] as $link) {
                    if (($link['strength']['mode'] ?? null) === 'qualitative') {
                        $codes['strength'][] = $link['strength']['level_code'];
                    }
                }
            }
        }

        if (($data['kind'] ?? null) === 'catalog') {
            foreach ($data['entries'] as $entry) {
                $d = $entry['defaults'] ?? [];
                if (isset($d['impact_level_code'])) {
                    $codes['impact'][] = $d['impact_level_code'];
                }
                if (isset($d['likelihood_level_code'])) {
                    $codes['likelihood'][] = $d['likelihood_level_code'];
                }
                foreach ($entry['children'] ?? [] as $child) {
                    if (isset($child['defaults']['strength_level_code'])) {
                        $codes['strength'][] = $child['defaults']['strength_level_code'];
                    }
                }
            }
        }
    }

    return [
        'impact' => array_values(array_unique($codes['impact'])),
        'likelihood' => array_values(array_unique($codes['likelihood'])),
        'strength' => array_values(array_unique($codes['strength'])),
    ];
}

test('every level code any fixture uses resolves against the seeded system scales', function () {
    $scales = (new SystemScaleSeeder)->run();
    $codes = collectLevelCodes();

    // The fixtures should actually exercise this — a suite where nothing
    // uses a qualitative value would let a real typo through undetected.
    expect($codes['impact'])->not->toBeEmpty()
        ->and($codes['likelihood'])->not->toBeEmpty()
        ->and($codes['strength'])->not->toBeEmpty();

    foreach (['impact', 'likelihood', 'strength'] as $kind) {
        /** @var Scale $scale */
        $scale = $scales[$kind];
        $known = $scale->levels()->pluck('code')->all();

        foreach ($codes[$kind] as $code) {
            // toContain() would read the explanation as a second needle, so
            // this asserts membership explicitly and keeps the message.
            expect(in_array($code, $known, true))->toBeTrue(
                "fixture uses {$kind} level_code '{$code}', which is not one of the seeded system {$kind} scale's codes: ".implode(', ', $known)
            );
        }
    }
});

test('a level code that does not resolve against the loaded scale fails loudly, not silently', function () {
    // Simulates the defensive branch in
    // TemplateAssessmentSeeder::resolveValue() firing — the situation it
    // guards against (SystemScaleSeeder's bands and
    // AssessmentFixtureValidator's allowed-code constants drifting apart)
    // cannot be reached through the normal validate-then-seed path, since
    // the validator already rejects any code outside its allow-list before
    // a fixture ever reaches the seeder. Reflection exercises the seeder's
    // own fail-loudly branch directly, proving it throws rather than
    // silently writing a NULL level id.
    $seeder = new TemplateAssessmentSeeder;
    $method = new ReflectionMethod($seeder, 'resolveValue');
    $method->setAccessible(true);

    expect(fn () => $method->invoke(
        $seeder,
        ['mode' => 'qualitative', 'level_code' => 'catastrophic'],
        'impact_amount',
        ['moderate' => 1], // deliberately missing 'catastrophic'
    ))->toThrow(SeederFixtureException::class);
});
