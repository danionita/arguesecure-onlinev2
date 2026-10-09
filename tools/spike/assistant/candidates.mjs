/**
 * The 2.6 candidate benchmark: other models, and other ways of running the
 * current one, measured the way the application actually calls the runtime.
 *
 * That last clause is the difference from `threads.mjs`. It posts to
 * `/v1/chat/completions`, streaming, with the app's sampling (temperature 0.4,
 * `cache_prompt`) — the same request `App\Domain\Assistant\Client` sends — and
 * not to `/completion`, which applies no chat template and returned an empty
 * string for jobs A and C on the shipped model (W59, 2.3-threads.md). A seed
 * is added, which the app deliberately does not send, so that two runs of the
 * harness are comparable.
 *
 * It is driven one phase at a time by `candidates.sh`, because the middle of
 * the measurement is a container restart that this process cannot perform:
 *
 *   node candidates.mjs --host http://arsec-candidate:8080 --host-label unraid \
 *       --config gemma3-qat --steps props,a-cold,a-warm,save
 *   (restart the container)
 *   node candidates.mjs ... --steps restore,a-restored,b-outage,b-payment,c
 *
 * Every step merges into `results/candidates-<host>-<config>.json` as it
 * finishes,
 * so an interrupted run keeps what it measured — and, as report.mjs learned
 * the hard way, a step that did not run never overwrites one that did.
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { jobA, jobB, jobC, jobCNodeCount } from './fixtures.mjs';

const here = dirname(fileURLToPath(import.meta.url));

const arg = (name, fallback) => {
    const i = process.argv.indexOf('--' + name);

    return i === -1 ? fallback : process.argv[i + 1];
};

const HOST = arg('host', 'http://localhost:8080');
const CONFIG = arg('config', 'unlabelled');
const STEPS = arg('steps', '').split(',').filter(Boolean);
// Which machine. Results from two hosts must never overwrite each other, and a
// figure without its host is the anecdote 2.3-inference.md warns about.
const HOST_LABEL = arg('host-label', 'local');
const OUT = join(here, 'results', 'candidates-' + HOST_LABEL + '-' + CONFIG + '.json');

/** `config/assistant.php` `max_tokens`. Output is the latency dial. */
const BUDGET = { help: 320, suggestions: 512, summary: 700 };

/**
 * Three questions, one per job A measurement. The first two are the 2.3
 * spike's, so a reply can be read against that run; the third is one BM25
 * missed there, which makes it a fair test of whether the model reads the
 * corpus rather than matching words.
 */
const QUESTIONS = {
    'a-cold': 'What is the difference between a defence and a transfer?',
    'a-warm': 'Why does my total not change when I set a reduction target?',
    'a-restored': 'What happens if I leave a likelihood blank?',
};

/** The slot file the help prefix is saved under, per configuration. */
const SLOT_FILE = CONFIG + '-help.bin';

export async function http(path, body) {
    const response = await fetch(HOST + path, {
        method: body ? 'POST' : 'GET',
        headers: body ? { 'content-type': 'application/json' } : undefined,
        body: body ? JSON.stringify(body) : undefined,
    });
    const text = await response.text();
    let json = null;
    try {
        json = JSON.parse(text);
    } catch {
        // Left as text below: an error page is evidence too.
    }

    return { status: response.status, ok: response.ok, json, text: json ? null : text.slice(0, 500) };
}

/**
 * One streamed chat completion, as the application sends it.
 *
 * `timings` arrives on the final chunk. llama-server counts `prompt_n` as the
 * tokens it actually processed and `cache_n` as those it served from the KV
 * cache, so `prompt_per_second` is a real rate here — unlike Ollama's
 * `prompt_eval_count`, which folded cache hits in and produced the "649 tok/s"
 * trap 2.3-inference.md describes.
 */
