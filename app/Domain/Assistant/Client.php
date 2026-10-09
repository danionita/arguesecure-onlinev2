<?php

namespace App\Domain\Assistant;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * The only thing in this application that talks to the inference runtime.
 *
 * ## It streams, and that is not a preference (PLAN-2.3-ai.md D20)
 *
 * A non-streamed generation sends **no response headers at all** until it has
 * finished. CPU generations run for minutes — the spike measured 249s for a
 * job C draft — so with a conventional client the request dies on a header
 * timeout while the server is still working, and the failure looks like a
 * server fault. It is not. This destroyed half of the spike's first benchmark
 * at exactly 300 seconds, twice, before it was recognised
 * (docs/spikes/2.3-inference.md).
 *
 * Streaming sends headers immediately, which converts the meaningful timeout
 * from "how long may the answer be" into "how long may a gap between tokens
 * be". That second question has a sane answer; the first does not. It is also
 * the only way to offer a cancel, or to show progress on a job that runs for
 * minutes.
 *
 * ## Every timeout is set explicitly
 *
 * Guzzle's defaults are measured in seconds and are wrong here by one to two
 * orders of magnitude. Nothing below relies on a default.
 *
 * ## It talks to the chat endpoint, and that is a correctness fix (W59)
 *
 * `/v1/chat/completions`, not llama.cpp's own `/completion`. The difference is
 * the model's **chat template**: an instruction-tuned model expects its turn
 * markers, the chat endpoint applies the template stored in the GGUF's own
 * metadata, and the raw endpoint applies nothing.
 *
 * Sent raw, `gemma-3-4b-it` saw a finished document — one ending "Reply with
 * the answer only." — and did the correct thing with a finished document,
 * which is to stop. **Job A and job C returned an empty string on every run**;
 * job B survived only because its prompt ends "Reply with the JSON array
 * only", and a JSON array is a plausible continuation. Measured both ways in
 * docs/spikes/2.3-threads.md: 1 token against 88 and 401.
 *
 * Prompt-prefix caching, which D21's whole no-retrieval design rests on,
 * survives the move — the runtime caches on the token prefix regardless of
 * which endpoint produced it.
 *
 * ## What this class does not do
 *
 * It does not build prompts, and it does not interpret replies. It takes a
 * prompt string and returns text. Prompt assembly lives with each job (W51,
 * W52, W54) because each has its own versioned prompt file and its own
 * untrusted-content handling, and parsing lives with the job because only the
 * job knows what shape it asked for.
 */
class Client
{
    /**
     * Generates a completion, returning the assembled text.
     *
     * @param  int  $maxTokens  The generation budget. This is the latency dial
     *                          — see config/assistant.php.
     * @param  (callable(string): void)|null  $onToken  Called with each chunk as
     *                                                  it arrives, for a caller
     *                                                  that wants to stream
     *                                                  onward rather than wait.
     *
     * @throws RuntimeException when the runtime refuses, is unreachable, or
     *                          stalls for longer than the idle timeout.
     */
    public function complete(string $prompt, int $maxTokens, ?callable $onToken = null): string
    {
        $response = Http::connectTimeout((int) config('assistant.timeouts.connect'))
            ->timeout((int) config('assistant.timeouts.total'))
            ->withOptions(['stream' => true])
            ->post($this->url('/v1/chat/completions'), [
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => $maxTokens,
                'stream' => true,
                // Deterministic-ish. Not seeded: a user who asks twice and
                // gets the same five suggestions has been given no reason to
                // ask twice, and the temperature is what makes a second
                // attempt worth making.
                'temperature' => 0.4,
                'cache_prompt' => true,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'The assistant runtime refused the request (HTTP '.$response->status().').'
            );
        }

        return $this->readStream($response->toPsrResponse()->getBody(), $onToken);
    }

