<?php

/**
 * W111: the share token travels in the path of `/s/{token}`, so an access
 * log line that carries the request line carries a live read token to the
 * container's stdout and the reverse proxy's log. The shipped format must
 * log the masked URI, not `$request`. These assert the configuration's shape;
 * the masking was checked once against a real nginx (stock nginx:alpine with
 * this `map` and `log_format`): `/s/<43 characters>` logged as
 * `/s/[redacted]`, `/assessments/x?a=1` unchanged.
 */
function nginxHttpBlock(): string
{
    return file_get_contents(dirname(__DIR__, 3).'/docker/nginx.conf');
}

test('the access log format never logs the raw request line', function () {
    preg_match('/log_format\s+main\s+(.*?);/s', nginxHttpBlock(), $format);

    expect($format)->not->toBeEmpty()
        ->and($format[1])->not->toContain('$request"')
        ->and($format[1])->not->toContain('$request ')
        ->and($format[1])->not->toContain('$request_uri')
        ->and($format[1])->toContain('$log_uri');
});

test('the logged URI is masked for the share-link path', function () {
    preg_match('/map\s+\$request_uri\s+\$log_uri\s*\{(.*?)\}/s', nginxHttpBlock(), $map);

    expect($map)->not->toBeEmpty()
        ->and($map[1])->toContain('~^/+(?:s|%73)/[^?]* "/s/[redacted]"')
        ->and($map[1])->toContain('default "$request_uri"');
});

test('the access log is still written to stdout', function () {
    expect(nginxHttpBlock())->toContain('access_log /dev/stdout main;');
});
