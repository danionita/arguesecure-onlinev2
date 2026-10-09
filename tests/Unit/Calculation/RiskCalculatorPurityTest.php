<?php

// §15 item 14: RiskCalculator (and its supporting Calculation classes) must
// have no Eloquent import, no I/O, no clock, no randomness. Two layers:
// Pest's arch() plugin checks the dependency graph (no Eloquent/Facades
// classes referenced anywhere in the namespace); a manual source scan below
// catches forbidden *function* calls (now(), rand(), file I/O, ...) that
// arch's class-dependency analysis does not see.
//
// CalculationCache is the single, deliberate exception. PLAN.md's W16 row
// places `Calculation\CalculationCache` in this namespace, and caching a
// result in Redis keyed on assessments.calc_version is Eloquent and I/O by
// definition — it is the impure boundary *around* the pure engine, not part
// of it. It is excluded by exact name rather than by loosening the rule, so a
// newly added impure file in this directory still fails these tests. The
// guarantee that matters is unchanged: RiskCalculator and everything it calls
// stay pure, which is what makes the engine snapshot-replayable and mirrorable
// in TypeScript.
const IMPURE_BY_DESIGN = ['CalculationCache.php'];

/** @return list<string> every Calculation source file that must be pure */
function pureCalculationFiles(): array
{
    $files = glob(dirname(__DIR__, 3).'/app/Domain/Calculation/*.php');

    return array_values(array_filter(
        $files === false ? [] : $files,
        fn (string $file): bool => ! in_array(basename($file), IMPURE_BY_DESIGN, true),
    ));
}

arch('the calculation engine does not depend on Eloquent, facades, or Carbon')
    ->expect('App\Domain\Calculation')
    ->not->toUse([
        'Illuminate\Database',
        'Illuminate\Support\Facades',
        'Illuminate\Contracts\Container',
        'Carbon\Carbon',
        'Carbon\CarbonImmutable',
    ])
    ->ignoring('App\Domain\Calculation\CalculationCache');

arch('the calculation engine classes are final')
    ->expect('App\Domain\Calculation')
    ->classes()
    ->toBeFinal();

test('no Calculation source file calls a clock, randomness, or I/O function', function () {
    $forbidden = [
        // clock
        'now(', 'today(', 'date(', 'time(', 'microtime(', 'strtotime(', 'Carbon::',
        // randomness
        'rand(', 'mt_rand(', 'random_int(', 'uniqid(', 'str_random(', 'Str::random(',
        // I/O
        'file_get_contents(', 'file_put_contents(', 'fopen(', 'curl_', 'DB::', 'Http::',
        // Eloquent-style calls (the arch test above already checks imports)
        '::query(', '::find(', '::create(',
    ];

    $files = pureCalculationFiles();
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = file_get_contents($file);

        foreach ($forbidden as $needle) {
            expect(str_contains($source, $needle))
                ->toBeFalse("{$file} contains forbidden token '{$needle}'.");
        }
    }
});

test('no Calculation source file imports an Eloquent or facade class', function () {
    $files = pureCalculationFiles();
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = file_get_contents($file);

        preg_match_all('/^use\s+([^;]+);/m', $source, $matches);

        foreach ($matches[1] as $import) {
            expect($import)
                ->not->toContain('Illuminate\Database')
                ->and($import)->not->toContain('Illuminate\Support\Facades')
                ->and($import)->not->toContain('App\Models');
        }
    }
});
