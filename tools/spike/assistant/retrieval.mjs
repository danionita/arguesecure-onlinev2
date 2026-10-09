/**
 * Open question 4: "for job A, is a plain full-text search over the help
 * corpus enough, or is an embedding index worth its own container? Try the
 * cheap one first."
 *
 * This is the cheap one. BM25 over the corpus split at its own headings, no
 * dependency, no container, about eighty lines. It is scored against a set of
 * questions written the way a user asks them — deliberately using words the
 * target section does *not* contain, because a retriever that only works when
 * the question quotes the document is not a retriever.
 *
 * The result decides two things, not one: whether retrieval is needed at all
 * (the whole corpus fits in the context window), and if it is, whether keyword
 * matching clears the bar before an embedding container is considered.
 *
 *   node tools/spike/assistant/retrieval.mjs
 */
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..', '..');
const helpDir = join(repo, 'resources', 'markdown', 'help');

/** The corpus, split at its own `##` headings. */
export function sections() {
    const out = [];

    for (const file of readdirSync(helpDir).filter((f) => f.endsWith('.md'))) {
        const text = readFileSync(join(helpDir, file), 'utf8');
        const slug = file.replace(/\.md$/, '');
        let title = slug;
        let buffer = [];

        const flush = () => {
            const body = buffer.join('\n').trim();
            if (body) out.push({ id: slug + '#' + title, file: slug, title, body });
        };

        for (const line of text.split('\n')) {
            const heading = line.match(/^#{1,2}\s+(.*)$/);
            if (heading) {
                flush();
                title = heading[1].trim();
                buffer = [];
                continue;
            }
            buffer.push(line);
        }
        flush();
    }

    return out;
}

const STOP = new Set(
    ('a an and are as at be but by do does for from how i if in is it its my no ' +
        'not of on or our so than that the their then there these they this to ' +
        'was what when where which who why will with you your').split(/\s+/),
);

const terms = (text) =>
    text
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, ' ')
        .split(/\s+/)
        .filter((t) => t.length > 2 && !STOP.has(t));

/** Textbook BM25. k1 and b at their usual defaults; nothing tuned to the set. */
export function rank(query, docs, { k1 = 1.5, b = 0.75 } = {}) {
    const tokenised = docs.map((d) => terms(d.title + ' ' + d.title + ' ' + d.body));
    const lengths = tokenised.map((t) => t.length);
    const avgLength = lengths.reduce((a, l) => a + l, 0) / lengths.length;

    const df = new Map();
    for (const doc of tokenised) {
        for (const term of new Set(doc)) {
            df.set(term, (df.get(term) ?? 0) + 1);
        }
    }

    const queryTerms = terms(query);

    return docs
        .map((doc, i) => {
            const counts = new Map();
            for (const term of tokenised[i]) {
                counts.set(term, (counts.get(term) ?? 0) + 1);
            }

            let score = 0;
            for (const term of queryTerms) {
                const n = df.get(term);
                if (!n) continue;

                const idf = Math.log(1 + (docs.length - n + 0.5) / (n + 0.5));
                const f = counts.get(term) ?? 0;
                score +=
                    (idf * (f * (k1 + 1))) /
                    (f + k1 * (1 - b + (b * lengths[i]) / avgLength));
            }

            return { doc, score };
        })
        .sort((x, y) => y.score - x.score);
}

/**
 * The evaluation set.
 *
 * `expect` is the section that actually answers the question. The questions
 * avoid the target's own vocabulary wherever a user plausibly would: "how
 * effective" rather than "strength", "someone else editing at the same time"
 * rather than "locking".
 */
const EVAL = [
    ['What is the difference between a defence and a transfer?', 'concepts#Concepts'],
    ['Can the same attack show up under two risks?', 'concepts#Why this is a DAG, not a tree'],
    ['Why did my total not move when I set a reduction target?', 'quantification#Reduction target does not change the total'],
    ['Is impact the yearly cost or the cost each time?', 'quantification#Two assumptions you must not get backwards'],
    ['What happens if I leave a likelihood blank?', 'quantification#What a missing value does — and the deliberate asymmetry'],
    ['Do two controls on the same attack really multiply?', 'quantification#Stacking defences assumes independence'],
    ['What do inherent, current and target mean?', 'quantification#Reading the totals'],
    ['Someone else is editing the same field as me', 'collaboration#Field-level locking'],
    ['How do I know who else has the assessment open?', 'collaboration#Presence'],
    ['Do I have to press save?', 'collaboration#Autosave and conflicts'],
    ['Can I let a client look without giving them an account?', 'sharing#The read-only share link'],
    ['If I make it public can strangers change it?', 'sharing#The public flag'],
    ['What can an editor do that a viewer cannot?', 'sharing#Roles'],
    ['How do I get a PDF of the report?', 'reading-a-report#Producing a PDF'],
    ['What should I check before generating the report?', 'reading-a-report#Before you generate it'],
    ['Is there a shortcut for adding a child node?', 'keyboard#Keyboard map'],
];

const docs = sections();
const corpusChars = docs.reduce((a, d) => a + d.body.length + d.title.length, 0);

let hitsAt1 = 0;
let hitsAt3 = 0;
let selectedChars = 0;
const rows = [];

for (const [question, expected] of EVAL) {
    const ranked = rank(question, docs);
    const top = ranked.slice(0, 3);
    const at1 = top[0]?.doc.id === expected;
    const at3 = top.some((r) => r.doc.id === expected);

    if (at1) hitsAt1 += 1;
    if (at3) hitsAt3 += 1;
    selectedChars += top.reduce((a, r) => a + r.doc.body.length + r.doc.title.length, 0);

    rows.push({
        question,
        expected,
        got: top.map((r) => r.doc.id),
        at1,
        at3,
    });
}

const summary = {
    sections: docs.length,
    corpusChars,
    corpusApproxTokens: Math.round(corpusChars / 4),
    questions: EVAL.length,
    recallAt1: hitsAt1 / EVAL.length,
    recallAt3: hitsAt3 / EVAL.length,
    meanTop3Chars: Math.round(selectedChars / EVAL.length),
    meanTop3ApproxTokens: Math.round(selectedChars / EVAL.length / 4),
    rows,
};

writeFileSync(
    join(here, 'results', 'retrieval.json'),
    JSON.stringify(summary, null, 2),
);

console.log('sections            ', summary.sections);
console.log('whole corpus        ', corpusChars, 'chars ~', summary.corpusApproxTokens, 'tok');
console.log('mean top-3 selection', summary.meanTop3Chars, 'chars ~', summary.meanTop3ApproxTokens, 'tok');
console.log('recall@1            ', (summary.recallAt1 * 100).toFixed(0) + '%');
console.log('recall@3            ', (summary.recallAt3 * 100).toFixed(0) + '%');
console.log('');
for (const row of rows) {
    if (row.at3) continue;
    console.log('MISS  ', row.question);
    console.log('  want', row.expected);
    console.log('  got ', row.got.join(' | '));
}
