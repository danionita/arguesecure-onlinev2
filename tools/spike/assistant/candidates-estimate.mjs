/**
 * What a model reads per generated token, from its GGUF header alone.
 *
 * The mixture-of-experts candidates do not fit on the machine the 2.6 spike
 * ran on, and benchmarking them from swap would measure the disk. What can be
 * known without running them is how many bytes of weights each generated
 * token touches — and on a CPU, generation is memory-bandwidth bound
 * (2.3-threads.md: doubling cores bought 16%), so that figure divided into a
 * bandwidth *measured on the dense models here* is a defensible estimate.
 *
 * The header is fetched with HTTP Range requests from the pinned commit, a few
 * megabytes per model rather than the whole file. Tensor sizes are taken from
 * the gaps between data offsets, not from a table of quantisation block sizes,
 * so the figure is exact for whatever types the file mixes.
 *
 *   node tools/spike/assistant/candidates-estimate.mjs
 *
 * Writes `results/candidates-estimate.json`.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { MANIFEST } from './candidates-weights.mjs';

const here = dirname(fileURLToPath(import.meta.url));

/** A reader over a remote file that fetches more of it only when a read needs it. */
class RemoteReader {
    constructor(url) {
        this.url = url;
        this.buffer = Buffer.alloc(0);
        this.pos = 0;
    }

    async ensure(n) {
        while (this.pos + n > this.buffer.length) {
            const from = this.buffer.length;
            const to = from + 8 * 2 ** 20 - 1;
            this.buffer = Buffer.concat([this.buffer, await this.range(from, to)]);
        }
    }

    /** The CDN behind a resolve URL drops the odd connection; retry those. */
    async range(from, to, attempt = 1) {
        try {
            const response = await fetch(this.url, { headers: { range: 'bytes=' + from + '-' + to } });
            if (response.status !== 206) throw new Error(this.url + ' -> ' + response.status + ' (no range support?)');

            return Buffer.from(await response.arrayBuffer());
        } catch (error) {
            if (attempt >= 5 || /range support/.test(error.message)) throw error;
            await new Promise((resolve) => setTimeout(resolve, 2000 * attempt));

            return this.range(from, to, attempt + 1);
        }
    }

    async u32() {
        await this.ensure(4);
        const v = this.buffer.readUInt32LE(this.pos);
        this.pos += 4;

        return v;
    }

    async u64() {
        await this.ensure(8);
        const v = this.buffer.readBigUInt64LE(this.pos);
        this.pos += 8;

        return Number(v);
    }

    async bytes(n) {
        await this.ensure(n);
        const v = this.buffer.subarray(this.pos, this.pos + n);
        this.pos += n;

        return v;
    }

    async string() {
        return (await this.bytes(await this.u64())).toString('utf8');
    }
}

const SCALAR = { 0: 1, 1: 1, 2: 2, 3: 2, 4: 4, 5: 4, 6: 4, 7: 1, 10: 8, 11: 8, 12: 8 };

async function value(r, type) {
    if (type === 8) return r.string();
    if (type === 9) {
        const itemType = await r.u32();
        const count = await r.u64();
        // Token lists run to hundreds of thousands of entries; only their
        // length is interesting here.
        for (let i = 0; i < count; i++) await value(r, itemType);

        return { array: count };
    }
    const size = SCALAR[type];
    const raw = await r.bytes(size);
    if (type === 4) return raw.readUInt32LE(0);
    if (type === 5) return raw.readInt32LE(0);
    if (type === 6) return raw.readFloatLE(0);
    if (type === 7) return raw[0] !== 0;
    if (type === 10) return Number(raw.readBigUInt64LE(0));

    return raw.length === 1 ? raw[0] : raw.readUInt16LE(0);
}

