import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
// @ts-expect-error plain ES module without types
import { advisories, evaluate } from '../../../tools/dev/audit-gate.mjs';

const advisory = (id: string, name: string, severity: string) => ({
    name,
    severity,
    title: `${name} problem`,
    url: `https://github.com/advisories/${id}`,
});

const audit = {
    vulnerabilities: {
        braces: { via: [advisory('GHSA-aaaa', 'braces', 'high')] },
        micromatch: { via: ['braces'] },
        axios: {
            via: [
                advisory('GHSA-bbbb', 'axios', 'high'),
                advisory('GHSA-cccc', 'axios', 'moderate'),
            ],
        },
    },
};

describe('npm audit gate', () => {
    it('reads advisories and skips "depends on" strings', () => {
        expect(advisories(audit).map((a: { id: string }) => a.id)).toEqual([
            'GHSA-aaaa',
            'GHSA-bbbb',
            'GHSA-cccc',
        ]);
    });

    it('fails on a high advisory that is not allowlisted, and only on high or critical', () => {
        const { unlisted } = evaluate(audit, [{ id: 'GHSA-aaaa' }]);
        expect(unlisted.map((a: { id: string }) => a.id)).toEqual([
            'GHSA-bbbb',
        ]);
    });

    it('passes when every high advisory is allowlisted (a moderate one is not gated)', () => {
        const { unlisted, stale } = evaluate(audit, [
            { id: 'GHSA-aaaa' },
            { id: 'GHSA-bbbb' },
        ]);
        expect(unlisted).toEqual([]);
        expect(stale).toEqual([]);
    });

    it('flags an allowlist entry whose advisory is gone', () => {
        const { stale } = evaluate(audit, [
            { id: 'GHSA-aaaa' },
            { id: 'GHSA-bbbb' },
            { id: 'GHSA-gone' },
        ]);
        expect(stale.map((e: { id: string }) => e.id)).toEqual(['GHSA-gone']);
    });

    it('treats critical like high', () => {
        const critical = {
            vulnerabilities: {
                x: { via: [advisory('GHSA-dddd', 'x', 'critical')] },
            },
        };
        expect(evaluate(critical, []).unlisted).toHaveLength(1);
    });
});

describe('npm audit gate (CLI)', () => {
    const run = (content: string) => {
        const file = join(mkdtempSync(join(tmpdir(), 'audit-')), 'audit.json');
        writeFileSync(file, content);
        return spawnSync('node', ['tools/dev/audit-gate.mjs', file], {
            encoding: 'utf8',
        });
    };

    it('exits 1 on output that is not an audit report', () => {
        expect(run('{"error":{"code":"ENOAUDIT"}}').status).toBe(1);
        expect(run('').status).toBe(1);
    });

    it('exits 1 on an unlisted high advisory and 0 on a clean report', () => {
        expect(run(JSON.stringify(audit)).status).toBe(1);
        const clean = JSON.stringify({ vulnerabilities: {} });
        // The committed allowlist is empty, so nothing is stale against an empty report.
        expect(run(clean).status).toBe(0);
    });

    it('keeps the highest severity and does not merge advisories without a url', () => {
        const merged = {
            vulnerabilities: {
                a: { via: [advisory('GHSA-eeee', 'a', 'high')] },
                b: { via: [advisory('GHSA-eeee', 'b', 'moderate')] },
                c: {
                    via: [
                        {
                            name: 'c',
                            severity: 'high',
                            title: 'one',
                            source: 1,
                        },
                    ],
                },
                d: {
                    via: [
                        {
                            name: 'd',
                            severity: 'moderate',
                            title: 'two',
                            source: 2,
                        },
                    ],
                },
            },
        };
        const found = advisories(merged);
        expect(found).toHaveLength(3);
        expect(
            evaluate(merged, []).unlisted.map((x: { id: string }) => x.id),
        ).toEqual(['GHSA-eeee', 'c:1']);
    });

    it('throws on a report without vulnerabilities', () => {
        expect(() => advisories({ error: {} })).toThrow();
    });
});
