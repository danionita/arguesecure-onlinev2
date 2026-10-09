import { defineConfig, type Plugin } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

// W24 (performance): a hard bundle budget.
//
// Vite's own `build.chunkSizeWarningLimit` only prints a yellow warning — it
// never fails the build, so a bundle can grow without bound and nobody
// notices until the report page is slow to first paint. `rollup-plugin`'s
// `generateBundle` hook runs after every chunk is finalized (including
// content-hashed filenames), so this throws there instead, which fails
// `vite build` with a non-zero exit code and therefore fails CI.
//
// Budgets were set from the real build at the time this was written
// (`npm run build`, see git history for the exact figures): the largest
// chunk was the shared `app-*.js` entry at ~295 KB raw / ~104 KB gzip, and
// the full `public/build` output (all JS + CSS, hashed filenames included)
// was ~580 KB raw. Both budgets below leave headroom for organic growth
// without being loose enough to hide a real regression (e.g. an accidental
// non-lazy import of a rarely-used page into the shared entry chunk).
const CHUNK_BUDGET_BYTES = 400 * 1024; // per emitted JS/CSS asset, raw (pre-gzip)
const TOTAL_BUDGET_BYTES = 900 * 1024; // whole public/build output, raw (pre-gzip)

function bundleBudget(): Plugin {
    return {
        name: 'arguesecure:bundle-budget',
        apply: 'build',
        generateBundle(_options, bundle) {
            let total = 0;
            const overBudget: string[] = [];

            for (const [fileName, output] of Object.entries(bundle)) {
                const size =
                    output.type === 'chunk'
                        ? Buffer.byteLength(output.code, 'utf8')
                        : Buffer.byteLength(
                              typeof output.source === 'string' ? output.source : Buffer.from(output.source),
                          );

                total += size;

                if (size > CHUNK_BUDGET_BYTES) {
                    overBudget.push(`  ${fileName}: ${(size / 1024).toFixed(1)} KB > ${(CHUNK_BUDGET_BYTES / 1024).toFixed(0)} KB budget`);
                }
            }

            const errors: string[] = [...overBudget];
            if (total > TOTAL_BUDGET_BYTES) {
                errors.push(
                    `  TOTAL: ${(total / 1024).toFixed(1)} KB > ${(TOTAL_BUDGET_BYTES / 1024).toFixed(0)} KB budget`,
                );
            }

            if (errors.length > 0) {
                this.error(
                    `Bundle budget exceeded — build failed.\n${errors.join('\n')}\n` +
                        'Split the offending chunk (dynamic import / route-level code-splitting) or raise the ' +
                        'budget deliberately in vite.config.ts with a comment explaining why.',
                );
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.ts',
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        bundleBudget(),
    ],
    build: {
        // Keep Vite's own warning close to the hard budget so a developer
        // sees the yellow warning locally before CI turns it red.
        chunkSizeWarningLimit: 400,
    },
});
