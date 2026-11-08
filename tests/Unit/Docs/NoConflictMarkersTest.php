<?php

/*
 * A merge that was committed with its conflict markers still in a file ships
 * them: the specs and the help are published, and nothing else reads them.
 * This looks for the marker lines Git writes in every tracked source tree.
 */

test('no file holds a merge conflict marker', function () {
    $root = dirname(__DIR__, 3);
    $found = [];

    // The published files at the root and the workflows count too.
    // (No GLOB_BRACE: the checks image is musl, which lacks it.)
    $roots = [$root.'/.env.example'];
    foreach (['md', 'json', 'yml', 'yaml'] as $extension) {
        $roots = array_merge($roots, glob("$root/*.$extension") ?: []);
    }
    $roots = array_merge($roots, glob("$root/.github/workflows/*.yml") ?: []);

    foreach ($roots as $path) {
        foreach (is_file($path) ? (file($path) ?: []) : [] as $number => $line) {
            if (preg_match('/^(<<<<<<<|>>>>>>>) \S/', $line)) {
                $found[] = substr($path, strlen($root) + 1).':'.($number + 1);
            }
        }
    }

    foreach (['app', 'bootstrap', 'config', 'database', 'docs', 'resources', 'routes', 'tests', 'tools', '.claude'] as $dir) {
        if (! is_dir("$root/$dir")) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getSize() > 2_000_000 || str_contains($file->getPathname(), '/node_modules/')
                || str_ends_with($file->getFilename(), 'NoConflictMarkersTest.php')) {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match('/^(<<<<<<<|>>>>>>>) \S/', $line)) {
                    $found[] = substr($file->getPathname(), strlen($root) + 1).':'.($number + 1);
                }
            }
        }
    }

    expect($found)->toBe([]);
});
