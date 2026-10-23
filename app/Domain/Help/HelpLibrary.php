<?php

namespace App\Domain\Help;

use App\Domain\Assistant\Help\Corpus;

/**
 * The fixed manifest of help pages (PLAN.md Phase 9 item 2, W23). A
 * hardcoded slug => title map, not a directory scan: the slug in the URL
 * never becomes a filesystem path built from request input (CLAUDE.md "no
 * dynamic column names from request input" — the same principle applies to
 * paths), and the manifest doubles as the fixed navigation order for the
 * help index and the "every markdown file the help index lists actually
 * exists and parses" test.
 *
 * Order here is the order help is presented in: the vocabulary first, then
 * how to build an assessment and what its numbers mean, then working with
 * other people and with templates, then history and the report, which are
 * reached for once an assessment has content. The keyboard map is a
 * reference and administration concerns few readers, so both come last.
 *
 * Every page here is also the assistant's help corpus, whole, on every
 * question ({@see Corpus}); a page added here is paid for in prompt
 * length, which `the corpus still fits the context window` in
 * tests/Feature/Assistant/HelpTest.php keeps under its ceiling.
 */
final class HelpLibrary
{
    /** @var array<string, string> slug => title */
    private const PAGES = [
        'concepts' => 'Concepts',
        'building' => 'Building an assessment',
        'quantification' => 'The quantification model',
        'collaboration' => 'Collaboration',
        'sharing' => 'Sharing and roles',
        'templates' => 'Templates',
        'history' => 'History and versions',
        'reading-a-report' => 'How to read a report',
        'keyboard' => 'Keyboard map',
        'signing-in' => 'Signing in',
        'administration' => 'Administration',
    ];

    /**
     * @return list<array{slug: string, title: string}>
     */
    public static function all(): array
    {
        return array_map(
            fn (string $slug, string $title): array => ['slug' => $slug, 'title' => $title],
            array_keys(self::PAGES),
            array_values(self::PAGES),
        );
    }

    public static function title(string $slug): ?string
    {
        return self::PAGES[$slug] ?? null;
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::PAGES);
    }

    public static function path(string $slug): string
    {
        return resource_path("markdown/help/{$slug}.md");
    }
}
