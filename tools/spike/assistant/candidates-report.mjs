/**
 * Prints the tables in `docs/spikes/2.6-models.md` from
 * `results/candidates-*.json`, so the document can be regenerated after a
 * re-run on another host rather than retyped.
 *
 *   node tools/spike/assistant/candidates-report.mjs unraid
 *
 * One host per report — figures from two machines side by side invite exactly
 * the comparison 2.3-inference.md says not to make. Configurations print in
 * the order the driver runs them. A step that failed or never ran prints as
 * "—", never as a zero: absence must not read as a measurement (see report.mjs).
 */
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const dir = join(here, 'results');
const ORDER = ['baseline', 'gemma3-qat', 'gemma3-qat-draft', 'gemma4-e4b', 'qwen35-4b', 'gemma4-26b-a4b', 'qwen36-35b-a3b'];
const NOT_RUNS = new Set(['candidates-manifest.json', 'candidates-estimate.json']);

const all = readdirSync(dir)
    .filter((f) => /^candidates-.+\.json$/.test(f) && !NOT_RUNS.has(f))
    .map((f) => JSON.parse(readFileSync(join(dir, f), 'utf8')));
const hosts = [...new Set(all.map((c) => c.host))];
const HOST = process.argv[2];

if (!HOST || !hosts.includes(HOST)) {
    console.error('usage: node candidates-report.mjs <host>   (hosts with results: ' + (hosts.join(', ') || 'none') + ')');
    process.exit(1);
}

const configs = all
    .filter((c) => c.host === HOST)
    // Driver order, with a suffixed re-run (`qwen35-4b-budget0`) after its base.
    .sort((a, b) => {
        const rank = (c) => {
            const i = ORDER.findLastIndex((o) => c === o || c.startsWith(o + '-'));

            return i === -1 ? ORDER.length : i;
        };

        return rank(a.config) - rank(b.config) || a.config.localeCompare(b.config);
    });

const dash = '—';
const s = (n) => (typeof n === 'number' ? n.toFixed(1) + 's' : dash);
const r = (n) => (typeof n === 'number' ? n.toFixed(1) : dash);
const gib = (bytes) => (typeof bytes === 'number' ? (bytes / 2 ** 30).toFixed(2) + ' GiB' : dash);
const ok = (step) => step && !step.error;
const cache = (step) => (ok(step) && step.promptProcessed != null ? step.promptProcessed + ' / ' + step.promptFromCache : dash);
const table = (head, rows) =>
    ['| ' + head.join(' | ') + ' |', '|' + head.map(() => '---').join('|') + '|', ...rows.map((row) => '| ' + row.join(' | ') + ' |')].join('\n');

const step = (c, name) => c.steps?.[name];

const machine = configs.find((c) => c.recorded?.host)?.recorded.host;
if (machine) {
    console.log(
        '**Host `' + HOST + '`:** ' + machine.cpu + ', ' + machine.physicalCores + ' physical cores / ' +
            machine.logicalCpus + ' logical, ' + gib(machine.memTotalBytes) + ' RAM visible to Docker, kernel ' +
            machine.kernel + ', Docker ' + machine.docker + '. Benchmarked on ' + machine.cores + ' cores' +
            (machine.cpuset ? ' (cpuset ' + machine.cpuset + ')' : '') + '; ' + machine.flags.join(', ') + '.\n',
    );
}

const skipped = configs.filter((c) => c.recorded?.skipped || c.recorded?.failed);
if (skipped.length) {
    console.log('### Not measured\n');
    for (const c of skipped) {
        console.log('- `' + c.config + '`: ' + (c.recorded.skipped ?? c.recorded.failed));
    }
    console.log('');
}

console.log('### Job A — help answer (320-token budget)\n');
console.log(
    table(
        ['Config', 'Cold', 'processed / cached', 'Warm', 'processed / cached', 'After restart + restore', 'processed / cached', 'Prompt tok/s (cold)', 'Gen tok/s (cold)'],
        configs.map((c) => {
            const [cold, warm, rest] = ['a-cold', 'a-warm', 'a-restored'].map((n) => step(c, n));

            return [
                '`' + c.config + '`',
                s(cold?.wallSeconds),
                cache(cold),
                s(warm?.wallSeconds),
                cache(warm),
                s(rest?.wallSeconds),
                cache(rest),
                r(cold?.promptTokensPerSecond),
                r(cold?.outputTokensPerSecond),
            ];
        }),
    ),
);