export async function chat(prompt, maxTokens, seed = 42) {
    const started = performance.now();
    const response = await fetch(HOST + '/v1/chat/completions', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
            messages: [{ role: 'user', content: prompt }],
            max_tokens: maxTokens,
            stream: true,
            stream_options: { include_usage: true },
            temperature: 0.4,
            seed,
            cache_prompt: true,
        }),
    });

    if (!response.ok) {
        throw new Error('/v1/chat/completions -> ' + response.status + ' ' + (await response.text()).slice(0, 300));
    }

    const decoder = new TextDecoder();
    let buffer = '';
    let text = '';
    let reasoning = '';
    let firstTokenMs = null;
    let timings = null;
    let usage = null;
    let finishReason = null;

    for await (const chunk of response.body) {
        buffer += decoder.decode(chunk, { stream: true });

        // Whole lines only; a network read lands wherever it lands.
        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';

        for (const raw of lines) {
            const line = raw.trim();
            if (!line.startsWith('data:')) continue;
            const payload = line.slice(5).trim();
            if (payload === '[DONE]') continue;

            const event = JSON.parse(payload);
            const choice = event.choices?.[0];
            const content = choice?.delta?.content ?? '';
            const thought = choice?.delta?.reasoning_content ?? '';

            if ((content || thought) && firstTokenMs === null) {
                firstTokenMs = performance.now() - started;
            }
            text += content;
            reasoning += thought;
            if (choice?.finish_reason) finishReason = choice.finish_reason;
            if (event.timings) timings = event.timings;
            if (event.usage) usage = event.usage;
        }
    }

    return {
        wallSeconds: (performance.now() - started) / 1000,
        firstTokenSeconds: firstTokenMs === null ? null : firstTokenMs / 1000,
        finishReason,
        timings,
        usage,
        text,
        reasoning,
    };
}

const round = (value, places = 2) =>
    typeof value === 'number' ? Math.round(value * 10 ** places) / 10 ** places : value;

/**
 * Whether the model reasoned despite being told not to.
 *
 * Three places it can surface: `reasoning_content`, when the runtime parsed
 * the thinking out; raw think tags in the reply, when it did not; and plain
 * prose ("Thinking Process:"), which some Qwen builds emit when the template
 * switch is honoured but the model deliberates anyway. qwen3:4b spent its
 * entire budget this way in 2.3 and produced nothing, so any of the three
 * disqualifies.
 */
const reasoningCheck = (result) => {
    const markers = [/<\/?think>/i, /thinking process/i, /<\|?channel\|?>\s*thought/i, /<\|think\|>/i];

    return {
        reasoningChars: result.reasoning.length,
        markersInContent: markers.filter((m) => m.test(result.text)).map(String),
        reasoned: result.reasoning.length > 0 || markers.some((m) => m.test(result.text)),
    };
};

export const summarise = (result) => {
    const t = result.timings ?? {};

    return {
        wallSeconds: round(result.wallSeconds),
        firstTokenSeconds: round(result.firstTokenSeconds),
        finishReason: result.finishReason,
        // Processed vs served from cache: the pair D21's design rests on.
        promptProcessed: t.prompt_n ?? null,
        promptFromCache: t.cache_n ?? null,
        promptTokensPerSecond: round(t.prompt_per_second),
        outputTokens: t.predicted_n ?? null,
        outputTokensPerSecond: round(t.predicted_per_second),
        // Present only when a draft model is loaded.
        draftTokens: t.draft_n ?? null,
        draftAccepted: t.draft_n_accepted ?? null,
        draftAcceptance: t.draft_n ? round(t.draft_n_accepted / t.draft_n, 3) : null,
        ...reasoningCheck(result),
        timings: result.timings,
        usage: result.usage,
        text: result.text,
        reasoning: result.reasoning || undefined,
    };
};

/**
 * Job B's contract, checked the way the accept path reads it (D23): find the
 * array wherever it sits in the reply — gemma3 fences it, qwen2.5 did not —
 * and count only elements with a non-empty string `title` and `description`.
 * Extra keys are recorded rather than counted against the item, because the
 * parser discards them; they are still worth seeing.
 */
export function parseSuggestions(text) {
    const fenced = text.match(/```(?:json)?\s*([\s\S]*?)```/);
    const body = fenced ? fenced[1] : text;
    const start = body.indexOf('[');
    const end = body.lastIndexOf(']');

    if (start === -1 || end <= start) {
        return { parsed: false, items: 0, wellFormed: 0, fenced: Boolean(fenced) };
    }

    let array;
    try {
        array = JSON.parse(body.slice(start, end + 1));
    } catch (error) {
        return { parsed: false, items: 0, wellFormed: 0, fenced: Boolean(fenced), error: String(error.message) };
    }

    const items = Array.isArray(array) ? array : [];
    const good = items.filter(
        (i) => i && typeof i.title === 'string' && i.title.trim() && typeof i.description === 'string' && i.description.trim(),
    );

    return {
        parsed: true,
        fenced: Boolean(fenced),
        items: items.length,
        wellFormed: good.length,
        extraKeys: [...new Set(items.flatMap((i) => Object.keys(i ?? {}).filter((k) => k !== 'title' && k !== 'description')))],
        titlesOver60: good.filter((i) => i.title.length >= 60).length,
        titles: good.map((i) => i.title),
    };
}

