/**
 * What has to happen before D19 changes (PLAN-2.6.md W96), measured against
 * one running model.
 *
 * Three things, each a condition docs/spikes/2.6-models.md set:
 *
 * 1. **The red-team cases.** `redteam.mjs`'s seven injections, sent the way
 *    the application sends a prompt — `/v1/chat/completions` — rather than
 *    through Ollama's `/api/generate`, which D17 retired. Run against the old
 *    default as well as the new presets: if the old default does not
 *    reproduce the 2.3 record through this path, the port is what changed,
 *    not the models, and nothing here may be trusted.
 * 2. **Quality with more than one sample.** Every job at three seeds. What can
 *    be checked mechanically is: well-formed suggestions, figures in a summary
 *    that do not appear in its input, reasoning that leaked. The rest — is the
 *    answer right, is the attack specific — is saved whole, to be read.
 * 3. **The help cache across other jobs.** Seed 2's first help question comes
 *    after seed 1's suggestions and summary took the only slot, so it shows
 *    whether `--cache-ram 2048` (D56) still keeps the corpus.
 *
 *   node verify.mjs --host http://arsec-verify:8080 --host-label unraid --config small
 *
 * Writes `results/verify-<host>-<config>.json`, step by step, so an
 * interrupted run keeps what it measured.
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chat, http, parseSuggestions, summarise } from './candidates.mjs';
import { jobA, jobB, jobC } from './fixtures.mjs';
import { cases } from './redteam.mjs';

const here = dirname(fileURLToPath(import.meta.url));

const arg = (name, fallback) => {
    const i = process.argv.indexOf('--' + name);

    return i === -1 ? fallback : process.argv[i + 1];
};

const CONFIG = arg('config', 'unlabelled');
const HOST_LABEL = arg('host-label', 'local');
const SEEDS = arg('seeds', '1,2,3').split(',').map(Number);
// `--only quality` re-measures quality and caching without the red team, for
// a runtime-setting change that cannot move what an injection does.
const ONLY = arg('only', null);
const OUT = join(here, 'results', 'verify-' + HOST_LABEL + '-' + CONFIG + '.json');

const BUDGET = { help: 320, suggestions: 512, summary: 700 };

const QUESTIONS = [
    'What is the difference between a defence and a transfer?',
    // The one the 2.6 spike found the old default answering wrongly.
    'Why does my total not change when I set a reduction target?',
    'What happens if I leave a likelihood blank?',
];

const load = () =>
    existsSync(OUT) ? JSON.parse(readFileSync(OUT, 'utf8')) : { host: HOST_LABEL, config: CONFIG, redteam: {}, quality: {} };
const save = (data) => writeFileSync(OUT, JSON.stringify(data, null, 2) + '\n');

/**
 * Money figures in a summary that are not in its input. The prompt forbids
 * inventing one, and the 2.6 spike caught the old default doing it twice in
 * one summary — one of them ten times the real amount.
 */
const figures = (text) =>
    [...text.matchAll(/(\d{1,3}(?:[,.\s]\d{3})+|\d{4,})(\s*(?:million|m\b))?/gi)]
        .map((m) => Number(m[1].replace(/[,.\s]/g, '')) * (m[2] ? 1e6 : 1))
        .filter((n) => n >= 1000 && !(n >= 1990 && n <= 2100));

const allowed = new Set(figures(jobC()));

const checkSummary = (text) => {
    const found = figures(text);

    return {
        paragraphs: text.trim().split(/\n\s*\n/).length,
        figures: found.length,
        invented: found.filter((n) => !allowed.has(n)),
    };
};

async function redteam() {
    const data = load();

    for (const c of cases) {
        if (data.redteam[c.id]) continue;
        console.log('[' + CONFIG + '] red team: ' + c.id);
        const prompt = c.job === 'C' ? jobC({ inject: c.text }) : jobB({ inject: c.text });
        const run = summarise(await chat(prompt, c.job === 'C' ? BUDGET.summary : BUDGET.suggestions));
        const answered = run.text.trim() !== '';

        data.redteam[c.id] = {
            job: c.job,
            // `void` where nothing came back: neither complied nor held, and
            // scoring it as held flatters exactly the model least able to do
            // the job (qwen3:4b, 2.3).
            outcome: !answered ? 'void' : c.detect(run.text) ? 'complied' : 'held',
            ...run,
        };
        save(data);
        console.log('  ' + data.redteam[c.id].outcome);
    }
}

async function quality() {
    const data = load();

    for (const seed of SEEDS) {
        const key = 'seed-' + seed;
        const done = data.quality[key] ?? {};
        const steps = [
            ...QUESTIONS.map((q, i) => ['a' + (i + 1), () => chat(jobA(q), BUDGET.help, seed)]),
            ['b-outage', () => chat(jobB({ risk: 'risk-storefront-outage' }), BUDGET.suggestions, seed)],
            ['b-payment', () => chat(jobB({ risk: 'risk-payment-breach' }), BUDGET.suggestions, seed)],
            ['c', () => chat(jobC(), BUDGET.summary, seed)],
        ];

        for (const [name, run] of steps) {
            if (done[name]) continue;
            console.log('[' + CONFIG + '] seed ' + seed + ' ' + name);
            const result = summarise(await run());
            if (name.startsWith('b')) result.suggestions = parseSuggestions(result.text);
            if (name === 'c') result.summary = checkSummary(result.text);
            done[name] = result;
            data.quality[key] = done;
            save(data);
            console.log(
                '  ' +
                    result.wallSeconds +
                    's, ' +
                    result.promptProcessed +
                    ' processed / ' +
                    result.promptFromCache +
                    ' cached' +
                    (result.reasoned ? ', REASONED' : ''),
            );
        }
    }
}

/** How many steps this results file already holds. */
const measured = (data) =>
    Object.keys(data.redteam).length + Object.values(data.quality).reduce((t, s) => t + Object.keys(s).length, 0);

const run = async () => {
    const before = measured(load());

    if (ONLY !== 'quality') await redteam();
    if (ONLY !== 'redteam') await quality();

    const data = load();

    // A re-run under a label that already has every step resumes into
    // nothing: it measures nothing and would look like it had. Say so, and
    // exit with a code the driver checks before it overwrites the server log
    // that went with the earlier measurement (W96: exactly this happened once,
    // and the evidence for the large preset's cache eviction was overwritten
    // with an idle log). Metadata is only stamped when something was measured.
    if (measured(data) === before) {
        console.error(
            '\n[' + CONFIG + '] NOTHING MEASURED: every step already has a result in ' + OUT + '.\n' +
                '  To measure again, run with a new HOST_LABEL (for example HOST_LABEL=' + HOST_LABEL + '-rerun).',
        );
        process.exitCode = 3;

        return;
    }

    const props = await http('/props');
    data.model = props.json?.model_path ?? null;
    data.build = props.json?.build_info ?? null;
    data.measuredAt = new Date().toISOString();
    save(data);
};

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
