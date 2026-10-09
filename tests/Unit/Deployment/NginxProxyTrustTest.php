<?php

/*
 * FIXED (2.6.2): behind SWAG, a visitor from the internet got a page whose
 * CSS and JS links were http://, blocked as mixed content. The image's nginx
 * runs real_ip, which rewrites $remote_addr to the visitor's address, and
 * fastcgi_params passes $remote_addr to php-fpm as REMOTE_ADDR. TrustProxies
 * (bootstrap/app.php, deployment.md §4) honours X-Forwarded-Proto only when
 * REMOTE_ADDR is a private address, so a public visitor's request was treated
 * as plain http. Every test and CI client is on a private address, which is
 * why nothing caught it before a real deployment did.
 *
 * The fix hands php-fpm the address nginx actually received the request from.
 * Laravel then trusts the proxy and resolves the visitor's IP itself from
 * X-Forwarded-For, so request()->ip() is unchanged.
 */

function nginxPhpLocation(): string
{
    $conf = file_get_contents(dirname(__DIR__, 3).'/docker/nginx.conf');

    preg_match('/location ~ \\\\\.php\$ \{(.*?)\n        \}/s', $conf, $match);

    return $match[1] ?? '';
}

test('php-fpm is given the proxy address as REMOTE_ADDR, not the client address real_ip resolved', function () {
    $location = nginxPhpLocation();

    expect($location)->not->toBe('', 'the PHP location block was not found in docker/nginx.conf');

    // fastcgi_param lines repeat a name to override it and php-fpm keeps the
    // last value, so the override must come after `include fastcgi_params`.
    preg_match_all('/^\s*(include fastcgi_params|fastcgi_param REMOTE_ADDR \S+);/m', $location, $lines);

    expect(end($lines[1]))->toBe('fastcgi_param REMOTE_ADDR $realip_remote_addr');
});
