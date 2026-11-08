<?php

/*
 * Facts the README, the specs and the release notes state more than once, or
 * that the code outgrows, are checked against their source here, so a
 * document cannot go on saying something the product no longer does.
 *
 * Two earlier slips drove this: the README said "Twelve are bundled" after
 * the fourteenth template arrived, and the 2.7.0 notes said single sign-on
 * had never met a real provider while three other documents said Keycloak
 * had been tested.
 */

const NUMBER_WORDS = [
    'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
    'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
    'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
    'nineteen' => 19, 'twenty' => 20, 'twenty-one' => 21, 'twenty-two' => 22, 'twenty-three' => 23,
    'twenty-four' => 24, 'twenty-five' => 25,
];

/** The sentence that names which identity providers have been tested; it must read the same everywhere. */
const TESTED_PROVIDERS_CLAIM = 'Only Keycloak has been tested';

function docsRoot(string $path = ''): string
{
    return dirname(__DIR__, 3).($path === '' ? '' : '/'.$path);
}

/** Drops comment markers and collapses line breaks, so a sentence wrapped differently still matches. */
function docsFlatten(string $text): string
{
    return preg_replace('/\s+/', ' ', str_replace('#', ' ', $text));
}

/** @return array<string, int> count of bundled assessment templates, by fixture-name prefix */
function bundledTemplateCounts(): array
{
    $counts = ['all' => 0, 'standard' => 0, 'archetype' => 0];
    foreach (glob(docsRoot('database/seeders/templates/*.json')) as $file) {
        $json = json_decode((string) file_get_contents($file), true);
        if (($json['kind'] ?? null) !== 'assessment') {
            continue;
        }
        $counts['all']++;
        $name = basename($file);
        foreach (['standard', 'archetype'] as $prefix) {
            if (str_starts_with($name, $prefix.'-')) {
                $counts[$prefix]++;
            }
        }
    }

    return $counts;
}

function numberBefore(string $text, string $pattern): ?int
{
    // Exactly one statement of the fact: a second match would hide which one is checked.
    if (preg_match_all($pattern, $text, $m) !== 1) {
        return null;
    }

    return NUMBER_WORDS[strtolower($m[1][0])] ?? null;
}

test('the README states how many templates are bundled', function () {
    $readme = docsFlatten((string) file_get_contents(docsRoot('README.md')));

    expect(numberBefore($readme, '/\b([A-Za-z-]+) are bundled\b/'))->toBe(bundledTemplateCounts()['all']);
});

test('the README states how many standards templates and system archetypes there are', function () {
    $readme = docsFlatten((string) file_get_contents(docsRoot('README.md')));
    $counts = bundledTemplateCounts();

    expect(numberBefore($readme, '/\b([A-Za-z-]+) standards templates\b/i'))->toBe($counts['standard']);
    expect(numberBefore($readme, '/\b([A-Za-z-]+) system archetypes\b/i'))->toBe($counts['archetype']);
});

test('the documents that name the tested sign-in providers say the same thing', function () {
    $withSso = array_filter(
        glob(docsRoot('docs/RELEASE-*.md')),
        function ($f) {
            $text = strtolower((string) file_get_contents($f));

            return str_contains($text, 'single sign-on') && str_contains($text, 'tested');
        },
    );
    usort($withSso, fn ($a, $b) => version_compare(
        preg_replace('/^RELEASE-|\.md$/', '', basename($a)),
        preg_replace('/^RELEASE-|\.md$/', '', basename($b)),
    ));
    $notes = end($withSso);

    $files = [
        'README.md' => docsRoot('README.md'),
        'docs/specs/deployment.md' => docsRoot('docs/specs/deployment.md'),
        '.env.example' => docsRoot('.env.example'),
        basename((string) $notes) => (string) $notes,
    ];

    foreach ($files as $label => $path) {
        expect(str_contains(docsFlatten((string) file_get_contents($path)), TESTED_PROVIDERS_CLAIM))
            ->toBeTrue("$label does not say \"".TESTED_PROVIDERS_CLAIM.'"');
    }
});
