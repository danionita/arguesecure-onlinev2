import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end configuration (W25, PLAN.md's Verification section).
 *
 * These specs drive a real browser against a real running stack — they do
 * not boot one. Start it first, isolated from any local instance:
 *
 *   docker compose -p arguesecure-e2e --env-file .env.e2e \
 *     -f docker-compose.yml -f docker-compose.e2e.yml up -d --build
 *
 * Scenario 1 is first-run setup, which requires an empty users table, so
 * the run is order-dependent by design: it creates the admin every later
 * scenario logs in as. Hence `fullyParallel: false` and a single worker —
 * these are release-acceptance scenarios in sequence, not isolated unit
 * tests, and the twelve in PLAN.md read as one narrative.
 *
 * E2E_BASE_URL exists so the same specs can point at a staging deployment
 * without editing them.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8100',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
