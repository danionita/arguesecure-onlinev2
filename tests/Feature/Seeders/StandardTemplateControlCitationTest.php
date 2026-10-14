<?php

use App\Models\Assessment;
use App\Models\Defence;
use App\Models\Node;
use App\Models\NodeReference;
use App\Models\User;
use Database\Seeders\SampleContentSeeder;
use Database\Seeders\Support\AssessmentFixtureValidator;
use Database\Seeders\Support\SeederFixtureException;

/*
 * W102 (PLAN-2.7 D64): every defence in a standards template whose title
 * names a control cites it as a structured `control` reference, and one that
 * names none cites nothing. The expectation is read off the title here,
 * independently of the references in the file, so a citation that drifts
 * from its title — or a new defence added without one — turns this red.
 * StandardTemplateCoverageTest is the other half: that the titles name every
 * control the standard has.
 */

/** @return array<string, string> fixture key => framework code */
function citingTemplates(): array
{
    return [
        'standard-iso27001-2022' => 'ISO27001-2022',
        'standard-iso42001-2023' => 'ISO42001-2023',
        'standard-eu-ai-act' => 'EUAIACT',
        'standard-gdpr' => 'GDPR',
        'standard-nis2' => 'NIS2',
        'standard-soc2' => 'SOC2',
        'standard-cis-controls-v8' => 'CIS8',
        'standard-nist-csf-2' => 'CSF2',
        'standard-eu-cra' => 'CRA',
    ];
}

/** The control a title names, in the stored form, or null. */
function controlNamedBy(string $framework, string $title): ?string
{
    $patterns = match ($framework) {
        'ISO27001-2022', 'ISO42001-2023' => [
            '/^Clause (\d+(?:\.\d+)+) /' => fn (array $m): string => $m[1],
            '/^(A(?:\.\d+){2,3}) /' => fn (array $m): string => $m[1],
            // The insurance transfer names the clause it shares risk under.
            '/under clause (\d+(?:\.\d+)+)\)$/' => fn (array $m): string => $m[1],
        ],
        'EUAIACT', 'GDPR', 'NIS2' => [
            '/^Art\. (\d+)((?:\(\d+\))?(?:\([a-z]+\))?) /' => fn (array $m): string => 'ART.'.$m[1].strtoupper($m[2]),
        ],
        'SOC2' => [
            '/^((?:CC|PI|A|C|P)\d\.\d+) /' => fn (array $m): string => $m[1],
            '/under (CC\d\.\d+)\)$/' => fn (array $m): string => $m[1],
        ],
        'CIS8' => [
            '/^(\d{1,2}\.\d{1,2}) /' => fn (array $m): string => $m[1],
        ],
        'CRA' => [
            '/^Art\. (\d+)\((\d+)\) /' => fn (array $m): string => "ART.{$m[1]}({$m[2]})",
            '/^Annex I Part (I|II)\((\d+)\)(?:\(([a-z])\))? /' => fn (array $m): string => "ANNEX.I.{$m[1]}({$m[2]})".(isset($m[3]) ? '('.strtoupper($m[3]).')' : ''),
        ],
        'CSF2' => [
            '/^([A-Z]{2}\.[A-Z]{2}-\d{2}) /' => fn (array $m): string => $m[1],
        ],
    };

    foreach ($patterns as $pattern => $format) {
        if (preg_match($pattern, $title, $m) === 1) {
            return $framework.':'.$format($m);
        }
    }

    return null;
}

test('every standards-template defence cites exactly the control its title names', function (string $key, string $framework) {
    $fixture = json_decode((string) file_get_contents(database_path("seeders/templates/{$key}.json")), true);

    $cited = 0;
    foreach ($fixture['defences'] as $defence) {
        $expected = controlNamedBy($framework, $defence['title']);
        $controls = array_values(array_filter(
            $defence['references'] ?? [],
            fn (array $r): bool => $r['kind'] === 'control',
        ));

        if ($expected === null) {
            expect($controls)->toBe([], "{$key}: '{$defence['title']}' names no control but cites one");

            continue;
        }

        expect(array_column($controls, 'identifier'))->toBe([$expected], "{$key}: '{$defence['title']}'");
        $cited++;
    }

    // Only the insurance transfers in CIS, GDPR, NIS2 and CSF 2.0 name no control.
    expect(count($fixture['defences']) - $cited)->toBe(in_array($key, ['standard-cis-controls-v8', 'standard-gdpr', 'standard-nis2', 'standard-nist-csf-2'], true) ? 1 : 0);
})->with(fn (): array => array_map(null, array_keys(citingTemplates()), array_values(citingTemplates())));

test('a seeded standards template carries its citations with the framework link', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $root = Node::withoutGlobalScopes()->where('uuid', 'c6000000-0000-4000-8000-000000000001')->sole();
    $assessment = Assessment::query()->findOrFail($root->assessment_id);
    $defence = Defence::withoutGlobalScopes()
        ->where('assessment_id', $assessment->id)
        ->where('title', 'like', 'Art. 21(2)(d) %')
        ->sole();

    $reference = NodeReference::query()->where('node_id', $defence->id)->sole();
    expect($reference->kind)->toBe('control')
        ->and($reference->identifier)->toBe('NIS2:ART.21(2)(D)')
        ->and($reference->url)->toBe('https://eur-lex.europa.eu/eli/dir/2022/2555/oj');
});

test('a fixture cannot cite a control on an attack, or cite one no framework has', function (string $where, string $identifier, string $message) {
    $fixture = json_decode((string) file_get_contents(database_path('seeders/templates/standard-nis2.json')), true);
    $citation = [['kind' => 'control', 'identifier' => $identifier]];
    if ($where === 'attack') {
        $fixture['attacks'][0]['references'] = $citation;
    } else {
        $fixture['defences'][0]['references'] = $citation;
    }

    expect(fn () => (new AssessmentFixtureValidator)->validate($fixture, 'standard-nis2.json'))
        ->toThrow(SeederFixtureException::class, $message);
})->with([
    ['attack', 'NIS2:ART.21(2)(D)', 'only a defence can cite a control'],
    ['defence', 'NIS2:ART.21(2)(d)', 'is not a FRAMEWORK:CONTROL citation'],
    ['defence', 'PCI:1.1', 'is not a FRAMEWORK:CONTROL citation'],
]);
