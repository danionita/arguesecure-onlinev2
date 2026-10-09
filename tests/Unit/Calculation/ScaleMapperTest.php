<?php

use App\Domain\Calculation\ScaleMapper;
use Tests\Unit\Calculation\Support\WorkedExampleGraph;

// §15 item 5: round trip is lossless for every level of every scale.
test('toLevel(toNumber(level)) round-trips for every level', function () {
    $mapper = new ScaleMapper;
    $levels = WorkedExampleGraph::scaleLevels();

    $byKind = [];
    foreach ($levels as $level) {
        $byKind[$level['kind']][] = $level;
    }

    foreach ($byKind as $kind => $levelsOfKind) {
        $unit = $levelsOfKind[0]['unit'];

        foreach ($levelsOfKind as $level) {
            $number = $mapper->toNumber($level);
            $roundTripped = $mapper->toLevel($number, $levelsOfKind, $unit);

            expect($roundTripped)->not->toBeNull();
            expect($roundTripped['id'])->toBe($level['id']);
        }
    }
});

// §8.2 boundary convention: [lower, upper) — a value exactly on a boundary
// lands in the *higher* band.
test('a value exactly on a boundary lands in the higher band', function () {
    $mapper = new ScaleMapper;
    $levels = array_values(array_filter(WorkedExampleGraph::scaleLevels(), fn (array $l) => $l['kind'] === 'impact'));

    // 10,000 is minor's upper_bound and moderate's lower_bound.
    $level = $mapper->toLevel(10000.0, $levels, 'currency');

    expect($level['code'])->toBe('moderate');
});

// §8.2 fraction scale top band is [lower, 1] inclusive.
test('the fraction scale top band is inclusive of 1.0', function () {
    $mapper = new ScaleMapper;
    $levels = array_values(array_filter(WorkedExampleGraph::scaleLevels(), fn (array $l) => $l['kind'] === 'strength'));

    $level = $mapper->toLevel(1.0, $levels, 'fraction');

    expect($level['code'])->toBe('near_certain');
});

// x = ⊥ (null) maps to null, never the lowest band.
test('a null value maps to null, not the lowest band', function () {
    $mapper = new ScaleMapper;
    $levels = array_values(array_filter(WorkedExampleGraph::scaleLevels(), fn (array $l) => $l['kind'] === 'impact'));

    expect($mapper->toLevel(null, $levels, 'currency'))->toBeNull();
});

// §8.3 / §15 item 13: unit mismatch must throw, not silently band the
// wrong quantity.
test('toLevel throws when the supplied unit disagrees with the scale', function () {
    $mapper = new ScaleMapper;
    $levels = array_values(array_filter(WorkedExampleGraph::scaleLevels(), fn (array $l) => $l['kind'] === 'impact'));

    $mapper->toLevel(50000.0, $levels, 'rate_per_year');
})->throws(InvalidArgumentException::class);
