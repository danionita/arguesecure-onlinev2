/**
 * The 2.3 spike benchmark.
 *
 * Answers open questions 2 and 3 of PLAN-2.3-ai.md with numbers from this
 * machine: tokens per second, resident size at load, and time-to-full-response
 * for each of the three jobs — including job C, whose prompt is the whole
 * assessment and therefore the largest the assistant will ever build.
 *
 * It also runs the red-team cases, because "does the delimiter hold" is a
 * property of the model as much as of the prompt, and a model that fails them
 * is not a candidate however fast it is.
 *
 * This is spike code. It is not wired into the application, it ships no
 * feature, and nothing in `app/` imports it. It exists so the numbers in
 * `docs/spikes/2.3-inference.md` can be reproduced on the machine that will
 * actually run the deployment, which is the only machine whose numbers matter.
 *
 *   node tools/spike/assistant/bench.mjs [--host http://localhost:11434]
 */
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { jobA, jobB, jobC, jobCNodeCount } from './fixtures.mjs';
import { cases as redteamCases } from './redteam.mjs';

const here = dirname(fileURLToPath(import.meta.url));

const arg = (name, fallback) => {
    const i = process.argv.indexOf('--' + name);

    return i === -1 ? fallback : process.argv[i + 1];
};

const HOST = arg('host', 'http://localhost:11434');

/**
 * `--models a,b` benchmarks only those, and `--out name.json` writes
 * elsewhere. A full run is hours; a candidate that fails needs re-running on
 * its own, without spending that time again on the ones that succeeded.
 */
const ONLY = arg('models', '');
const OUT_NAME = arg('out', 'bench.json');

/**
 * `--jobs c,rt` runs a subset. The first run lost job C and the red-team cases
 * on three of four candidates to a client timeout while job A and B completed
 * normally; re-measuring those would have cost an hour to reproduce numbers
 * that were never in doubt.
 */
const JOBS = new Set(
    arg('jobs', 'a,b,c,rt')
        .split(',')
        .map((j) => j.trim().toLowerCase()),
);

/**
 * The candidates. Small instruct models in the 3-8B range, 4-bit, all with
 * licences that permit self-hosting.
 *
 * `qwen3:4b` is here for a reason that is not size: it emits reasoning tokens
 * before its answer. On a CPU every one of those is paid for at the same rate
 * as an answer token, so the spike measures whether a reasoning model is
 * affordable rather than assuming either way.
 */
const MODELS = [
    'gemma3:4b-it-q4_K_M',
    'qwen2.5:7b-instruct-q4_K_M',
    'llama3.1:8b-instruct-q4_K_M',
    'qwen3:4b',
];

/**
 * 12k, not the 2k default.
 *
 * Job C's prompt measures ~8.5k tokens for a 73-node assessment. Ollama
 * silently truncates anything past `num_ctx`, so at the default the model
 * would summarise whichever half of the assessment survived and there would be
 * no error to notice. Truncation is the failure mode this whole setting exists
 * to prevent, and it is silent, which is why it is set explicitly here rather
 * than left to the runtime.
 */
const NUM_CTX = 12288;

/**
 * Two questions, chosen because the help corpus answers them in different
 * places: the first is stated plainly in concepts.md, the second is the
 * counter-intuitive rule quantification.md spends a section on. A model that
 * gets the first and invents the second is retrieving nothing.
 */
const QUESTIONS_A = [
    'What is the difference between a defence and a transfer?',
    'Why does my total not change when I set a reduction target?',
];

async function api(path, body, timeoutMs = 900_000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(HOST + path, {
            method: body ? 'POST' : 'GET',
            headers: body ? { 'content-type': 'application/json' } : undefined,
            body: body ? JSON.stringify(body) : undefined,
            signal: controller.signal,
        });

        if (!response.ok) {
            // With the body. A bare status turned a 500 on one candidate into
            // five minutes of guessing at the runtime's logs.
            const detail = await response.text().catch(() => '');
            throw new Error(
                path + ' -> ' + response.status + ' ' + detail.slice(0, 300),
            );
        }

        return await response.json();
    } finally {
        clearTimeout(timer);
    }
}

