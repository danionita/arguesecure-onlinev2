<?php

/*
 * The docs map (.claude/rules/docs-map.md) tells whoever changes the code
 * which documents describe it. A row that names a file that has been renamed
 * or removed sends them to nothing and the document it meant goes stale, so
 * every path the map names must exist, and every glob it loads on must still
 * match something.
 *
 * The map is a maintainers' file and is not in the published repository;
 * there the test has nothing to check and is skipped.
 */

function docsMapPath(): string
{
    return dirname(__DIR__, 3).'/.claude/rules/docs-map.md';
}

/** @return list<string> the globs under `paths:` in the map's front matter */
function docsMapGlobs(string $map): array
{
    preg_match('/\A---\n(.*?)\n---\n/s', $map, $front);
    preg_match_all('/^\s+-\s+"([^"]+)"\s*$/m', $front[1] ?? '', $globs);

    return $globs[1];
}

/**
 * Every backticked repository path in the table rows: anything with a slash,
 * a file extension or a name like Dockerfile. Globs are left to the
 * front-matter test.
 *
 * @return list<string>
 */
function docsMapNamedPaths(string $map): array
{
    $paths = [];
    foreach (preg_split('/\n/', $map) as $line) {
        if (! str_starts_with($line, '| ') || str_starts_with($line, '| You changed') || str_starts_with($line, '|---')) {
            continue;
        }
        preg_match_all('/`([^`\s]+)`/', $line, $m);
        foreach ($m[1] as $token) {
            $looksLikePath = str_contains($token, '/')
                || preg_match('/\.[a-z]{2,7}$/', $token) === 1   // README.md, .env.example
                || preg_match('/^[A-Z][a-z]+file$/', $token) === 1; // Dockerfile
            if (str_contains($token, '*') || ! $looksLikePath) {
                continue;
            }
            $paths[] = $token;
        }
    }

    return array_values(array_unique($paths));
}

/** The literal part of a glob, up to its first wildcard, with no trailing slash. */
function docsMapGlobPrefix(string $glob): string
{
    return rtrim(preg_split('/[*?\[]/', $glob)[0], '/');
}

test('every file and folder the docs map names exists', function () {
    if (! is_file(docsMapPath())) {
        $this->markTestSkipped('the docs map is a maintainers\' file and is not published');
    }

    $root = dirname(__DIR__, 3);
    $named = docsMapNamedPaths(file_get_contents(docsMapPath()));
    expect($named)->toContain('.env.example', 'Dockerfile', 'docs/specs/schema.md');

    $missing = array_values(array_filter($named, fn (string $p) => ! file_exists($root.'/'.rtrim($p, '/'))));
    expect($missing)->toBe([]);
});

test('every glob the docs map loads on still matches something', function () {
    if (! is_file(docsMapPath())) {
        $this->markTestSkipped('the docs map is a maintainers\' file and is not published');
    }

    $root = dirname(__DIR__, 3);
    $globs = docsMapGlobs(file_get_contents(docsMapPath()));
    expect($globs)->toContain('app/**');

    $dead = array_values(array_filter($globs, function (string $glob) use ($root) {
        $prefix = docsMapGlobPrefix($glob);
        if (str_contains($glob, '*') && ! str_contains($prefix, '/') && ! str_ends_with($glob, '/**')) {
            // A top-level pattern such as docker-compose*.yml: match it directly.
            return glob($root.'/'.$glob) === [];
        }

        return ! file_exists($root.'/'.$prefix);
    }));
    expect($dead)->toBe([]);
});
