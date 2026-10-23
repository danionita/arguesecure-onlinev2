// The npm half of CI's security job. Every npm package here is a
// devDependency, including the ones Vite bundles for browsers, so
// `npm audit --omit=dev` audits nothing and always reports 0 (W122, D86).
// This audits the whole lockfile and fails on any high or critical advisory
// that tools/dev/audit-allowlist.json does not name, and on any allowlist
// entry whose advisory is gone, so the list only shrinks.
//
//   npm audit --package-lock-only --json > audit.json; node tools/dev/audit-gate.mjs audit.json
//
// Output that is not an audit (an `error` object, empty or broken JSON, as
// when the registry is down) fails: a gate that audited nothing is not clean.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const BLOCKING = new Set(['high', 'critical']);

const RANK = { info: 0, low: 1, moderate: 2, high: 3, critical: 4 };

/** Advisory objects (not the "depends on X" strings) from `npm audit --json`. */
export function advisories(audit) {
    if (!audit || typeof audit.vulnerabilities !== 'object' || audit.vulnerabilities === null) {
        throw new Error('not an npm audit report (no "vulnerabilities" object)');
    }
    const found = new Map();
    for (const vuln of Object.values(audit.vulnerabilities)) {
        for (const via of vuln.via ?? []) {
            if (typeof via === 'string') continue;
            // Never an empty key: an advisory without a url must not merge with another.
            const id = String(via.url ?? '').split('/').pop() || `${via.name}:${via.source ?? via.title}`;
            const prior = found.get(id);
            // The same advisory under several packages keeps its highest severity.
            if (prior && (RANK[prior.severity] ?? 0) >= (RANK[via.severity] ?? 0)) continue;
            found.set(id, { id, package: via.name, severity: via.severity, title: via.title });
        }
    }
    return [...found.values()];
}

/** @returns {{ unlisted: object[], stale: object[] }} */
export function evaluate(audit, allowlist) {
    const found = advisories(audit);
    const listed = new Set(allowlist.map((entry) => entry.id));
    const seen = new Set(found.map((advisory) => advisory.id));
    return {
        unlisted: found.filter((advisory) => BLOCKING.has(advisory.severity) && !listed.has(advisory.id)),
        stale: allowlist.filter((entry) => !seen.has(entry.id)),
    };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    let audit;
    try {
        audit = JSON.parse(readFileSync(process.argv[2] ?? 0, 'utf8'));
        advisories(audit);
    } catch (error) {
        console.error(`npm audit gate: ${error.message}`);
        process.exit(1);
    }
    const allowlist = JSON.parse(
        readFileSync(new URL('./audit-allowlist.json', import.meta.url), 'utf8'),
    ).accepted;
    const { unlisted, stale } = evaluate(audit, allowlist);
    for (const a of unlisted) console.error(`not allowlisted: ${a.id} ${a.package} (${a.severity}) ${a.title}`);
    for (const e of stale) console.error(`stale allowlist entry (advisory gone, remove it): ${e.id} ${e.package}`);
    if (unlisted.length || stale.length) process.exit(1);
    console.log(`npm audit gate: no unlisted high or critical advisory (${allowlist.length} accepted)`);
}
