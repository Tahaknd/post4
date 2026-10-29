import { defineConfig, devices } from '@playwright/test';

// The browser smoke test of the UI (tests/e2e). It is NOT part of the Pest suite and NOT part of the Docker image:
// it needs a running stack and a browser, so it runs in a throwaway Playwright container on the compose network
// (scripts/e2e.sh); nothing is installed on the host and the app image never gets a browser.
//
//   E2E_BASE_URL   where the app answers: scripts/e2e.sh uses the app container's plain-http address on the compose network
//   E2E_EMAIL, E2E_PASSWORD   a THROWAWAY administrator (never hardcoded here): the test creates and deletes a project
//   E2E_BROWSER_CHANNEL       optional: run an installed browser instead of the bundled one (`chrome`), for a host without the
//                             Playwright image
//   E2E_BROWSER_ARGS          optional, space separated Chromium flags; `--host-resolver-rules="MAP name 127.0.0.1"` is not
//                             splittable on spaces, so use E2E_HOST_MAP=name instead (see below)
//   E2E_HOST_MAP              optional: a host NAME that the browser resolves to 127.0.0.1. Opening the app as http://name:8000
//                             makes it a plain-http, non-localhost origin (an insecure context) without a container network
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
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                ...(process.env.E2E_BROWSER_CHANNEL ? { channel: process.env.E2E_BROWSER_CHANNEL } : {}),
                launchOptions: {
                    args: [
                        ...(process.env.E2E_BROWSER_ARGS ? process.env.E2E_BROWSER_ARGS.split(' ').filter(Boolean) : []),
                        ...(process.env.E2E_HOST_MAP ? [`--host-resolver-rules=MAP ${process.env.E2E_HOST_MAP} 127.0.0.1`] : []),
                    ],
                },
            },
        },
    ],
});
