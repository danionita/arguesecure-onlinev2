/**
 * The interface says "scales" (W126, D92; plan criteria 22 and 23).
 *
 * It reads the files rather than mounting them: the words matter wherever
 * they can reach a person — a template, the help pages the assistant answers
 * from — and a mounted component only shows the branch that rendered. Code
 * comments and identifiers are out of scope on purpose (`ScaleCalibrator` is
 * still called that), and so is the calculation engine's own warning text,
 * which is normative and pinned by the golden vectors.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = join(__dirname, '../../..');
const read = (path: string) => readFileSync(join(root, path), 'utf8');
// Identifiers are not interface text: `cover.scaleCalibration` and
// `isUncalibrated()` are names in the code and the report payload.
const squash = (text: string) =>
    text
        .replace(/\b(?:scaleCalibration|isUncalibrated)\b/g, '')
        .replace(/\s+/g, ' ');

function templateOf(path: string): string {
    const source = read(path);
    const start = source.indexOf('<template>');
    return start === -1
        ? ''
        : source.slice(start).replace(/<!--[\s\S]*?-->/g, '');
}

const FILES = [
    'resources/js/Pages/Admin/Defaults.vue',
    'resources/js/Pages/Assessment/Settings.vue',
    'resources/js/Pages/Assessment/Index.vue',
    'resources/js/Components/NodeModal/tabs/ScalesTab.vue',
    'resources/js/Components/Report/CoverSection.vue',
    'resources/js/Components/NodeModal/tabs/RiskValuesPanel.vue',
    'resources/js/Components/NodeModal/tabs/AttackValuesPanel.vue',
    ...readdirSync(join(root, 'resources/js/Components/Scales'))
        .filter((f) => f.endsWith('.vue'))
        .map((f) => `resources/js/Components/Scales/${f}`),
];

const HELP = readdirSync(join(root, 'resources/markdown/help'))
    .filter((f) => f.endsWith('.md'))
    .map((f) => `resources/markdown/help/${f}`);

const BANNED = [
    /calibrat/i,
    /instance default/i,
    /default bands/i,
    /reset to default/i,
    /\b(impact|likelihood|strength) scale\b/i,
];

describe('words that no longer appear', () => {
    for (const file of [
        ...FILES.map((f) => [f, templateOf(f)]),
        ...HELP.map((f) => [f, read(f)]),
    ]) {
        const [name, text] = file;

        it(`${name} uses none of them`, () => {
            for (const pattern of BANNED) {
                expect(squash(text), `${pattern} in ${name}`).not.toMatch(
                    pattern,
                );
            }
        });
    }

    it('actually read some text, so the loop above is not passing on nothing', () => {
        expect(
            templateOf('resources/js/Pages/Assessment/Settings.vue'),
        ).toContain('Scales');
        expect(read('resources/markdown/help/administration.md')).toContain(
            'Standard',
        );
    });
});

describe('the Scales help text', () => {
    it('is the shorter, clearer paragraph, with no mention of calibration', () => {
        const settings = squash(
            templateOf('resources/js/Pages/Assessment/Settings.vue'),
        );

        expect(settings).toContain(
            'The ranges that your impact, likelihood and strength choices stand for. Pick a scale from the list to load it, or change any band yourself and the list switches to Custom. Nothing is stored until you press Save.',
        );
    });

    it('is introduced on the admin page in the same sense of "scale"', () => {
        const admin = squash(
            templateOf('resources/js/Pages/Admin/Defaults.vue'),
        );

        expect(admin).toContain(
            'A scale is a named set of impact, likelihood and strength bands. Each new assessment starts from one scale you choose when you create it. Changing a scale here never changes assessments that already exist.',
        );
    });
});
