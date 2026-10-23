<?php

/*
 * FIXED (W112): neither .env.example nor the image set LOG_CHANNEL, so
 * Laravel fell back to `stack` -> `single`: an unrotated
 * storage/logs/laravel.log on the data volume, at debug, invisible to
 * `docker compose logs`. The image now sets the channel and level itself, so
 * a deployment whose .env predates them gets them too. CI's Docker job boots
 * the image and checks the other half: a log line on the container's stderr
 * and no laravel.log.
 */

/**
 * The ENV assignments of the Dockerfile's runtime stage, continuation lines
 * joined. Only that stage: the build stages' environment never reaches a
 * running container.
 *
 * @return array<string, string>
 */
function runtimeStageEnv(): array
{
    $dockerfile = file_get_contents(dirname(__DIR__, 3).'/Dockerfile');
    $stage = preg_split('/^FROM \S+ AS runtime$/m', $dockerfile)[1] ?? '';
    $stage = preg_split('/^FROM /m', $stage)[0];
    $stage = preg_replace('/\\\\\n\s*/', ' ', $stage);

    $env = [];
    preg_match_all('/^ENV (.+)$/m', $stage, $lines);
    foreach ($lines[1] as $line) {
        preg_match_all('/(\w+)=(\S+)/', $line, $pairs, PREG_SET_ORDER);
        foreach ($pairs as [, $name, $value]) {
            $env[$name] = $value;
        }
    }

    return $env;
}

test('the image logs to stderr at info', function () {
    $env = runtimeStageEnv();

    expect($env['LOG_CHANNEL'] ?? null)->toBe('stderr')
        ->and($env['LOG_LEVEL'] ?? null)->toBe('info');
});

test('.env.example documents the values the image sets', function () {
    $example = file_get_contents(dirname(__DIR__, 3).'/.env.example');
    $env = runtimeStageEnv();

    // Commented out on purpose: the image is the one source of the default,
    // and an uncommented copy would hide a change to it from new deployments.
    foreach (['LOG_CHANNEL', 'LOG_LEVEL'] as $name) {
        expect($example)->toMatch('/^# '.$name.'='.preg_quote($env[$name] ?? '', '/').'$/m');
    }
});