const steps = {
    async props() {
        const props = await http('/props');
        const slots = await http('/slots');
        const p = props.json ?? {};
        const template = p.chat_template ?? '';

        return {
            buildInfo: p.build_info ?? null,
            modelPath: p.model_path ?? null,
            totalSlots: p.total_slots ?? null,
            // n_ctx per slot, which is what a prompt must fit into — not the
            // `-c` the server was started with, if the context is split.
            slotContext: p.default_generation_settings?.n_ctx ?? null,
            templateLength: template.length,
            templateMentionsThinking: /enable_thinking|thinking/i.test(template),
            modalities: p.modalities ?? null,
            slots: slots.ok ? (slots.json ?? []).map((s) => ({ id: s.id, n_ctx: s.n_ctx, speculative: s.speculative })) : slots.status,
        };
    },

    'a-cold': () => chat(jobA(QUESTIONS['a-cold']), BUDGET.help).then(summarise),
    'a-warm': () => chat(jobA(QUESTIONS['a-warm']), BUDGET.help).then(summarise),
    'a-restored': () => chat(jobA(QUESTIONS['a-restored']), BUDGET.help).then(summarise),

    async save() {
        const started = performance.now();
        const r = await http('/slots/0?action=save', { filename: SLOT_FILE });

        return { status: r.status, wallSeconds: round((performance.now() - started) / 1000), response: r.json ?? r.text };
    },

    async restore() {
        const started = performance.now();
        const r = await http('/slots/0?action=restore', { filename: SLOT_FILE });

        return { status: r.status, wallSeconds: round((performance.now() - started) / 1000), response: r.json ?? r.text };
    },

    'b-outage': async () => {
        const run = summarise(await chat(jobB({ risk: 'risk-storefront-outage' }), BUDGET.suggestions));

        return { ...run, quality: parseSuggestions(run.text) };
    },
    'b-payment': async () => {
        const run = summarise(await chat(jobB({ risk: 'risk-payment-breach' }), BUDGET.suggestions));

        return { ...run, quality: parseSuggestions(run.text) };
    },

    c: async () => ({ nodes: jobCNodeCount(), ...summarise(await chat(jobC(), BUDGET.summary)) }),
};

const load = () =>
    existsSync(OUT) ? JSON.parse(readFileSync(OUT, 'utf8')) : { host: HOST_LABEL, config: CONFIG, steps: {} };

const save = (data) => {
    mkdirSync(dirname(OUT), { recursive: true });
    writeFileSync(OUT, JSON.stringify(data, null, 2) + '\n');
};

/**
 * `--record key --value <json>` stores something the driver measured — load
 * time, resident memory, the image digest — alongside the steps, so one file
 * holds the whole configuration.
 */
const record = () => {
    const key = arg('record', null);
    if (!key) return;

    const data = load();
    const raw = arg('value', 'null');
    let value;
    try {
        value = JSON.parse(raw);
    } catch {
        value = raw;
    }
    data.recorded = { ...(data.recorded ?? {}), [key]: value };
    save(data);
};

const run = async () => {
    record();

    for (const name of STEPS) {
        const step = steps[name];
        if (!step) throw new Error('unknown step ' + name);

        console.log('[' + CONFIG + '] ' + name + '...');
        let result;
        try {
            result = await step();
        } catch (error) {
            result = { error: String(error.message ?? error) };
        }

        const data = load();
        data.steps[name] = { ...result, measuredAt: new Date().toISOString() };
        save(data);

        const brief = Object.fromEntries(
            Object.entries(result).filter(([k]) => !['text', 'reasoning', 'timings', 'usage', 'response', 'slots'].includes(k)),
        );
        console.log('  ' + JSON.stringify(brief));
    }
};

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    run().catch((error) => {
        console.error(error);
        process.exitCode = 1;
    });
}