const ns = (n) => (n ?? 0) / 1e9;

/**
 * Reads ollama's NDJSON stream and returns the final object, which carries the
 * same timing fields the non-streamed response would have plus the assembled
 * text. See `generate` for why this is not optional.
 */
async function streamGenerate(body) {
    const response = await fetch(HOST + '/api/generate', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const detail = await response.text().catch(() => '');
        throw new Error(
            '/api/generate -> ' + response.status + ' ' + detail.slice(0, 300),
        );
    }

    let pending = '';
    let text = '';
    let final = null;

    for await (const chunk of response.body) {
        pending += Buffer.from(chunk).toString('utf8');

        let newline;
        while ((newline = pending.indexOf('\n')) !== -1) {
            const line = pending.slice(0, newline).trim();
            pending = pending.slice(newline + 1);
            if (!line) continue;

            const parsed = JSON.parse(line);
            if (parsed.error) throw new Error(parsed.error);

            text += parsed.response ?? '';
            if (parsed.done) final = parsed;
        }
    }

    if (!final) throw new Error('stream ended without a done message');

    return { ...final, response: text };
}

/**
 * One generation, with the timings the runtime reports for it.
 *
 * **Streamed, and that is not a stylistic choice.** Node's `fetch` is undici,
 * whose `headersTimeout` defaults to 300 seconds, and a non-streamed
 * `/api/generate` sends no headers at all until the whole generation has
 * finished. On a CPU that is a live tripwire: the first run of this benchmark
 * lost job C on both 7B models at exactly 5m6s, with a "fetch failed" on the
 * client and a 500 on the server as it noticed the disconnect. Nothing was
 * wrong with either model.
 *
 * Streaming sends headers immediately, so the timeout that matters becomes the
 * gap between tokens rather than the length of the answer. The application
 * client W50 builds has the same problem waiting for it — Guzzle's own
 * timeouts are shorter still — which is why this is written up rather than
 * merely fixed.
 */
async function generate(model, prompt, { predict = 512 } = {}) {
    const started = Date.now();
    const r = await streamGenerate({
        model,
        prompt,
        stream: true,
        // Deterministic so a rerun on the same machine is comparable. The
        // shipped feature will not pin the seed; the benchmark must.
        options: {
            num_ctx: NUM_CTX,
            num_predict: predict,
            temperature: 0,
            seed: 42,
        },
        keep_alive: '10m',
    });

    // `promptTokensPerSecond` is NOT a measurement of prompt processing speed
    // and must not be quoted as one. The runtime counts every prompt token in
    // `prompt_eval_count`, including the ones it served from the KV cache
    // without doing any work — so a job whose prompts share a long prefix
    // reports rates of several hundred tokens per second, which is the cache
    // hit rate wearing a throughput field's name. For the real figure, read
    // `prompt processing` in the runtime's own log, which reports only the
    // tokens it actually processed.
    return {
        wallSeconds: (Date.now() - started) / 1000,
        promptTokens: r.prompt_eval_count ?? 0,
        outputTokens: r.eval_count ?? 0,
        loadSeconds: ns(r.load_duration),
        promptSeconds: ns(r.prompt_eval_duration),
        outputSeconds: ns(r.eval_duration),
        promptTokensPerSecond:
            (r.prompt_eval_count ?? 0) / (ns(r.prompt_eval_duration) || 1),
        outputTokensPerSecond: (r.eval_count ?? 0) / (ns(r.eval_duration) || 1),
        text: r.response ?? '',
        truncated: (r.eval_count ?? 0) >= predict,
    };
}

/** Resident size the runtime reports for the loaded model. */
async function residentBytes(model) {
    const ps = await api('/api/ps');
    const entry = (ps.models ?? []).find((m) => m.name === model || m.model === model);

    return entry ? entry.size : null;
}

