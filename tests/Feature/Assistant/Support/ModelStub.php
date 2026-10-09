<?php

namespace Tests\Feature\Assistant\Support;

use Illuminate\Support\Facades\Http;

/**
 * The inference runtime, replaced by something deterministic (W53).
 *
 * ## Why a stub and not a model
 *
 * A real model would make this suite slow, non-deterministic, and dependent on
 * an 8 GB file and a second container that CI does not have. It would also
 * test the wrong thing. **The model's behaviour was measured once**, in the 2.3
 * spike, and that measurement is recorded in
 * `tests/fixtures/assistant/injections.json`. What has to keep working is
 * *this application's* half: what it sends, what it refuses to read back, and
 * what it shows a person before anything lands.
 *
 * So the honest description of every guardrail test here is: **given a reply
 * of the shape the spike actually saw, what does ArgueSecure do with it?**
 * These tests cannot demonstrate that a model resists an injection — nothing
 * short of running the model can, which is what `tools/spike/assistant/` is
 * for and why it is committed rather than thrown away.
 *
 * ## It streams in pieces, deliberately
 *
 * llama.cpp emits one server-sent event per token, and an 8 KB socket read
 * lands mid-JSON routinely. A stub that returned the whole reply as one line
 * would leave `Client::takeCompleteLines` untested against the case it exists
 * for, and that failure would appear first on a long generation in production.
 */
final class ModelStub
{
    /** @var list<string> Prompts this stub has been sent, in order. */
    private static array $prompts = [];

    /**
     * Fakes the runtime, replying with a fixed body or with whatever the given
     * callback makes of the prompt.
     *
     * @param  string|(callable(string): string)  $reply
     */
    public static function replying(string|callable $reply): void
    {
        self::$prompts = [];

        // Anything this suite does not fake is a bug in the test, not a call
        // to make: a guardrail suite that quietly reached a real host would be
        // worse than no guardrail suite.
        Http::preventStrayRequests();

        Http::fake([
            '*/health' => Http::response('', 200),
            '*/completions' => function ($request) use ($reply) {
                // The prompt is the single user message since W59, where the
                // client moved to the chat endpoint so the model's own chat
                // template gets applied. Read here rather than in each test so
                // every existing assertion about "what was sent" keeps working.
                $prompt = is_string($request['messages'][0]['content'] ?? null)
                    ? $request['messages'][0]['content']
                    : '';
                self::$prompts[] = $prompt;

                return Http::response(self::stream(
                    is_callable($reply) ? $reply($prompt) : $reply
                ));
            },
        ]);
    }

    /** The reply the spike measured for a case whose injection succeeded. */
    public static function replyingAsMeasuredFor(string $caseId): void
    {
        self::replying(Injections::case($caseId)['complied_reply']);
    }

    /** @return list<string> */
    public static function prompts(): array
    {
        return self::$prompts;
    }

    public static function lastPrompt(): string
    {
        return self::$prompts === [] ? '' : self::$prompts[count(self::$prompts) - 1];
    }

    /**
     * Formats text as the runtime's SSE stream, split across several events.
     *
     * The OpenAI-compatible shape, because that is what the client reads since
     * W59 — `{"choices":[{"delta":{"content":"…"}}]}`, terminated by the
     * literal `data: [DONE]`. A stub that kept emitting the old flat shape
     * would have gone on passing while the application talked to an endpoint
     * nobody had ever exercised, which is the exact failure W59 exists to fix.
     */
    private static function stream(string $text): string
    {
        $out = '';

        foreach (mb_str_split($text, 24) ?: [''] as $piece) {
            $out .= 'data: '.json_encode([
                'choices' => [['delta' => ['content' => $piece]]],
            ])."\n";
        }

        return $out."data: [DONE]\n";
    }
}
