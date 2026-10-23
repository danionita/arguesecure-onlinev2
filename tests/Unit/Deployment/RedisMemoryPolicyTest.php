<?php

use Symfony\Component\Yaml\Yaml;

/*
 * W109 (PLAN-2.7.md D72): the one Redis holds queued jobs, edit locks and,
 * before D71, sessions. Under `allkeys-lru` a full Redis dropped any of them
 * silently; under `noeviction` it refuses the write and the failure is seen.
 * The value is asserted, not the flag's presence, because `allkeys-lru` and
 * `volatile-lru` pass a presence check just as well.
 */

/** @return list<string> */
function redisCommand(): array
{
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker-compose.yml');

    return array_map('strval', $compose['services']['redis']['command'] ?? []);
}

function redisOption(string $name): ?string
{
    $command = redisCommand();
    $at = array_search('--'.$name, $command, true);

    return $at === false ? null : ($command[$at + 1] ?? null);
}

test('the compose Redis never evicts', function () {
    expect(redisOption('maxmemory-policy'))->toBe('noeviction');
});

test('the compose Redis sets the memory cap the deployment spec sizes', function () {
    expect(redisOption('maxmemory'))->toBe('128mb');
});

test('the compose Redis sets each option once', function () {
    // redis-server takes the last of a repeated option, so a second
    // --maxmemory-policy appended later would silently override the first.
    $options = array_values(array_filter(redisCommand(), fn (string $part) => str_starts_with($part, '--')));

    expect($options)->toBe(array_values(array_unique($options)));
});
