<?php

use App\Domain\Structure\ScaleValidationException;
use App\Domain\Structure\ScaleValidator;
use App\Models\Scale;
use App\Models\ScaleLevel;

beforeEach(function () {
    $this->validator = new ScaleValidator;
});

/** @return array<ScaleLevel> */
function makeLevels(Scale $scale, array $bands): array
{
    $levels = [];
    foreach ($bands as $index => [$code, $lower, $upper, $representative]) {
        $levels[] = ScaleLevel::factory()->create([
            'scale_id' => $scale->id,
            'ordinal' => $index + 1,
            'code' => $code,
            'label' => ucfirst($code),
            'lower_bound' => $lower,
            'upper_bound' => $upper,
            'representative' => $representative,
        ]);
    }

    return $levels;
}

it('accepts a valid contiguous currency scale, open-ended top band', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $levels = makeLevels($scale, [
        ['low', 0, 1_000, 500],
        ['high', 1_000, null, 5_000],
    ]);

    expect($this->validator->validate($levels, 'currency'))->toBe([]);
});

it('accepts a fraction scale whose top band closes at 1 inclusive', function () {
    $scale = Scale::factory()->create(['unit' => 'fraction']);
    $levels = makeLevels($scale, [
        ['weak', 0, 0.5, 0.25],
        ['strong', 0.5, 1, 1.0],
    ]);

    expect($this->validator->validate($levels, 'fraction'))->toBe([]);
});

it('rejects non-contiguous ordinals', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $levels = makeLevels($scale, [
        ['low', 0, 1_000, 500],
        ['high', 1_000, null, 5_000],
    ]);
    $levels[1]->ordinal = 3;

    $errors = $this->validator->validate($levels, 'currency');

    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('contiguous');
});

it('rejects a first level that does not start at zero', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $levels = makeLevels($scale, [
        ['low', 10, 1_000, 500],
    ]);

    $errors = $this->validator->validate($levels, 'currency');

    expect(implode(' ', $errors))->toContain('lower_bound 0');
});

it('rejects a gap between bands', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $levels = makeLevels($scale, [
        ['low', 0, 1_000, 500],
        ['high', 2_000, null, 5_000],
    ]);

    $errors = $this->validator->validate($levels, 'currency');

    expect(implode(' ', $errors))->toContain('no gap or overlap');
});

it('rejects an overlap between bands', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $levels = makeLevels($scale, [
        ['low', 0, 1_000, 500],
        ['high', 500, null, 5_000],
    ]);

    $errors = $this->validator->validate($levels, 'currency');

    expect(implode(' ', $errors))->toContain('no gap or overlap');
});

it('rejects a non-fraction top band with a closed upper_bound', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $levels = makeLevels($scale, [
        ['low', 0, 1_000, 500],
    ]);
    $levels[0]->upper_bound = 1_000;

    $errors = $this->validator->validate($levels, 'currency');

    expect(implode(' ', $errors))->toContain('open-ended');
});

it('rejects a fraction top band that does not close at exactly 1', function () {
    $scale = Scale::factory()->create(['unit' => 'fraction']);
    $levels = makeLevels($scale, [
        ['weak', 0, 0.5, 0.25],
        ['strong', 0.5, 0.9, 0.7],
    ]);

    $errors = $this->validator->validate($levels, 'fraction');

    expect(implode(' ', $errors))->toContain('close at upper_bound 1');
});

it('rejects a representative below its band lower_bound', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $levels = makeLevels($scale, [
        ['low', 100, 1_000, 50],
    ]);
    $levels[0]->lower_bound = 100;

    $errors = $this->validator->validate($levels, 'currency');

    expect(implode(' ', $errors))->toContain('must be >=');
});

it('rejects a representative at or above its band upper_bound (exclusive top)', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $levels = makeLevels($scale, [
        ['low', 0, 1_000, 1_000],
    ]);

    $errors = $this->validator->validate($levels, 'currency');

    expect(implode(' ', $errors))->toContain('must fall within its band');
});

it('accepts a fraction top band representative exactly at the inclusive upper 1', function () {
    $scale = Scale::factory()->create(['unit' => 'fraction']);
    $levels = makeLevels($scale, [
        ['weak', 0, 0.5, 0.25],
        ['strong', 0.5, 1, 1.0],
    ]);

    expect($this->validator->validate($levels, 'fraction'))->toBe([]);
});

it('rejects an empty level set', function () {
    expect($this->validator->validate([], 'currency'))->toBe(['A scale must have at least one level.']);
});

it('assertValid throws ScaleValidationException when invalid, and is silent when valid', function () {
    $scale = Scale::factory()->create(['unit' => 'currency']);
    $badLevels = makeLevels($scale, [
        ['low', 10, 1_000, 500],
    ]);

    expect(fn () => $this->validator->assertValid($badLevels, 'currency'))
        ->toThrow(ScaleValidationException::class);

    $goodScale = Scale::factory()->create(['unit' => 'currency']);
    $goodLevels = makeLevels($goodScale, [
        ['low', 0, 1_000, 500],
        ['high', 1_000, null, 5_000],
    ]);

    $this->validator->assertValid($goodLevels, 'currency');
    expect(true)->toBeTrue(); // reached without throwing
});
