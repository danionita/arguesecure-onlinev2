/**
 * The weight files the 2.6 candidate benchmark needs, and the only place their
 * provenance is written down.
 *
 * Each entry names a publisher repo and a file, never a URL: `resolve` asks the
 * Hugging Face API for the repo's current commit, lists that commit's tree, and
 * writes `results/candidates-manifest.json` with the commit sha, the byte count
 * and the LFS sha256 of every file. `candidates-fetch.sh` then downloads from
 * that commit — not from `main`, which moves — and refuses to leave a file in
 * place whose size or hash disagrees with the manifest (D18: a truncated
 * download exits 0).
 *
 *   node tools/spike/assistant/candidates-weights.mjs resolve
 *
 * A repo that splits a model into `-00001-of-0000N.gguf` parts is expanded to
 * every part; llama.cpp is pointed at the first. `mmproj*` files are never
 * listed: nothing in this feature sends an image, and a vision projector cost
 * the 2.3 baseline 933 MiB of resident memory for nothing.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

export const MANIFEST = join(here, 'results', 'candidates-manifest.json');

/**
 * `why` is recorded in the manifest because each of these is a choice between
 * several plausible repos, and the reason is not recoverable from the filename.
 */
export const weights = [
    {
        id: 'gemma3-4b-q4km',
        repo: 'ggml-org/gemma-3-4b-it-GGUF',
        file: 'gemma-3-4b-it-Q4_K_M.gguf',
        why: 'The shipped default (D19), from the repo the 2.3 spike verified.',
    },
    {
        id: 'gemma3-4b-qat-q4_0',
        repo: 'ggml-org/gemma-3-4b-it-qat-GGUF',
        file: 'gemma-3-4b-it-qat-Q4_0.gguf',
        why: "google/gemma-3-4b-it-qat-q4_0-gguf is gated behind a manual licence click; ggml-org's is the same QAT checkpoint, ungated.",
    },
    {
        id: 'gemma3-270m-q8_0',
        repo: 'ggml-org/gemma-3-270m-it-GGUF',
        file: 'gemma-3-270m-it-Q8_0.gguf',
        why: 'Draft model for speculative decoding. Shares the gemma3 tokenizer, which drafting requires.',
    },
    {
        id: 'gemma4-e4b-qat',
        repo: 'unsloth/gemma-4-E4B-it-qat-GGUF',
        file: 'gemma-4-E4B-it-qat-UD-Q4_K_XL.gguf',
        why: "The only QAT build published. Its base is google/gemma-4-E4B-it-qat-q4_0-unquantized; no plain Q4_0 of it exists, and ggml-org's Q4_0 is quantized from the non-QAT checkpoint.",
    },
    {
        id: 'qwen35-4b-q4km',
        repo: 'unsloth/Qwen3.5-4B-GGUF',
        file: 'Qwen3.5-4B-Q4_K_M.gguf',
        why: 'Qwen/Qwen3.5-4B-GGUF does not exist (401 from the API).',
    },
    {
        id: 'gemma4-26b-a4b-qat',
        repo: 'unsloth/gemma-4-26B-A4B-it-qat-GGUF',
        file: 'gemma-4-26B-A4B-it-qat-UD-Q4_K_XL.gguf',
        why: 'Mixture of experts. QAT base, the only 4-bit file in the QAT repo.',
    },
    {
        id: 'qwen36-35b-a3b',
        repo: 'unsloth/Qwen3.6-35B-A3B-GGUF',
        file: 'Qwen3.6-35B-A3B-UD-Q4_K_XL.gguf',
        why: 'Mixture of experts. Qwen/Qwen3.6-35B-A3B-GGUF does not exist.',
    },
];

const api = async (path) => {
    const response = await fetch('https://huggingface.co/api/' + path);
    if (!response.ok) throw new Error(path + ' -> HTTP ' + response.status);

    return response.json();
};

/** Every part of a split GGUF, or just the file itself. */
const partsOf = (file, tree) => {
    const split = file.match(/^(.*)-00001-of-(\d{5})\.gguf$/);
    if (!split) return [file];

    const count = Number(split[2]);

    return Array.from(
        { length: count },
        (_, i) => split[1] + '-' + String(i + 1).padStart(5, '0') + '-of-' + split[2] + '.gguf',
    ).filter((p) => tree.has(p));
};

async function resolve() {
    const out = [];

    for (const w of weights) {
        const model = await api('models/' + w.repo);
        const tree = new Map(
            (await api('models/' + w.repo + '/tree/' + model.sha + '?recursive=true'))
                .filter((e) => e.type === 'file')
                .map((e) => [e.path, e]),
        );

        const parts = partsOf(w.file, tree).map((path) => {
            const entry = tree.get(path);
            if (!entry) throw new Error(w.repo + '@' + model.sha + ' has no ' + path);
            if (!entry.lfs?.oid) throw new Error(path + ' is not an LFS file; no sha256 to verify against');

            return { path, bytes: entry.lfs.size ?? entry.size, sha256: entry.lfs.oid };
        });

        out.push({ ...w, sha: model.sha, gated: model.gated ?? false, parts });
        console.log(
            w.id.padEnd(22) +
                (parts.reduce((t, p) => t + p.bytes, 0) / 1e9).toFixed(2).padStart(7) +
                ' GB  ' +
                w.repo +
                '@' +
                model.sha.slice(0, 10) +
                '  ' +
                parts.map((p) => p.path).join(', '),
        );
    }

    writeFileSync(
        MANIFEST,
        JSON.stringify({ resolvedAt: new Date().toISOString(), weights: out }, null, 2) + '\n',
    );
    console.log('\nwritten to ' + MANIFEST);
}

/**
 * One line per file for `candidates-fetch.sh`, which has no JSON parser:
 * `repo sha path bytes sha256`, optionally only the ids named after `tsv`.
 */
async function tsv(ids) {
    const { weights: resolved } = JSON.parse(readFileSync(MANIFEST, 'utf8'));

    for (const w of resolved) {
        if (ids.length && !ids.includes(w.id)) continue;
        for (const p of w.parts) {
            console.log([w.repo, w.sha, p.path, p.bytes, p.sha256].join('\t'));
        }
    }
}

const commands = { resolve, tsv };
const command = commands[process.argv[2]];

if (command) {
    command(process.argv.slice(3)).catch((error) => {
        console.error(error);
        process.exitCode = 1;
    });
}
