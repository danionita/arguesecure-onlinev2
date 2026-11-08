<?php

namespace App\Domain\Realtime;

/**
 * The hosts a browser may open the websocket from, for
 * config/reverb.php. Reverb matches the host of the connection's Origin
 * header against each entry, with `*` wildcards. Channel authorization still
 * decides who may join anything; this stops a page on another site from
 * opening a socket to the server in a visitor's browser.
 *
 * Pure, so a test can pass it strings: config files cannot be given an
 * environment.
 */
final class AllowedOrigins
{
    /**
     * @param  ?string  $configured  REVERB_ALLOWED_ORIGINS, comma-separated hosts, when set
     * @param  ?string  $appUrl  APP_URL
     * @return list<string> an empty list, never `*`, when neither names a host
     */
    public static function resolve(?string $configured, ?string $appUrl): array
    {
        if ($configured !== null && trim($configured) !== '') {
            return array_values(array_filter(
                array_map(fn (string $host): string => strtolower(trim($host)), explode(',', $configured)),
                fn (string $host): bool => $host !== '',
            ));
        }

        $url = trim((string) $appUrl);
        // An APP_URL written without its scheme ("arguesecure.example.com")
        // has no host to parse; read it as one rather than lock everyone out.
        $host = parse_url(str_contains($url, '://') ? $url : '//'.$url, PHP_URL_HOST);

        // A name (letters, digits, dots, hyphens, and underscores, which LAN and
        // Docker names use) or a bracketed IPv6 literal, as Origin carries it.
        return is_string($host) && preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9_.-]+)$/i', $host) === 1
            ? [strtolower($host)]
            : [];
    }
}
