import { defineConfig, devices } from '@playwright/test';

// The browser smoke test of the UI (tests/e2e). It is NOT part of the Pest suite and NOT part of the Docker image:
// it needs a running stack and a browser, so it runs in a throwaway Playwright container on the compose network
// (scripts/e2e.sh); nothing is installed on the host and the app image never gets a browser.
//
//   E2E_BASE_URL   where the app answers: scripts/e2e.sh uses the app container's plain-http address on the compose network
//   E2E_EMAIL, E2E_PASSWORD   a THROWAWAY administrator (never hardcoded here): the test creates and deletes a project
//   E2E_BROWSER_CHANNEL       optional: run an installed browser instead of the bundled one (`chrome`), for a host without the
//                             Playwright image
//   E2E_ALLOW_REMOTE=1        the tests create and delete projects with a signed-in administrator: they refuse any address that is not
//                             localhost, a private IP, `*.test` or `*.localhost` unless this is set
//   E2E_HOST_MAP              optional: a host NAME that the browser resolves to 127.0.0.1. Opening the app as http://name:8000
//                             makes it a plain-http, non-localhost origin (an insecure context) without a container network
/** Whether `host` is somewhere a test may safely create and delete projects: this machine, a LAN, a reserved test name. */
export function isSafeTarget(host) {
    const name = host.toLowerCase().replace(/^\[|\]$/g, '');

    return (
        name === 'localhost' ||
        name.endsWith('.localhost') ||
        name.endsWith('.test') ||
        name === '::1' ||
        /^(127|10)\.\d+\.\d+\.\d+$/.test(name) ||
        /^192\.168\.\d+\.\d+$/.test(name) ||
        /^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$/.test(name) ||
        /^169\.254\.\d+\.\d+$/.test(name) ||
        /^f[cd][0-9a-f]{2}:/.test(name)
    );
}

const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';

if (process.env.E2E_ALLOW_REMOTE !== '1' && !isSafeTarget(new URL(baseURL).hostname)) {
    throw new Error(`Refusing to run the e2e tests against ${new URL(baseURL).host}: they create and delete projects as an administrator. Use localhost, a private address or a *.test name, or set E2E_ALLOW_REMOTE=1.`);
}

const browserArgs = process.env.E2E_HOST_MAP ? [`--host-resolver-rules=MAP ${process.env.E2E_HOST_MAP} 127.0.0.1`] : [];

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
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                ...(process.env.E2E_BROWSER_CHANNEL ? { channel: process.env.E2E_BROWSER_CHANNEL } : {}),
                ...(browserArgs.length > 0 ? { launchOptions: { args: browserArgs } } : {}),
            },
        },
    ],
});
