/**
 * Builds the three prompts the benchmark measures, from real repository
 * content rather than invented text.
 *
 * Job A's corpus is `resources/markdown/help/**` in full — see the spike
 * report for why `docs/` is not in it.
 *
 * Job B's parent is a real risk from the seeded demo assessment.
 *
 * Job C's assessment is the four shipped archetypes merged into one, because
 * no single fixture in the repository is as large as the 50-node assessment
 * the sketch asks for and the point of the measurement is the context size a
 * large assessment produces.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const repo = join(here, '..', '..', '..');

const read = (p) => readFileSync(p, 'utf8');

/** Prompt files carry an HTML comment explaining themselves; it is not sent. */
const prompt = (name) =>
    read(join(here, 'prompts', name)).replace(/^<!--[\s\S]*?-->\n/, '');

const fill = (template, values) =>
    Object.entries(values).reduce(
        (out, [key, value]) => out.split('{{' + key + '}}').join(String(value)),
        template,
    );

const ARCHETYPES = [
    'archetype-public-web-app',
    'archetype-cloud-saas',
    'archetype-internal-network',
    'archetype-iot-embedded',
];

const template = (key) =>
    JSON.parse(
        read(join(repo, 'database', 'seeders', 'templates', key + '.json')),
    );

/** The whole user-facing help corpus, each file prefixed with its filename. */
export function helpCorpus() {
    const dir = join(repo, 'resources', 'markdown', 'help');

    return readdirSync(dir)
        .filter((f) => f.endsWith('.md'))
        .map((f) => '--- ' + f + ' ---\n' + read(join(dir, f)))
        .join('\n\n');
}

export function jobA(question) {
    return fill(prompt('job-a-help.md'), {
        CORPUS: helpCorpus(),
        QUESTION: question,
    });
}

/**
 * @param {{risk?: string, count?: number, inject?: string}} options
 *   `inject` appends attacker-controlled text to the risk description, which
 *   is how the red-team cases are built: the injection arrives exactly where a
 *   user holding `editor` on a shared assessment would type it.
 */
export function jobB({ risk: wanted, count = 5, inject = '' } = {}) {
    const demo = template('demo-webshop');
    const risk = wanted
        ? demo.risks.find((r) => r.ref === wanted)
        : demo.risks[0];
    const byRef = new Map(demo.attacks.map((a) => [a.ref, a]));
    const existing = (risk.attack_refs ?? [])
        .map((ref) => byRef.get(ref))
        .filter(Boolean)
        .map((a) => '- ' + a.title + ': ' + a.description)
        .join('\n');

    return fill(prompt('job-b-suggest-attacks.md'), {
        COUNT: count,
        TOS_TITLE: demo.title,
        TOS_DESCRIPTION: demo.description,
        RISK_TITLE: risk.title,
        RISK_DESCRIPTION: risk.description + (inject ? '\n\n' + inject : ''),
        EXISTING_ATTACKS: existing || '(none yet)',
    });
}

/** Every node of an assessment, rendered the way job C would receive it. */
function renderAssessment(source) {
    const lines = [
        'Target of assessment: ' + source.title,
        source.description,
        '',
    ];
    const attacks = new Map((source.attacks ?? []).map((a) => [a.ref, a]));
    const defences = new Map((source.defences ?? []).map((d) => [d.ref, d]));

    for (const risk of source.risks ?? []) {
        const impact =
            risk.impact?.mode === 'quantitative'
                ? source.currency + ' ' + risk.impact.amount + ' per occurrence'
                : 'impact level ' + (risk.impact?.level_code ?? 'not set');
        lines.push('RISK: ' + risk.title + ' (' + impact + ')');
        lines.push(risk.description);

        for (const ref of risk.attack_refs ?? []) {
            const attack = attacks.get(ref);
            if (!attack) continue;

            const rate =
                attack.likelihood?.mode === 'quantitative'
                    ? attack.likelihood.rate + '/year'
                    : 'likelihood level ' +
                      (attack.likelihood?.level_code ?? 'not set');
            lines.push('  ATTACK: ' + attack.title + ' (' + rate + ')');
            lines.push('  ' + attack.description);

            const links = attack.defence_links ?? attack.defence_refs ?? [];
            for (const link of links) {
                const dref = typeof link === 'string' ? link : link.ref;
                const defence = defences.get(dref);
                if (!defence) continue;

                const strength =
                    typeof link === 'object' && link.strength_fraction != null
                        ? Math.round(link.strength_fraction * 100) +
                          '% effective'
                        : 'strength not set';
                const kind = defence.is_transfer ? 'TRANSFER' : 'DEFENCE';
                lines.push(
                    '    ' +
                        kind +
                        ': ' +
                        defence.title +
                        ' (' +
                        (defence.status ?? 'status not set') +
                        ', ' +
                        strength +
                        ')',
                );
                lines.push('    ' + defence.description);
            }
        }
        lines.push('');
    }

    return lines.join('\n');
}

/** The four archetypes merged, to reach the sketch's 50-node figure. */
export function jobC({ inject = '' } = {}) {
    const body =
        ARCHETYPES.map((k) => renderAssessment(template(k))).join('\n') +
        (inject ? '\n' + inject + '\n' : '');

    return fill(prompt('job-c-executive-summary.md'), { ASSESSMENT: body });
}

/** Node count of the merged job C assessment, for the report. */
export function jobCNodeCount() {
    return ARCHETYPES.reduce((total, key) => {
        const t = template(key);

        return (
            total +
            1 +
            (t.risks ?? []).length +
            (t.attacks ?? []).length +
            (t.defences ?? []).length
        );
    }, 0);
}
