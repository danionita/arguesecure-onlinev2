/**
 * Prints the W96 tables from `results/verify-<host>-*.json`: the red-team
 * matrix beside the 2.3 record, and the multi-sample quality checks.
 *
 *   node tools/spike/assistant/verify-report.mjs unraid
 *
 * The 2.3 column is read from tests/fixtures/assistant/injections.json, so
 * the control's agreement with the record is checked, not asserted.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const HOST = process.argv[2] ?? 'unraid';
const ORDER = ['control', 'small', 'large'];

const runs = readdirSync(join(here, 'results'))
    .filter((f) => f.startsWith('verify-' + HOST + '-') && f.endsWith('.json'))
    .map((f) => JSON.parse(readFileSync(join(here, 'results', f), 'utf8')))
    .sort((a, b) => ORDER.indexOf(a.config) - ORDER.indexOf(b.config));

const fixture = JSON.parse(readFileSync(join(here, '..', '..', '..', 'tests', 'fixtures', 'assistant', 'injections.json'), 'utf8'));
const recorded = fixture.measured.models[0];

const table = (head, rows) =>
    ['| ' + head.join(' | ') + ' |', '|' + head.map(() => '---').join('|') + '|', ...rows.map((r) => '| ' + r.join(' | ') + ' |')].join('\n');
const bold = (o) => (o === 'complied' ? '**complied**' : o);

console.log('### Red team (host `' + HOST + '`)\n');
console.log(
    table(
        ['Case', 'Job', recorded + ' (2.3 record)', ...runs.map((r) => r.config)],
        fixture.cases.map((c) => [
            '`' + c.id + '`',
            c.job,
            bold(c.outcome[recorded]),
            ...runs.map((r) => bold(r.redteam[c.id]?.outcome ?? '—')),
        ]),
    ),
);

console.log('\n### Quality, per seed\n');
const QUESTIONS = ['a1', 'a2', 'a3'];
for (const r of runs) {
    const seeds = Object.entries(r.quality);
    const all = seeds.flatMap(([, s]) => Object.values(s));
    const reasoned = all.filter((x) => x.reasoned).length;
    const wellFormed = seeds.flatMap(([, s]) => ['b-outage', 'b-payment'].map((k) => s[k]?.suggestions)).filter(Boolean);
    const invented = seeds.map(([, s]) => s.c?.summary?.invented ?? []);
    const cacheAfterOthers = seeds.slice(1).map(([, s]) => s.a1 && s.a1.promptFromCache + '/' + (s.a1.promptFromCache + s.a1.promptProcessed));

    console.log('**`' + r.config + '`** (`' + (r.model ?? '?').split('/').pop() + '`, ' + r.build + ')\n');
    console.log('- replies that reasoned: ' + reasoned + ' of ' + all.length);
    console.log('- suggestions well-formed: ' + wellFormed.map((w) => w.wellFormed + '/' + w.items).join(', '));
    console.log('- summary figures not in the input, per seed: ' + invented.map((i) => (i.length ? i.join(' ') : 'none')).join(' · '));
    console.log('- summary paragraphs per seed: ' + seeds.map(([, s]) => s.c?.summary?.paragraphs ?? '—').join(', '));
    console.log('- help prefix served from cache after jobs B and C: ' + (cacheAfterOthers.join(', ') || '—'));
    console.log(
        '- wall seconds, mean over seeds: ' +
            ['a1', 'a2', 'a3', 'b-outage', 'b-payment', 'c']
                .map((k) => {
                    const xs = seeds.map(([, s]) => s[k]?.wallSeconds).filter((x) => typeof x === 'number');

                    return k + ' ' + (xs.length ? (xs.reduce((t, x) => t + x, 0) / xs.length).toFixed(1) : '—');
                })
                .join(', '),
    );
    console.log('');
    void QUESTIONS;
}
