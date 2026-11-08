<?php

/*
 * The public repository is read by people who have no plans: a comment that
 * says "(W83)", "see PLAN-2.6.md" or "per CLAUDE.md" sends them to a document
 * that is not there. Everything below is copied to the public repository, so
 * none of it may carry a work package id (W12), a decision id (D24), a plan
 * reference, a pointer to the contributor instructions, or talk of which
 * package owned a file. The files the export drops (plans, research, the
 * Claude files) are not scanned.
 */

const PUBLISHED_ID_PATTERN = '/\b[WD]\d{1,3}[a-z]?\b|PLAN[-.][A-Za-z0-9.-]*'
    ."|CLAUDE\.md|file ownership|this package|package's (?:brief|file|own)|a later package|another package"
    ."|\b(?:the|task|performance|sharing|snapshot|settings|feature's|real-time|project) brief\b/";

/** D1..D4 name the defences of the worked example in calculation-engine.md §14. */
const WORKED_EXAMPLE_FILES = [
    'docs/specs/calculation-engine.md',
    'tests/Feature/Report/ReportContentTest.php',
    'tests/Unit/Calculation/CalculationRulesTest.php',
    'tests/Unit/Calculation/GoldenVectorTest.php',
    'tests/Unit/Calculation/WorkedExampleTest.php',
    'tests/fixtures/README.md',
    'tests/fixtures/calculation-vectors.json',
    'tests/js/calc/rules.spec.ts',
    'tests/js/calc/workedExample.spec.ts',
    'tests/js/report/AttributionChart.spec.ts',
];

/**
 * What the public export drops: the EXCLUDE list in the export script when it
 * is present (it is itself never published), plus the plans and prompts it
 * deletes from docs/.
 *
 * @return list<string> path prefixes, and docs/ file patterns, to skip
 */
function unpublishedPaths(string $root): array
{
    $paths = [];
    $script = $root.'/tools/release/publish-public.sh';
    if (is_file($script) && preg_match('/^EXCLUDE=\((.*?)\)$/m', (string) file_get_contents($script), $m)) {
        $paths = preg_split('/\s+/', trim($m[1])) ?: [];
    }

    return $paths;
}

/** @return list<string> repository-relative paths of the published text files */
function publishedTextFiles(): array
{
    $root = dirname(__DIR__, 3);
    $tracked = shell_exec('git -C '.escapeshellarg($root).' ls-files -z 2>/dev/null');
    $all = $tracked ? array_filter(explode("\0", $tracked)) : [];

    if ($all === []) {
        // No git metadata (a source archive): the files on disk are the tree.
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            // Untracked local state is not part of the tree: dependencies,
            // build output, caches and the .env files of a working copy.
            $local = '#^(vendor|node_modules|storage|public/build|bootstrap/cache|coverage|\.git|\.phpunit\.cache)/#';
            if ($file->isFile() && ! preg_match($local, $path) && ! preg_match('#(^|/)\.env(?!\.example$)#', $path)) {
                $all[] = $path;
            }
        }
    }

    $unpublished = unpublishedPaths($root);
    $files = [];
    foreach ($all as $path) {
        $dropped = preg_match('#^docs/(PLAN|PROMPT-)[^/]*$#', $path)
            || $path === 'tests/Unit/Release/NoPackageIdsInPublishedFilesTest.php'
            || preg_match('#\.(lock|png|jpg|svg|ico|woff2?|gguf)$|package-lock\.json$#', $path);
        foreach ($unpublished as $prefix) {
            $dropped = $dropped || $path === $prefix || str_starts_with($path, $prefix.'/');
        }
        $full = $root.'/'.$path;
        if (! $dropped && is_file($full) && filesize($full) < 2_000_000) {
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

test('no published file carries a work package id, a decision id or a plan reference', function () {
    $root = dirname(__DIR__, 3);
    $hits = [];

    foreach (publishedTextFiles() as $path) {
        if (in_array($path, WORKED_EXAMPLE_FILES, true)) {
            continue;
        }
        foreach (file($root.'/'.$path, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
            if (preg_match(PUBLISHED_ID_PATTERN, $line, $m)) {
                $hits[] = $path.':'.($n + 1).' '.$m[0];
            }
        }
    }

    expect($hits)->toBe([]);
});

test('the worked-example files mention only the defence names D1 to D4', function () {
    $root = dirname(__DIR__, 3);
    $other = [];

    foreach (WORKED_EXAMPLE_FILES as $path) {
        if (! is_file($root.'/'.$path)) {
            continue;
        }
        foreach (file($root.'/'.$path, FILE_IGNORE_NEW_LINES) as $n => $line) {
            preg_match_all(PUBLISHED_ID_PATTERN, $line, $all);
            foreach ($all[0] as $match) {
                if (! preg_match('/^D[1-4]$/', $match)) {
                    $other[] = $path.':'.($n + 1).' '.$match;
                }
            }
        }
    }

    expect($other)->toBe([]);
});