async function header(url, fileBytes) {
    const r = new RemoteReader(url);
    if ((await r.bytes(4)).toString() !== 'GGUF') throw new Error(url + ' is not a GGUF');
    await r.u32();
    const tensorCount = await r.u64();
    const kvCount = await r.u64();

    const meta = {};
    for (let i = 0; i < kvCount; i++) {
        const key = await r.string();
        meta[key] = await value(r, await r.u32());
    }

    const tensors = [];
    for (let i = 0; i < tensorCount; i++) {
        const name = await r.string();
        const dims = [];
        for (let d = await r.u32(); d > 0; d--) dims.push(await r.u64());
        tensors.push({ name, dims, type: await r.u32(), offset: await r.u64() });
    }

    const alignment = meta['general.alignment'] ?? 32;
    const dataStart = Math.ceil(r.pos / alignment) * alignment;

    tensors.sort((a, b) => a.offset - b.offset);
    tensors.forEach((t, i) => {
        t.bytes = (i + 1 < tensors.length ? tensors[i + 1].offset : fileBytes - dataStart) - t.offset;
    });

    return { meta, tensors };
}

/**
 * Bytes of weights read to produce one token.
 *
 * - Expert tensors (`*_exps`) count at `used / total` of their size: the
 *   router picks `expert_used_count` of `expert_count` per layer per token.
 * - Embedding lookups read one row, not the table: `token_embd` is excluded
 *   when a separate `output` projection exists, and Gemma 4's per-layer
 *   embedding tables (`per_layer_token_embd`) are always excluded — they are
 *   why an "E4B" file is larger than a 4B one without being slower per token.
 * - When `output.weight` is absent the embedding is tied and is read in full
 *   for the logits, so it counts.
 */
function perToken({ meta, tensors }) {
    const arch = meta['general.architecture'];
    const experts = meta[arch + '.expert_count'] ?? 0;
    const used = meta[arch + '.expert_used_count'] ?? 0;
    const tied = !tensors.some((t) => t.name === 'output.weight');

    let shared = 0;
    let expert = 0;
    let lookup = 0;
    for (const t of tensors) {
        if (/per_layer_token_embd/.test(t.name) || (t.name === 'token_embd.weight' && !tied)) lookup += t.bytes;
        else if (/_exps\./.test(t.name)) expert += t.bytes;
        else shared += t.bytes;
    }
    const active = shared + (experts ? (expert * used) / experts : expert);

    return {
        architecture: arch,
        layers: meta[arch + '.block_count'],
        experts: experts || null,
        expertsUsed: used || null,
        sharedBytes: shared,
        expertBytes: expert,
        lookupOnlyBytes: lookup,
        activeBytesPerToken: Math.round(active),
        slidingWindow: meta[arch + '.attention.sliding_window'] ?? null,
        kvHeads: meta[arch + '.attention.head_count_kv'] ?? null,
        headDim: meta[arch + '.attention.key_length'] ?? null,
        ssm: Object.keys(meta).some((k) => k.startsWith(arch + '.ssm.')),
    };
}

const run = async () => {
    const { weights } = JSON.parse(readFileSync(MANIFEST, 'utf8'));
    const out = [];

    for (const w of weights) {
        const part = w.parts[0];
        const url = 'https://huggingface.co/' + w.repo + '/resolve/' + w.sha + '/' + part.path;
        const fileBytes = w.parts.reduce((t, p) => t + p.bytes, 0);
        const summary = { id: w.id, fileBytes, ...perToken(await header(url, part.bytes)) };
        out.push(summary);
        console.log(
            w.id.padEnd(22) +
                (summary.activeBytesPerToken / 1e9).toFixed(2).padStart(6) +
                ' GB/token of ' +
                (fileBytes / 1e9).toFixed(2) +
                ' GB  ' +
                summary.architecture +
                (summary.experts ? '  experts ' + summary.expertsUsed + '/' + summary.experts : '') +
                (summary.ssm ? '  recurrent' : '') +
                (summary.slidingWindow ? '  swa ' + summary.slidingWindow : ''),
        );
    }

    const file = join(here, 'results', 'candidates-estimate.json');
    writeFileSync(file, JSON.stringify({ measuredAt: new Date().toISOString(), models: out }, null, 2) + '\n');
    console.log('\nwritten to ' + file);
};

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
