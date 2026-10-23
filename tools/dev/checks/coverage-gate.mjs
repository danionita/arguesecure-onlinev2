// The coverage floor (W119, D88): reads the two reports `checks.sh coverage`
// writes, prints one line per side, and exits non-zero when a side is below
// the floor recorded in coverage-floors.json. A floor is the measured value
// minus one point, raised by hand when a package raises coverage — it
// ratchets, it is not a target.
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

/** Statement coverage of a Clover report, in percent. */
export function phpStatements(clover) {
    const tag =
        clover.match(/<project[^>]*>\s*<metrics([^>]*)>/) ??
        clover.match(/<metrics([^>]*)\/>\s*<\/project>/);
    if (!tag) throw new Error('no project <metrics> in the Clover report');
    const attr = (name) =>
        Number(tag[1].match(new RegExp(`\\b${name}="(\\d+)"`))?.[1]);
    const total = attr('statements');
    if (!total) throw new Error('the Clover report counts no statements');
    return (attr('coveredstatements') / total) * 100;
}

/** Each side of the report against its floor; one entry per measure. */
export function evaluate(floors, measured) {
    const rows = [];
    for (const [side, measures] of Object.entries(floors)) {
        for (const [measure, floor] of Object.entries(measures)) {
            const value = measured[side]?.[measure];
            rows.push({
                side,
                measure,
                floor,
                value,
                ok: value >= floor,
            });
        }
    }
    return rows;
}

function main(root) {
    const floors = JSON.parse(
        readFileSync(join(here, 'coverage-floors.json'), 'utf8'),
    );
    const clover = join(root, 'coverage/php/clover.xml');
    const summary = join(root, 'coverage/js/coverage-summary.json');
    for (const file of [clover, summary]) {
        if (!existsSync(file))
            throw new Error(
                `${file} is missing; run tools/dev/checks/checks.sh coverage`,
            );
    }
    const js = JSON.parse(readFileSync(summary, 'utf8')).total;
    const measured = {
        php: { statements: phpStatements(readFileSync(clover, 'utf8')) },
        js: {
            lines: js.lines.pct,
            branches: js.branches.pct,
            functions: js.functions.pct,
            statements: js.statements.pct,
        },
    };
    const rows = evaluate(floors, measured);
    for (const r of rows) {
        const value =
            typeof r.value === 'number' ? r.value.toFixed(2) : 'not measured';
        console.log(
            `${r.ok ? 'ok  ' : 'FAIL'} ${r.side} ${r.measure}: ${value}% (floor ${r.floor}%)`,
        );
    }
    return rows.every((r) => r.ok) ? 0 : 1;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
    try {
        process.exit(main(process.argv[2] ?? join(here, '../../..')));
    } catch (e) {
        console.error(e.message);
        process.exit(2);
    }
}
