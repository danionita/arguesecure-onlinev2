<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/*
 * The loaded Reverb app is not open to every origin, and config/reverb.php
 * passes the environment through App\Domain\Realtime\AllowedOrigins (whose
 * rules are in tests/Unit/Realtime/AllowedOriginsTest.php). The config file is
 * required again with the environment set, because the loaded config only
 * shows what the test environment happened to contain.
 */
function reverbOriginsWith(?string $appUrl, ?string $override): array
{
    $saved = [Env::get('APP_URL'), Env::get('REVERB_ALLOWED_ORIGINS')];
    $set = function (string $name, ?string $value): void {
        Env::getRepository()->clear($name);
        if ($value !== null) {
            Env::getRepository()->set($name, $value);
        }
    };

    try {
        $set('APP_URL', $appUrl);
        $set('REVERB_ALLOWED_ORIGINS', $override);

        return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
    } finally {
        $set('APP_URL', $saved[0]);
        $set('REVERB_ALLOWED_ORIGINS', $saved[1]);
    }
}

test('the default is the host of APP_URL', function () {
    expect(reverbOriginsWith('https://a.example', null))->toBe(['a.example']);
});

test('REVERB_ALLOWED_ORIGINS replaces the default', function () {
    expect(reverbOriginsWith('https://a.example', 'b.example,*.c.example'))->toBe(['b.example', '*.c.example']);
});

test('an unusable APP_URL gives an empty list, not a wildcard', function () {
    expect(reverbOriginsWith('not a url', null))->toBe([]);
});

test('the loaded config is not open to every origin', function () {
    expect(config('reverb.apps.apps.0.allowed_origins'))->not->toContain('*');
});

test('starting Reverb with an empty list logs which variable to set', function () {
    Log::spy();
    config(['reverb.apps.apps.0.allowed_origins' => []]);

    event(new CommandStarting('reverb:start', new ArrayInput([]), new NullOutput));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'REVERB_ALLOWED_ORIGINS'));
});

test('starting Reverb with a usable list logs nothing', function () {
    Log::spy();
    config(['reverb.apps.apps.0.allowed_origins' => ['a.example']]);

    event(new CommandStarting('reverb:start', new ArrayInput([]), new NullOutput));

    Log::shouldNotHaveReceived('warning');
});
