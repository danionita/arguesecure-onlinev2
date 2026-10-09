<?php

/**
 * W24 — the OWASP items that are *absences*, and so cannot be proved by
 * exercising a route: security.md §7 (input handling), §2 (no secret in
 * the tree), §6 (nothing loads from a CDN), and CLAUDE.md's `env()` rule.
 *
 * A behavioural test can show that one endpoint is safe. Only a scan can
 * show that a bad pattern appears **nowhere**, which is the actual claim
 * each of these rules makes. Each defect below is a legacy one with a
 * number in security.md §1, so the scan is not speculative: it is
 * regression cover for something this codebase demonstrably did before.
 *
 * Scans are rooted at base_path() and use forward-slash comparisons
 * throughout — CI is Linux and case-sensitive, this repo is developed on
 * Windows.
 */

use App\Domain\Help\MarkdownRenderer;
use Symfony\Component\Finder\Finder;

/**
 * Every PHP file under the given project-relative directories.
 *
 * @param  list<string>  $directories
 * @return array<string, string> relative path => contents
 */
function sourceFiles(array $directories, string $pattern = '*.php'): array
{
    $existing = array_values(array_filter(
        $directories,
        fn (string $dir): bool => is_dir(base_path($dir)),
    ));

    if ($existing === []) {
        return [];
    }

    $finder = (new Finder)
        ->files()
        ->name($pattern)
        ->in(array_map(fn (string $dir): string => base_path($dir), $existing));

    $files = [];
    $root = str_replace('\\', '/', base_path()).'/';

    foreach ($finder as $file) {
        $absolute = str_replace('\\', '/', $file->getPathname());
        $relative = str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;

        $files[$relative] = (string) $file->getContents();
    }

    return $files;
}

/**
 * Strips comments and docblocks so a scan cannot be tripped by a file
 * *discussing* the pattern it is banned from using — which several here
 * deliberately do (app/Models/Node.php's docblock names class_exists() to
 * explain why it is not used; this very file quotes `env(`).
 */
function withoutComments(string $php): string
{
    return preg_replace('#/\*.*?\*/|//[^\n]*|(?<!:)\#[^\n]*#s', '', $php) ?? $php;
}

