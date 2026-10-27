import { defineConfig, devices } from '@playwright/test';

// The browser smoke test of the UI (tests/e2e). It is NOT part of the Pest suite and NOT part of the Docker image:
// it needs a running stack and a browser, so it runs in a throwaway Playwright container on the compose network
// (scripts/e2e.sh); nothing is installed on the host and the app image never gets a browser.
//
//   E2E_BASE_URL   where the app answers (http://localhost:8000: scripts/e2e.sh runs the browser in the network namespace of the app container)
//   E2E_EMAIL, E2E_PASSWORD   a THROWAWAY administrator (never hardcoded here): the test creates and deletes a project
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 120_000,
    expect: { timeout: 15_000 },
    reporter: [['list']],
    outputDir: process.env.E2E_OUTPUT_DIR ?? 'test-results',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
