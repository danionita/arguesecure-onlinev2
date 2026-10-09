/**
 * Merges the result files into the tables that go into
 * `docs/spikes/2.3-inference.md`.
 *
 * There is more than one file because the first run lost job C and the
 * red-team cases to a client timeout (see `generate`), so the missing halves
 * were re-measured separately. Merging is by model name, later files winning
 * per job — which is also what makes a partial re-run useful in future rather
 * than a reason to redo everything.
 *
 *   node tools/spike/assistant/report.mjs results/bench.json results/bench-c-rt.json
 */
import { readFileSync } from 'node:fs';
import { dirname, isAbsolute, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { cases as redteamCases } from './redteam.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const files = process.argv.slice(2);

if (files.length === 0) {
    console.error('usage: node report.mjs <results.json> [more.json ...]');
    process.exit(1);
}

const merged = new Map();
let meta = {};

for (const file of files) {
    const path = isAbsolute(file) ? file : join(process.cwd(), file);
    const run = JSON.parse(readFileSync(path, 'utf8'));
    meta = { ...meta, ...run };

    for (const model of run.models ?? []) {
        const existing = merged.get(model.model) ?? { model: model.model };

        for (const [key, value] of Object.entries(model)) {
            // A later run's null means "not measured this time", never
            // "measured as nothing" — it must not erase an earlier result.
            // The same goes for an empty array: a run invoked with `--jobs a,b`
            // has no red-team results, and letting that overwrite a run that
            // does silently blanks the safety table.
            if (value === null || value === undefined) continue;
            if (Array.isArray(value) && value.length === 0) continue;
            if (key === 'error' && existing.error === undefined && value) {
                existing.error = value;
                continue;
            }
            if (key !== 'error') existing[key] = value;
        }

        merged.set(model.model, existing);
    }
}

const models = [...merged.values()];
const gib = (b) => (b ? (b / 2 ** 30).toFixed(1) + ' GiB' : '—');
const s = (n) => (n == null ? '—' : n.toFixed(0) + 's');

console.log('### Candidates\n');
console.log(
    '| Model | Resident | Job B | Job B tok/s | JSON | Job A first | Job A after | Job C |',
);
console.log('|---|---|---|---|---|---|---|---|');

for (const m of models) {
    const a = m.jobA?.runs ?? [];
    console.log(
        '| `' +
            m.model +
            '` | ' +
            gib(m.residentBytes) +
            ' | ' +
            s(m.jobB?.medianWallSeconds) +
            ' | ' +
            (m.jobB ? m.jobB.medianOutputTokensPerSecond.toFixed(1) : '—') +
            ' | ' +
            (m.jobB ? m.jobB.wellFormed + '/' + m.jobB.of : '—') +
            ' | ' +
            s(a[0]?.wallSeconds) +
            ' | ' +
            s(a[1]?.wallSeconds) +
            ' | ' +
            s(m.jobC?.wallSeconds) +
            ' |',
    );
}

console.log('\n### Red team\n');

const withRt = models.filter((m) => (m.redteam ?? []).length);
console.log('| Case | Job | ' + withRt.map((m) => m.model).join(' | ') + ' |');
console.log('|---|---|' + withRt.map(() => '---|').join(''));

for (const testCase of redteamCases) {
    const cells = withRt.map((m) => {
        const hit = (m.redteam ?? []).find((r) => r.id === testCase.id);
        if (!hit) return '—';
        // `void` where the model returned no text: it neither complied nor
        // resisted, and counting it as resistance is a false negative that
        // flatters exactly the models least able to do the job.
        if (hit.answered === false || hit.excerpt.trim() === '') return 'void';

        return hit.complied ? '**complied**' : 'held';
    });
    console.log(
        '| `' + testCase.id + '` | ' + testCase.job + ' | ' + cells.join(' | ') + ' |',
    );
}

// Recomputed from the merged cases, never read from the stored scalar: a run
// that skipped the phase wrote a zero, and a zero that overwrites a real count
// turns "two attacks succeeded" into a clean bill of health.
console.log(
    '| **complied** | | ' +
        withRt
            .map((m) => {
                const cases = m.redteam ?? [];
                const complied = cases.filter((r) => r.complied === true).length;
                const answered = cases.filter(
                    (r) => r.answered !== false && r.excerpt.trim() !== '',
                ).length;

                return answered === 0
                    ? 'no output'
                    : complied + '/' + answered;
            })
            .join(' | ') +
        ' |',
);

console.log('\n### Job C prompt\n');
for (const m of models) {
    if (!m.jobC) continue;
    console.log(
        '- `' +
            m.model +
            '`: ' +
            m.jobC.promptTokens +
            ' prompt tokens, ' +
            m.jobC.outputTokens +
            ' output tokens, ' +
            m.jobC.wallSeconds.toFixed(0) +
            's for ' +
            (meta.jobCNodeCount ?? '?') +
            ' nodes',
    );
}

void here;
