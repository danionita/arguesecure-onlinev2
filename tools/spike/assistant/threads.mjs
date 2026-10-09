/**
 * The thread sweep: what the assistant costs on a host that is not allowed
 * every core.
 *
 * `bench.mjs` answered "which model" on a machine with eight cores to itself.
 * This answers a different question, and the one an operator actually has:
 * the deployment host runs MariaDB, Redis, Reverb, a queue worker and the app
 * on the same silicon, so the assistant gets a **budget** — four cores, six —
 * and llama.cpp will otherwise take every core it can see, including the ones
 * the database is using.
 *
 * Two knobs, and both are needed. `-t N` tells llama.cpp how many threads to
 * spawn; `--cpus=N` tells Docker how much CPU the container may actually have.
 * Setting only the first measures a runtime that is still free to burst; only
 * the second measures an over-subscribed runtime fighting the scheduler. The
 * production compose file sets `-t` via ASSISTANT_THREADS and leaves the quota
 * to the operator, which is why the sweep varies them together.
 *
 * It speaks llama.cpp's own `/completion` rather than Ollama's `/api/generate`,
 * unlike `bench.mjs`, because llama.cpp is what D17 actually ships. The
 * `timings` object it returns carries the same two rates.
 *
 *   node tools/spike/assistant/threads.mjs --host http://localhost:11436 --label t4
 *
 * Results land in `results/threads-<label>.json`. Run it once per thread
 * setting; `threads.sh` does the whole sweep including starting and stopping
 * the runtime, which matters because two inference servers competing for the
 * same cores produce two wrong numbers.
 */
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { jobA, jobB, jobC, jobCNodeCount } from './fixtures.mjs';

const here = dirname(fileURLToPath(import.meta.url));

const arg = (name, fallback) => {
    const i = process.argv.indexOf('--' + name);

    return i === -1 ? fallback : process.argv[i + 1];
};

const HOST = arg('host', 'http://localhost:8080');
const LABEL = arg('label', 'unlabelled');

/**
 * The per-job output budgets from `config/assistant.php`. Measuring anything
 * else measures a feature this repository does not ship: output tokens are
 * roughly ten times as expensive as prompt tokens on a CPU, so `n_predict` is
 * very nearly the whole latency figure.
 */
const PREDICT = { helpFirst: 320, helpRepeat: 320, suggest: 512, summary: 700 };

/**
 * Streamed, always, for the reason D20 exists: a non-streamed generation sends
 * no response headers until it has finished, and undici's `headersTimeout`
 * defaults to 300 seconds. Job C runs longer than that on a constrained host —
 * which is the case this script exists to measure — so a non-streamed run
 * would fail exactly where the answer matters most.
 */
async function completion(prompt, nPredict) {
    const started = Date.now();

    const response = await fetch(HOST + '/completion', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
            prompt,
            n_predict: nPredict,
            temperature: 0,
            seed: 42,
            // The whole point of D21's fixed-prefix design. Leaving this off
            // would measure a runtime the application does not run.
            cache_prompt: true,
            stream: true,
        }),
    });

    if (!response.ok) {
        throw new Error('/completion -> ' + response.status);
    }

    const decoder = new TextDecoder();
    let buffer = '';
    let text = '';
    let final = null;

    for await (const chunk of response.body) {
        buffer += decoder.decode(chunk, { stream: true });

        // SSE frames are separated by a blank line; a chunk boundary can fall
        // inside one, so only whole frames are consumed and the remainder is
        // carried forward.
        const frames = buffer.split('\n\n');
        buffer = frames.pop() ?? '';

        for (const frame of frames) {
            const line = frame.split('\n').find((l) => l.startsWith('data: '));
            if (!line) continue;

            const payload = JSON.parse(line.slice(6));
            text += payload.content ?? '';
            if (payload.stop) final = payload;
        }
    }

    if (!final) throw new Error('stream ended without a stop message');

    const timings = final.timings ?? {};

    return {
        wallSeconds: (Date.now() - started) / 1000,
        promptTokens: timings.prompt_n ?? 0,
        outputTokens: timings.predicted_n ?? 0,
        // `promptTokensPerSecond` counts cache hits as work, exactly as
        // bench.mjs warns. It is reported because its *movement* between
        // thread settings is informative; it is not a throughput figure and
        // must not be quoted as one.
        promptTokensPerSecond: timings.prompt_per_second ?? null,
        outputTokensPerSecond: timings.predicted_per_second ?? null,
        text,
    };
}

const round = (value) => (typeof value === 'number' ? Math.round(value * 100) / 100 : value);

const summarise = (name, result) => ({
    job: name,
    wallSeconds: round(result.wallSeconds),
    promptTokens: result.promptTokens,
    outputTokens: result.outputTokens,
    outputTokensPerSecond: round(result.outputTokensPerSecond),
    promptTokensPerSecond: round(result.promptTokensPerSecond),
});

const run = async () => {
    const results = [];

    /*
     * Job A twice, and the pair is the measurement rather than either number.
     * The corpus is a fixed prefix, so the first question pays for processing
     * all 4,819 tokens of it and the second pays for almost none — 94s against
     * 6.5s on eight cores. If a constrained host loses that ratio, D21's "send
     * the whole corpus" stops being free and job A needs rethinking, which is
     * the single most consequential thing this sweep can find.
     */
    console.log('[' + LABEL + '] job A, cold prefix...');
    results.push(summarise('help-first', await completion(jobA('What is a transfer?'), PREDICT.helpFirst)));

    console.log('[' + LABEL + '] job A, warm prefix...');
    results.push(
        summarise(
            'help-repeat',
            await completion(jobA('How does the target baseline differ from current?'), PREDICT.helpRepeat),
        ),
    );

    /*
     * Job B is the one with a hard threshold. It is a button with a spinner,
     * and 30 seconds is roughly where a person stops waiting and starts
     * clicking other things. Past that it becomes a queued job with a
     * notification, which is a different package.
     */
    console.log('[' + LABEL + '] job B...');
    results.push(summarise('suggest', await completion(jobB(), PREDICT.suggest)));

    /*
     * Job C is already a queued job, so it has no threshold to cross — but its
     * duration sets the lock TTL and the "in progress" window, and a figure
     * measured on the wrong machine makes both wrong.
     */
    console.log('[' + LABEL + '] job C (' + jobCNodeCount() + ' nodes)...');
    results.push(summarise('summary', await completion(jobC(), PREDICT.summary)));

    const out = join(here, 'results', 'threads-' + LABEL + '.json');

    writeFileSync(
        out,
        JSON.stringify(
            { label: LABEL, host: HOST, measuredAt: new Date().toISOString(), jobs: results },
            null,
            2,
        ) + '\n',
    );

    console.log('\n' + JSON.stringify(results, null, 2));
    console.log('\nwritten to ' + out);
};

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