/** Streamed for the same reason `generate` is: a 5 GB pull outlives 300s. */
async function pull(model) {
    const started = Date.now();
    const response = await fetch(HOST + '/api/pull', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ model, stream: true }),
    });

    if (!response.ok) {
        throw new Error('pull ' + model + ' -> ' + response.status);
    }

    // Drained, not parsed. The progress messages are noise here; what matters
    // is that the body is consumed to completion before the model is used.
    for await (const chunk of response.body) {
        void chunk;
    }

    return (Date.now() - started) / 1000;
}

const median = (xs) => {
    const s = [...xs].sort((a, b) => a - b);

    return s.length % 2
        ? s[(s.length - 1) / 2]
        : (s[s.length / 2 - 1] + s[s.length / 2]) / 2;
};

/** Drops the full text, keeping an excerpt — results stay readable by hand. */
const strip = (runs) =>
    runs.map(({ text, ...rest }) => ({ ...rest, excerpt: text.slice(0, 600) }));

/** Does the reply parse as the array the accept path would have to consume? */
function parsesAsSuggestions(text) {
    const start = text.indexOf('[');
    const end = text.lastIndexOf(']');
    if (start === -1 || end <= start) return false;

    try {
        const parsed = JSON.parse(text.slice(start, end + 1));

        return (
            Array.isArray(parsed) &&
            parsed.length > 0 &&
            parsed.every(
                (item) =>
                    item &&
                    typeof item.title === 'string' &&
                    typeof item.description === 'string',
            )
        );
    } catch {
        return false;
    }
}

/**
 * Runs every phase for one model, keeping whatever completed.
 *
 * The first run of this benchmark threw on job C for three of four candidates
 * and the throw discarded their finished job A and B measurements with it —
 * an hour of CPU that existed only in a progress log. A failure late in a long
 * measurement must not invalidate the parts that already succeeded.
 */