it('never calls $request->all(), anywhere', function () {
    // security.md §7 / legacy defect #8: "Never $request->all(). Use
    // $request->validated() or an explicit only([...])." The legacy
    // NodeController wrote an attacker-chosen column bounded only by
    // $fillable — which included Defence::tree_id and Tree::public.
    $offenders = [];

    foreach (sourceFiles(['app', 'routes']) as $path => $contents) {
        // `->all()` on a Collection is fine and common; only the Request
        // forms matter. `$request->all(`, `Request::all(`, `$this->all(`
        // inside a FormRequest.
        if (preg_match('/(\$request|Request::|\$this)->all\(\)/', $contents) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('never calls env() outside a config file', function () {
    // CLAUDE.md: env() "returns null once config:cache runs, which is a
    // latent production bug the legacy app shipped". config/ is the only
    // legitimate caller.
    // `tests` is deliberately out of scope: the rule exists because
    // `config:cache` freezes config and leaves env() returning null at
    // runtime, and the suite never runs against cached config. Scanning it
    // would only ever flag files quoting the rule, as this one does.
    $offenders = [];

    foreach (sourceFiles(['app', 'routes', 'bootstrap', 'database']) as $path => $contents) {
        // Comments stripped first, then a boundary match so getenv() and
        // $this->env() are not mistaken for it.
        if (preg_match('/(?<![\w:>$])env\s*\(/', withoutComments($contents)) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('instantiates no class and selects no column from request input', function () {
    // Legacy defects #7 and #8: NodeController took an attacker-supplied
    // `type` string into class_exists() + ReflectionClass::newInstance(),
    // and wrote an attacker-chosen column via $model->fill([$field =>
    // $value]). security.md §7 replaces both with hardcoded maps and typed
    // endpoints.
    // The two mechanisms the legacy defect actually used are banned
    // outright. Neither appears anywhere in v2, and neither has a
    // legitimate use in this codebase.
    $banned = [];

    foreach (sourceFiles(['app', 'routes']) as $path => $contents) {
        $code = withoutComments($contents);

        foreach (['/\bclass_exists\s*\(/', '/new\s+ReflectionClass\s*\(/', '/->fill\(\s*\[\s*\$/'] as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $banned[] = $path;
                break;
            }
        }
    }

    expect($banned)->toBe([]);

    // `new $class` is a different matter: it is legitimate, and this
    // codebase uses it five times — but only ever on a class name that
    // came out of a hardcoded map or a `match` with a throwing `default`,
    // never out of request input. That distinction is the whole of defect
    // #7, and a regex cannot see it, so the known-safe sites are listed
    // here by hand with what resolves each one.
    //
    // A SIXTH SITE APPEARING HERE IS A REVIEW GATE, NOT A TEST FAILURE TO
    // SILENCE: check that its class name cannot be influenced by anything
    // a user sends, then add it with the same one-line justification.
    $reviewed = [
        // static::$typeMap, keyed off the `type` column read back from the
        // database — the STI hydration path, not an input path.
        'app/Models/Node.php',
        // match($kind) over the four catalogue kinds, default => throw.
        'app/Domain/Structure/AssessmentCloner.php',
        // A map of undo action => applier class, throwing on an unknown
        // action before it ever reaches `new`.
        'app/Domain/History/Commands/CommandDispatcher.php',
        // EdgeResolver::meta()'s hardcoded ['model' => RiskAttack::class]
        // / AttackDefence::class pair.
        'app/Domain/History/Commands/RelinkApplier.php',
        'app/Domain/History/Commands/ReparentEdgeApplier.php',
    ];

    $dynamic = [];

    foreach (sourceFiles(['app', 'routes']) as $path => $contents) {
        if (preg_match('/\bnew\s+\$/', withoutComments($contents)) === 1) {
            $dynamic[] = $path;
        }
    }

    expect($dynamic)->toEqualCanonicalizing($reviewed);
});

it('holds no secret, key or credential literal in the tree', function () {
    // security.md §2. The two committed APP_KEYs are burned and the
    // history purge was declined (§2.1, settled — not reopened here).
    // What this asserts is the forward-looking half: nothing in the v2
    // working tree introduces a new one. security.md §13 asks for a
    // gitleaks step in CI; this is the in-suite floor under it.
    $offenders = [];

    $patterns = [
        'laravel app key' => '/base64:[A-Za-z0-9+\/]{40,}={0,2}/',
        'bcrypt/argon hash' => '/\$(2y|argon2i?d?)\$[.\/A-Za-z0-9$]{20,}/',
        'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'aws access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'github token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
        'slack token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
    ];

    $files = [
        ...sourceFiles(['app', 'config', 'routes', 'bootstrap', 'database', 'tests', 'docker']),
        ...sourceFiles(['resources'], '*.{ts,vue,js,css}'),
        ...sourceFiles(['docs'], '*.md'),
    ];

    foreach ($files as $path => $contents) {
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = $label.' in '.$path;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps every env file out of version control on this branch', function () {
    // security.md §2 steps 2-3. `.env` and `.env-deploy` remain tracked on
    // `master` (v1) and are untracked at cutover — that is §2.1's
    // "still outstanding" note and is deliberately not this branch's
    // problem. What must hold *here* is that v2 tracks none of them.
    $tracked = [];
    $gitignore = (string) file_get_contents(base_path('.gitignore'));

    foreach (['.env', '.env-deploy', '.env.testing', '.env.local'] as $file) {
        // .env.example is the one that must be tracked, and is covered by
        // the negative below.
        if (! str_contains($gitignore, '.env.*') && ! str_contains($gitignore, $file)) {
            $tracked[] = $file;
        }
    }

    expect($tracked)->toBe([])
        ->and($gitignore)->toContain('!.env.example');
});

it('references no external host from any template, stylesheet or bundle entry', function () {
    // security.md §6: "Everything is self-hosted. No CDN, no external
    // font, no external icon set, no remote image." The legacy app pulled
    // socket.io from cdn.socket.io with no SRI.
    //
    // Only *fetchable* references count — a URL inside a comment (the
    // Lucide licence notice in icons.generated.ts, the MDN link in
    // config/session.php) is documentation, not a network dependency. So
    // this looks for the syntax that actually loads something.
    $offenders = [];

    $loaders = [
        'html src/href' => '/(?:src|href)\s*=\s*["\']https?:\/\/([^"\'\/]+)/i',
        'css url()' => '/url\(\s*["\']?https?:\/\/([^"\'\)]+)/i',
        'css @import' => '/@import\s+(?:url\()?\s*["\']https?:\/\/([^"\']+)/i',
        'js import' => '/\bimport\s[^;]*?["\']https?:\/\/([^"\']+)/i',
    ];

    $files = [
        ...sourceFiles(['resources/views'], '*.blade.php'),
        ...sourceFiles(['resources/css'], '*.css'),
        ...sourceFiles(['resources/js'], '*.{ts,vue,js}'),
    ];

    foreach ($files as $path => $contents) {
        foreach ($loaders as $label => $pattern) {
            if (preg_match($pattern, $contents, $match) === 1) {
                $offenders[] = $label.' -> '.$match[1].' in '.$path;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('renders no off-origin asset reference on a real page', function () {
    // The scan above covers source; this covers what the browser is
    // actually handed, which is the claim that matters. Any src= or href=
    // in the delivered HTML must be same-origin or a relative path.
    $html = (string) $this->get('/')->getContent();

    preg_match_all('/(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $html, $matches);

    $offOrigin = array_values(array_filter(
        $matches[1],
        function (string $url): bool {
            $host = parse_url($url, PHP_URL_HOST);

            return $host !== null && $host !== false && $host !== request()->getHost();
        },
    ));

    expect($offOrigin)->toBe([]);
});

it('renders markdown with raw HTML stripped and unsafe links dropped', function () {
    // security.md §7: "Markdown in comments is rendered through
    // a hardened parser with raw HTML disabled, then sanitized." Both
    // options differ from league/commonmark's own defaults (which are
    // `allow` and `true` respectively), so leaving them implicit would be
    // an XSS hole rather than a style choice.
    $rendered = MarkdownRenderer::toHtml(
        "<script>alert(1)</script>\n\n[click](javascript:alert(1))\n\n<img src=x onerror=alert(1)>",
    );

    expect($rendered)
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('onerror');
});
