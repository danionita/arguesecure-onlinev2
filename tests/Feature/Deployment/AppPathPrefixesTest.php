<?php

/**
 * An operator who moves the app to another hostname redirects the old host's
 * app paths in the reverse proxy, and so needs to know which first path
 * segments are the app's (docs/specs/deployment.md §4.1). The list there is
 * only worth publishing if it stays true, so this test holds it equal to the
 * registered routes in both directions.
 */

use Illuminate\Support\Facades\Route;

/**
 * Prefixes the app answers on that no registered route carries: the websocket
 * is proxied to Reverb by the app container's nginx, and built assets are
 * static files.
 */
const PREFIXES_WITHOUT_ROUTE = ['app', 'build'];

/** @return list<string> the first-column prefixes of the table in deployment.md §4.1 */
function documentedPrefixes(): array
{
    $spec = (string) file_get_contents(base_path('docs/specs/deployment.md'));
    $start = strpos($spec, '| Prefix | What it is |');
    expect($start)->not->toBeFalse('deployment.md §4.1 has the prefix table');

    // The table ends at the first blank line.
    $table = substr($spec, (int) $start);
    $table = substr($table, 0, (int) strpos($table, "\n\n"));

    preg_match_all('/^\| `([a-z-]+)` \|/m', $table, $matches);

    return $matches[1];
}

/** @return list<string> */
function registeredPrefixes(): array
{
    $prefixes = [];

    foreach (Route::getRoutes() as $route) {
        $first = explode('/', ltrim($route->uri(), '/'))[0];
        // Not a prefix an operator can name: the root, a parameter segment,
        // or a debug route the framework registers (`_ignition`).
        if ($first !== '' && ! str_starts_with($first, '{') && ! str_starts_with($first, '_')) {
            $prefixes[$first] = true;
        }
    }

    return array_keys($prefixes);
}

it('documents the first path segment of every registered route', function () {
    expect(array_values(array_diff(registeredPrefixes(), documentedPrefixes())))->toBe([]);
});

it('has a route behind every documented prefix, except the two nginx serves', function () {
    $documented = array_diff(documentedPrefixes(), PREFIXES_WITHOUT_ROUTE);

    expect(array_values(array_diff($documented, registeredPrefixes())))->toBe([]);
});

it('lists the same prefixes in the nginx example as in the table', function () {
    $spec = (string) file_get_contents(base_path('docs/specs/deployment.md'));
    preg_match('/location ~ \^\/\(([^)]+)\)\(\/\|\$\)/', $spec, $m);

    expect($m)->not->toBeEmpty('deployment.md §4.1 has the nginx location regex');

    $inExample = explode('|', $m[1]);
    $inTable = array_values(array_diff(documentedPrefixes(), ['s']));

    expect($inExample)->toEqualCanonicalizing($inTable);
});
