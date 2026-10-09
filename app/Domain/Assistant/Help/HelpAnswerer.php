<?php

namespace App\Domain\Assistant\Help;

use App\Domain\Assistant\Client;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Job A: answer a question about ArgueSecure from its own help pages
 * (PLAN-2.3-ai.md W51).
 *
 * ## The prompt is a file, and it is never built from user input
 *
 * `resources/prompts/assistant/help.md` is versioned and reviewed like code.
 * Exactly two substitutions happen: the corpus, and the question. Nothing
 * about the instructions varies per request, per user or per instance —
 * because a prompt assembled from anything a user controls is a prompt a user
 * can rewrite.
 *
 * ## Untrusted input, and what the delimiter is actually worth
 *
 * The question arrives inside a fenced section declared to be data. The spike
 * measured what that buys: across three model families, **every injection that
 * asked the model to abandon its task failed**, and injections that merely
 * asked it to decorate its output succeeded. So the fence holds the task
 * boundary and not the content boundary.
 *
 * For job A that residual risk is small and worth stating precisely: the
 * output is one person's answer to their own question, rendered as plain text,
 * reaching nobody else and writing nothing. A user who successfully instructs
 * the model to append their own string has achieved putting their own words on
 * their own screen. Job B and job C are where the same weakness matters,
 * because there the output reaches the graph and the report.
 */
class HelpAnswerer
{
    public function __construct(
        private readonly Client $client,
        private readonly Corpus $corpus,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onToken  W58's progress and
     *                                                  cancellation observer.
     *                                                  Job A's *first* answer
     *                                                  of a session measured
     *                                                  94 seconds — the whole
     *                                                  corpus has to be
     *                                                  processed before a
     *                                                  single token comes back
     *                                                  — so this is the job
     *                                                  where a person is most
     *                                                  likely to conclude
     *                                                  nothing is happening.
     *
     * @throws RuntimeException if the prompt would not fit, or the runtime fails.
     */
    public function answer(string $question, ?callable $onToken = null): string
    {
        $prompt = $this->buildPrompt($question);

        $this->assertFits($prompt);

        return trim($this->client->complete(
            $prompt,
            (int) config('assistant.max_tokens.help'),
            $onToken,
        ));
    }

    /**
     * Reads the help corpus into the runtime's cache without answering
     * anything (PLAN-2.6.md D57).
     *
     * Built by the same method as a real answer, so the cached prefix is
     * byte-for-byte the one the next real question will reuse — which is the
     * whole value of doing it. One token of output: the cost worth moving off
     * a person's first question is reading the corpus, not writing a reply.
     *
     * @throws RuntimeException if the prompt would not fit, or the runtime fails.
     */
    public function warm(): void
    {
        $prompt = $this->buildPrompt('What is ArgueSecure?');

        $this->assertFits($prompt);

        $this->client->complete($prompt, 1);
    }

    /**
     * Corpus first, question last — and that order is load-bearing.
     *
     * The runtime reuses its KV cache for a matching prompt *prefix*. With the
     * corpus in front, every question in a session shares 4,819 cached tokens
     * and costs 6.5s instead of 94s. Move the question above the corpus and
     * the prefix differs on every request, the cache never hits, and each
     * answer pays the full cost — a 14× regression from a change that looks
     * like formatting.
     */
    private function buildPrompt(string $question): string
    {
        $template = File::get(resource_path('prompts/assistant/help.md'));

        return str_replace(
            ['{{CORPUS}}', '{{QUESTION}}'],
            [$this->corpus->text(), $question],
            $template,
        );
    }

    /**
     * Refuses a prompt that the runtime would silently truncate.
     *
     * Truncation is the failure mode worth guarding: llama.cpp does not error
     * on an over-long prompt, it drops the front and answers from whatever
     * survived. The front is the corpus and the rules — so an over-long prompt
     * produces a confident answer from no documentation at all, and reads
     * exactly like a good one.
     *
     * The margin covers the reply the model still has to fit in the same
     * window, plus the approximation error in counting tokens by character
     * length.
     */
    private function assertFits(string $prompt): void
    {
        $promptTokens = (int) ceil(mb_strlen($prompt) / 4);
        $budget = (int) config('assistant.context_tokens')
            - (int) config('assistant.max_tokens.help');

        if ($promptTokens > $budget * 0.9) {
            throw new RuntimeException(
                'The help documentation no longer fits the assistant\'s context window. '.
                'See PLAN-2.3-ai.md D21: past roughly 8,000 tokens the no-retrieval '.
                'decision needs revisiting.'
            );
        }
    }
}