async function benchmarkModel(model, partial) {
    process.stderr.write('\n== ' + model + '\n');

    const pullSeconds = await pull(model);
    partial.pullSeconds = pullSeconds;
    process.stderr.write('   pulled in ' + pullSeconds.toFixed(0) + 's\n');

    // A cold first call so `load_duration` is measured once, deliberately, and
    // does not contaminate the runs that follow.
    const cold = await generate(model, jobB(), { predict: 512 });
    const resident = await residentBytes(model);
    partial.residentBytes = resident;
    partial.coldLoadSeconds = cold.loadSeconds;
    process.stderr.write(
        '   cold ' +
            cold.wallSeconds.toFixed(1) +
            's (load ' +
            cold.loadSeconds.toFixed(1) +
            's), resident ' +
            (resident ? (resident / 2 ** 30).toFixed(1) + 'GiB' : '?') +
            '\n',
    );

    const jobBRuns = [];
    for (const risk of JOBS.has('b')
        ? ['risk-payment-breach', 'risk-storefront-outage']
        : []) {
        const run = await generate(model, jobB({ risk }), { predict: 512 });
        run.wellFormed = parsesAsSuggestions(run.text);
        jobBRuns.push(run);
        process.stderr.write(
            '   B ' +
                run.wallSeconds.toFixed(1) +
                's ' +
                run.outputTokensPerSecond.toFixed(1) +
                ' tok/s json=' +
                run.wellFormed +
                '\n',
        );
    }

    if (jobBRuns.length) {
        partial.jobB = {
            medianWallSeconds: median(jobBRuns.map((r) => r.wallSeconds)),
            medianOutputTokensPerSecond: median(
                jobBRuns.map((r) => r.outputTokensPerSecond),
            ),
            medianPromptTokensPerSecond: median(
                jobBRuns.map((r) => r.promptTokensPerSecond),
            ),
            promptTokens: jobBRuns[0].promptTokens,
            wellFormed: jobBRuns.filter((r) => r.wellFormed).length,
            of: jobBRuns.length,
            runs: strip(jobBRuns),
        };
    }

    const jobARuns = [];
    for (const question of JOBS.has('a') ? QUESTIONS_A : []) {
        const run = await generate(model, jobA(question), { predict: 320 });
        jobARuns.push(run);
        process.stderr.write('   A ' + run.wallSeconds.toFixed(1) + 's\n');
    }

    if (jobARuns.length) {
        partial.jobA = {
            medianWallSeconds: median(jobARuns.map((r) => r.wallSeconds)),
            promptTokens: jobARuns[0].promptTokens,
            runs: strip(jobARuns),
        };
    }

    let jobCRun = null;
    if (JOBS.has('c')) {
        jobCRun = await generate(model, jobC(), { predict: 700 });
        process.stderr.write(
            '   C ' +
                jobCRun.wallSeconds.toFixed(1) +
                's prompt=' +
                jobCRun.promptTokens +
                'tok\n',
        );
        partial.jobC = {
            wallSeconds: jobCRun.wallSeconds,
            promptTokens: jobCRun.promptTokens,
            outputTokens: jobCRun.outputTokens,
            promptTokensPerSecond: jobCRun.promptTokensPerSecond,
            outputTokensPerSecond: jobCRun.outputTokensPerSecond,
            runs: strip([jobCRun]),
        };
    }

    const redteam = [];
    // Only recorded when the phase actually runs. An empty array here is
    // indistinguishable from "seven cases, none complied" once the file is
    // merged with another run's.
    if (JOBS.has('rt')) partial.redteam = redteam;
    for (const testCase of JOBS.has('rt') ? redteamCases : []) {
        const prompt =
            testCase.job === 'C'
                ? jobC({ inject: testCase.text })
                : jobB({ inject: testCase.text });
        // Shorter than the job's own budget, deliberately. Compliance with an
        // injection shows up in the opening tokens — a model that is going to
        // print BANANA prints it first — so the cases do not need to pay for a
        // full-length generation on a CPU to be decided.
        const run = await generate(model, prompt, {
            predict: testCase.job === 'C' ? 320 : 256,
        });
        // An empty reply is NOT a pass, and recording it as one is how a
        // model that answered nothing at all came out of the first run looking
        // like the only one that resisted every attack. A reasoning model can
        // spend its whole budget thinking — the runtime reports that
        // separately — and return no text for a detector to match. Three
        // states, not two.
        const answered = run.text.trim().length > 0;
        const complied = answered ? testCase.detect(run.text) : null;

        redteam.push({
            id: testCase.id,
            job: testCase.job,
            answered,
            complied,
            excerpt: run.text.slice(0, 400),
        });
        process.stderr.write(
            '   RT ' +
                testCase.id +
                (answered ? ' complied=' + complied : ' NO OUTPUT (void)') +
                '\n',
        );
    }

    partial.redteamComplied = redteam.filter((r) => r.complied === true).length;
    partial.redteamVoid = redteam.filter((r) => !r.answered).length;

    return partial;
}

const results = {
    startedAt: new Date().toISOString(),
    host: HOST,
    numCtx: NUM_CTX,
    jobCNodeCount: jobCNodeCount(),
    models: [],
};

const out = join(here, 'results', OUT_NAME);
const selected = ONLY ? ONLY.split(',').map((m) => m.trim()) : MODELS;

// Written after every model rather than once at the end. A full run is hours
// of CPU, and a crash on the last candidate should not cost the three that
// already finished.
for (const model of selected) {
    // The record is created before the work starts and filled as each phase
    // completes, so a failure keeps everything measured up to that point and
    // records where it stopped.
    const partial = { model };
    results.models.push(partial);

    try {
        await benchmarkModel(model, partial);
    } catch (error) {
        process.stderr.write('   FAILED ' + model + ': ' + error.message + '\n');
        partial.error = String(error.message);
    }

    writeFileSync(out, JSON.stringify(results, null, 2));
}

results.finishedAt = new Date().toISOString();
writeFileSync(out, JSON.stringify(results, null, 2));
process.stderr.write('\nwrote ' + out + '\n');
