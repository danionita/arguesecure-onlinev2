<?php

namespace App\Domain\Assistant\Help;

use App\Domain\Help\HelpLibrary;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The documentation job A answers from (PLAN-2.3-ai.md D21, D22).
 *
 * ## The whole corpus, every time
 *
 * There is no retrieval step, and that is a measured decision rather than a
 * simplification. The corpus was 4,819 tokens when this was measured (2.3's
 * six pages; about 6,500 by this estimate once 2.5.1's rewrite made them
 * ten) — the haystack is smaller than the context window, so there is
 * nothing to retrieve *from*. BM25 over it was
 * built and scored during the spike and reached only 81% recall@3, which means
 * roughly one question in five would lose the section that answers it and be
 * answered confidently from a near miss instead.
 *
 * The decisive argument is the prompt cache. A fixed corpus is a stable
 * prefix, so the runtime reuses its KV cache and the second question of a
 * session costs 6.5s against the first's 94s. A retrieved selection is a
 * *different* prefix every time and pays its cost on every question. Retrieval
 * here would be slower in steady state and less accurate.
 *
 * **This is why the corpus goes first and the question last** in the prompt,
 * and why nothing user-specific may ever be interpolated ahead of it. Putting
 * the question first would move the varying text to the front of the prefix
 * and destroy the cache for every subsequent question — a change that looks
 * cosmetic and costs a 14× slowdown.
 *
 * ## `docs/` is not in here, deliberately
 *
 * D22. `docs/` is developer documentation: it describes unbuilt features in
 * the present tense and narrates the *legacy* application's security failures
 * — a committed `APP_KEY` amounting to RCE, unauthenticated read endpoints, an
 * admin account with a documented default password — in prose that carries
 * nothing to mark it historical once it is chunked into a prompt. A user
 * asking "is ArgueSecure secure?" would get a fluent, well-sourced,
 * catastrophically wrong answer.
 *
 * The corpus is `HelpLibrary`'s manifest and nothing else. Reusing that class
 * rather than scanning the directory is the same discipline it exists for:
 * dropping a file into `resources/markdown/help/` cannot silently change what
 * the assistant tells people.
 */
class Corpus
{
    /**
     * Every help page, in the manifest's order, each headed by its title.
     *
     * Not cached in Redis: reading six small files costs microseconds, and a
     * cached copy would be one more thing that can go stale against the
     * markdown a deployment actually ships. The expensive cache is the
     * runtime's, and it is keyed on the text itself.
     */
    public function text(): string
    {
        $sections = [];

        foreach (HelpLibrary::all() as $page) {
            $path = HelpLibrary::path($page['slug']);

            if (! File::exists($path)) {
                // The manifest promised a page that is not on disk. Silently
                // skipping it would let the assistant answer "the
                // documentation does not cover that" about a topic the
                // product documents — a wrong answer produced by a packaging
                // mistake, which is the hardest kind to notice.
                throw new RuntimeException(
                    "Help page [{$page['slug']}] is in the manifest but missing from disk."
                );
            }

            $sections[] = '## '.$page['title']."\n\n".trim(File::get($path));
        }

        return implode("\n\n", $sections);
    }

    /**
     * A rough token count, for the guard in {@see HelpAnswerer}.
     *
     * Four characters per token is the usual English approximation and is good
     * enough for deciding whether a prompt is close to overflowing a context
     * window; it is not good enough to bill anyone for, and nothing here does.
     */
    public function approximateTokens(): int
    {
        return (int) ceil(mb_strlen($this->text()) / 4);
    }
}
