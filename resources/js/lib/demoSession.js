// The lifecycle of the posthog-js instance of the test bench, apart from Vue so tests/js/demoSession.test.mjs can run it
// with a fake SDK. One session owns at most one running SDK; starting loads it (a lazy import, so it takes a while),
// restarting replaces it, and anything that finishes after it was superseded or after the page was left is stopped
// again instead of being kept.
//
// Known limit of posthog-js: a stopped instance cannot take its two window listeners (`pagehide`, `pageshow`) back, so
// every start leaves one dead instance and those listeners behind until the tab is closed. demoSdk.stop() switches the
// page-leave capture off first, so the dead listeners only flush empty queues.

/**
 * @param {object} hooks
 * @param {(options: object) => Promise<{ stop: () => Promise<void> }>} hooks.load  creates and starts an SDK
 * @param {(status: 'loading' | 'ready' | 'failed') => void} hooks.onStatus
 * @param {(sdk: object) => void} hooks.onReady
 * @param {(error: unknown) => void} hooks.onFailure  the SDK could not start
 * @param {(error: unknown) => void} [hooks.onStopFailure]  an SDK would not stop (the session carries on without it)
 */
export function createDemoSession({ load, onStatus, onReady, onFailure, onStopFailure = () => {} }) {
    let running = null;
    let generation = 0;
    let disposed = false;

    async function stopQuietly(sdk) {
        try {
            await sdk.stop();
        } catch (error) {
            onStopFailure(error);
        }
    }

    async function stopRunning() {
        const sdk = running;
        running = null;

        if (sdk) {
            await stopQuietly(sdk);
        }
    }

    /** `getOptions` is read when the load begins, so a restart that waited for an earlier one still uses the newest options. */
    async function start(getOptions) {
        const mine = ++generation;
        onStatus('loading');

        try {
            const sdk = await load(getOptions());

            // Superseded by a restart, or the page was left, while the SDK was loading.
            if (disposed || mine !== generation) {
                await stopQuietly(sdk);

                return;
            }

            running = sdk;
            onStatus('ready');
            onReady(sdk);
        } catch (error) {
            if (!disposed && mine === generation) {
                onStatus('failed');
                onFailure(error);
            }
        }
    }

    /** Never rejects: a failing stop is reported through onStopFailure and the new SDK starts anyway. */
    async function restart(getOptions) {
        generation++;
        onStatus('loading');
        await stopRunning();
        await start(getOptions);
    }

    async function dispose() {
        disposed = true;
        generation++;
        await stopRunning();
    }

    return { start, restart, dispose, current: () => running };
}
