import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            // Mirrors the `@` alias resources/js uses throughout (tsconfig
            // paths / laravel-vite-plugin). Until W29 every spec here imported
            // by relative path, so nothing needed it; a component spec that
            // mounts a real card does, because the card's own imports are
            // written with it.
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.spec.ts'],
        // Read only by `vitest run --coverage` (checks.sh coverage, W119). A
        // file no spec imports still counts, as 0%.
        coverage: {
            provider: 'v8',
            include: ['resources/js/**'],
            reporter: ['json-summary', 'text-summary'],
            reportsDirectory: 'coverage/js',
        },
    },
});
