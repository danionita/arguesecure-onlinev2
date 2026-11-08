<?php

namespace App\Domain\Help;

use Illuminate\Support\Facades\File;

/**
 * The administrators' help: a second, fixed manifest, kept apart from
 * {@see HelpLibrary} for two reasons. It is served only behind the `admin`
 * middleware, because it describes controls and settings nobody else sees. And
 * it is not part of the assistant's corpus, which is the whole of the public
 * help on every question and has a token ceiling; nothing here is paid for in
 * prompt length.
 *
 * Like the public manifest it is a hardcoded slug => title map: the slug in
 * the URL never becomes a filesystem path.
 */
final class AdminHelpLibrary
{
    /** @var array<string, string> slug => title */
    private const PAGES = [
        'users' => 'Users',
        'settings' => 'Settings and sign-in',
        'email' => 'Email',
        'assistant' => 'AI assistant',
        'security-log' => 'Security log',
    ];

    /** Shortest query that is searched; one letter matches nearly everything. */
    public const MIN_QUERY = 2;

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

    public static function path(string $slug): string
    {
        return resource_path("markdown/admin-help/{$slug}.md");
    }

    /**
     * What a reader sees, as one line of text: link targets, heading marks,
     * bold and code marks are gone, so a query matches only visible words and
     * a snippet never shows markup. Underscores stay: they are in names like
     * `APP_NOTICE`.
     */
    private static function plain(string $markdown): string
    {
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $markdown);
        $text = (string) preg_replace('/^#+\s*/mu', '', $text);
        $text = str_replace(['**', '`'], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Pages that contain every word of the query, best first (most
     * occurrences), each with one plain-text snippet around the first match.
     * Case-insensitive, literal (the query is never a pattern), over the
     * markdown source, so a heading or a button name in bold matches.
     *
     * @return list<array{slug: string, title: string, snippet: string}>
     */
    public static function search(string $query): array
    {
        $words = array_values(array_unique(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [],
            fn (string $w): bool => $w !== '',
        )));

        if ($words === [] || mb_strlen(trim($query)) < self::MIN_QUERY) {
            return [];
        }

        $hits = [];

        foreach (self::PAGES as $slug => $title) {
            if (! File::exists(self::path($slug))) {
                continue;
            }

            $text = self::plain(File::get(self::path($slug)));
            $score = 0;
            $first = null;

            foreach ($words as $word) {
                $count = 0;
                $at = mb_stripos($text, $word);

                if ($at === false) {
                    continue 2;
                }

                $first = $first === null ? $at : min($first, $at);

                for ($from = $at; $from !== false; $from = mb_stripos($text, $word, $from + mb_strlen($word))) {
                    $count++;
                }

                $score += $count;
            }

            $start = max(0, (int) $first - 60);
            $chunk = mb_substr($text, $start, 160);

            $hits[] = [
                'slug' => $slug,
                'title' => $title,
                'snippet' => ($start > 0 ? '… ' : '').trim($chunk).($start + 160 < mb_strlen($text) ? ' …' : ''),
                'score' => $score,
            ];
        }

        usort($hits, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(
            fn (array $h): array => ['slug' => $h['slug'], 'title' => $h['title'], 'snippet' => $h['snippet']],
            $hits,
        );
    }
}
