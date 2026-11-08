<?php

use App\Domain\Realtime\AllowedOrigins;

/*
 * The websocket origin allow-list is the application's own host
 * unless the operator lists others, and is never `*`.
 */
test('the default is the host of APP_URL, without scheme, port or path', function (string $appUrl, string $host) {
    expect(AllowedOrigins::resolve(null, $appUrl))->toBe([$host]);
})->with([
    ['https://a.example', 'a.example'],
    ['https://Argue.Example:8443/sub/path', 'argue.example'],
    ['http://localhost', 'localhost'],
    ['argue.example', 'argue.example'],
    ['http://[::1]:8000', '[::1]'],
    ['http://argue_box:8000', 'argue_box'],
    ['http://192.168.1.2:8100', '192.168.1.2'],
    ['argue.example:8080/x', 'argue.example'],
]);

test('REVERB_ALLOWED_ORIGINS replaces the default, trimmed and lower-cased', function () {
    expect(AllowedOrigins::resolve(' A.example , b.example,, *.c.example ', 'https://other.example'))
        ->toBe(['a.example', 'b.example', '*.c.example']);
});

test('a blank override falls back to APP_URL', function () {
    expect(AllowedOrigins::resolve('  ', 'https://a.example'))->toBe(['a.example']);
});

test('nothing usable yields an empty list, never a wildcard', function (?string $configured, ?string $appUrl) {
    expect(AllowedOrigins::resolve($configured, $appUrl))->toBe([]);
})->with([
    [null, null],
    [null, ''],
    [null, 'not a url'],
    [', ,', 'not a url'],
    [null, '://'],
]);