    /**
     * Reads llama.cpp's server-sent event stream to its end.
     *
     * The idle timeout is enforced here rather than by the HTTP client,
     * because this is the only place that knows when the last token arrived.
     * A wedged runtime holds the connection open indefinitely without sending
     * anything, and that is precisely the case an overall timeout handles
     * badly: it either fires on a long-but-healthy generation or waits fifteen
     * minutes to notice a dead one.
     *
     * @param  (callable(string): void)|null  $onToken
     */
    private function readStream(StreamInterface $body, ?callable $onToken): string
    {
        $idleLimit = (int) config('assistant.timeouts.stream_idle');
        $lastToken = time();
        $buffer = '';
        $text = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                if (time() - $lastToken > $idleLimit) {
                    throw new RuntimeException(
                        'The assistant runtime stopped responding mid-answer.'
                    );
                }

                // Nothing to read yet but the stream is open. Yield rather
                // than spin: this loop would otherwise burn a CPU core that
                // the inference process needs.
                usleep(50_000);

                continue;
            }

            $lastToken = time();
            $buffer .= $chunk;

            foreach ($this->takeCompleteLines($buffer) as $line) {
                $text .= $this->textFrom($line, $onToken);
            }
        }

        return $text;
    }

    /**
     * Splits off whole lines, leaving any partial trailing line in $buffer.
     *
     * An 8 KB read lands wherever it lands, which is routinely mid-JSON. A
     * parser that assumed each read was a whole message would work in
     * development against short replies and fail on the long ones.
     *
     * @return list<string>
     */
    private function takeCompleteLines(string &$buffer): array
    {
        $lines = [];

        while (($newline = strpos($buffer, "\n")) !== false) {
            $lines[] = substr($buffer, 0, $newline);
            $buffer = substr($buffer, $newline + 1);
        }

        return $lines;
    }

    /**
     * One SSE line, in whichever shape the runtime sent it.
     *
     * The chat endpoint frames a token as
     * `{"choices":[{"delta":{"content":"…"}}]}` and ends the stream with the
     * literal `data: [DONE]`. The flat `{"content":"…"}` shape is llama.cpp's
     * own `/completion` format, which this client no longer calls but still
     * reads: it costs one branch, and it keeps the parser working against a
     * runtime someone points at this instance that speaks only the older
     * shape.
     *
     * @param  (callable(string): void)|null  $onToken
     */
    private function textFrom(string $line, ?callable $onToken): string
    {
        $line = trim($line);

        if ($line === '' || ! str_starts_with($line, 'data:')) {
            return '';
        }

        $payload = trim(substr($line, 5));

        if ($payload === '[DONE]') {
            return '';
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return '';
        }

        $content = $decoded['choices'][0]['delta']['content']
            ?? $decoded['content']
            ?? null;

        if (! is_string($content) || $content === '') {
            return '';
        }

        if ($onToken !== null) {
            $onToken($content);
        }

        return $content;
    }

    /**
     * A quick GET to one of the runtime's status endpoints — `/health`,
     * `/props`, `/slots` — or null when it could not be reached at all.
     *
     * The one place a status question is asked (until 2.6, Health,
     * RuntimeModel and CorpusWarmer each carried a copy of this). Not the
     * generous generation timeout: a runtime that is up answers a status
     * request immediately — `/health` returns 503 while it is still loading —
     * so a slow answer means absent, and absent must degrade fast (D25).
     *
     * Any transport failure is null rather than an exception. A status probe
     * is not a request whose error anyone can act on, and letting a DNS
     * failure become a 500 on a page that merely wanted to know whether to
     * show a button would defeat the whole degradation path.
     */
    public function probe(string $path): ?Response
    {
        try {
            return Http::connectTimeout((int) config('assistant.timeouts.connect'))
                ->timeout((int) config('assistant.timeouts.connect') + 2)
                ->get($this->url($path));
        } catch (Throwable) {
            return null;
        }
    }

    private function url(string $path): string
    {
        return rtrim((string) config('assistant.base_url'), '/').$path;
    }
}