console.log('\n### Job B — suggest attacks (512-token budget)\n');
console.log(
    table(
        ['Config', 'Storefront outage', 'Payment breach', 'First token', 'Gen tok/s', 'Output tokens', 'Well-formed', 'Draft acceptance'],
        configs.map((c) => {
            const runs = ['b-outage', 'b-payment'].map((n) => step(c, n));
            const accept = runs.filter((x) => x?.draftAcceptance != null).map((x) => (x.draftAcceptance * 100).toFixed(0) + '%');

            return [
                '`' + c.config + '`',
                s(runs[0]?.wallSeconds),
                s(runs[1]?.wallSeconds),
                runs.map((x) => s(x?.firstTokenSeconds)).join(', '),
                runs.map((x) => r(x?.outputTokensPerSecond)).join(', '),
                runs.map((x) => x?.outputTokens ?? dash).join(', '),
                runs.map((x) => (x?.quality ? x.quality.wellFormed + '/' + x.quality.items : dash)).join(', '),
                accept.join(', ') || dash,
            ];
        }),
    ),
);

console.log('\n### Job C — executive summary (700-token budget)\n');
console.log(
    table(
        ['Config', 'Wall', 'Prompt tokens', 'Prompt tok/s', 'Output tokens', 'Gen tok/s', 'Finish', 'Draft acceptance'],
        configs.map((c) => {
            const x = step(c, 'c');

            return [
                '`' + c.config + '`',
                s(x?.wallSeconds),
                ok(x) ? String((x.promptProcessed ?? 0) + (x.promptFromCache ?? 0)) : dash,
                r(x?.promptTokensPerSecond),
                x?.outputTokens ?? dash,
                r(x?.outputTokensPerSecond),
                x?.finishReason ?? (x?.error ? 'error' : dash),
                x?.draftAcceptance != null ? (x.draftAcceptance * 100).toFixed(0) + '%' : dash,
            ];
        }),
    ),
);

console.log('\n### Memory, load and restart\n');
console.log(
    table(
        ['Config', 'After load', 'After job A', 'After job C', 'cgroup peak', 'Load', 'Restart', 'Slot save', 'Slot restore'],
        configs.map((c) => {
            const m = c.recorded ?? {};
            const stat = (x) => (x?.dockerStats ? x.dockerStats.split(' / ')[0] : dash);
            const peak = Math.max(...['memoryAfterLoad', 'memoryAfterA', 'memoryAfterC'].map((k) => m[k]?.cgroupPeakBytes ?? 0));
            const slot = (x, n, bytes) =>
                x?.status === 200 ? x.response[n] + ' tok, ' + (x.response[bytes] / 2 ** 20).toFixed(0) + ' MiB' : x ? 'HTTP ' + x.status : dash;

            return [
                '`' + c.config + '`',
                stat(m.memoryAfterLoad),
                stat(m.memoryAfterA),
                stat(m.memoryAfterC),
                peak ? gib(peak) : dash,
                s(Number(m.loadSeconds)),
                s(Number(m.restartSeconds)),
                slot(step(c, 'save'), 'n_saved', 'n_written'),
                slot(step(c, 'restore'), 'n_restored', 'n_read'),
            ];
        }),
    ),
);

console.log('\n### Was reasoning actually off?\n');
const JOBS = ['a-cold', 'a-warm', 'a-restored', 'b-outage', 'b-payment', 'c'];
console.log(
    table(
        ['Config', 'Jobs that reasoned', '`reasoning_content` chars', 'Markers in reply', 'Build', 'Slot n_ctx'],
        configs.map((c) => {
            const runs = JOBS.map((n) => [n, step(c, n)]).filter(([, x]) => ok(x));
            const reasoned = runs.filter(([, x]) => x.reasoned).map(([n]) => n);

            return [
                '`' + c.config + '`',
                runs.length ? (reasoned.length ? reasoned.join(', ') : 'none of ' + runs.length) : dash,
                runs.reduce((t, [, x]) => t + (x.reasoningChars ?? 0), 0),
                [...new Set(runs.flatMap(([, x]) => x.markersInContent ?? []))].join(' ') || 'none',
                step(c, 'props')?.buildInfo ?? dash,
                step(c, 'props')?.slotContext ?? dash,
            ];
        }),
    ),
);

const failures = configs.flatMap((c) =>
    Object.entries(c.steps ?? {})
        .filter(([, x]) => x.error)
        .map(([n, x]) => '- `' + c.config + '` ' + n + ': ' + x.error),
);
if (failures.length) console.log('\n### Steps that failed\n\n' + failures.join('\n'));
