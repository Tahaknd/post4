// The posthog-js side of the test bench (Projects/Demo.vue). The SDK is imported lazily, so it is a chunk of its own
// that only this page loads (the main bundles do not grow), and it is the real package from node_modules: no CDN and
// no request to any host but this server.
//
// Every start creates a NEW named instance and every stop shuts it down. posthog-js has one process-wide singleton and
// a second `init()` of an instance that is already loaded is a no-op, so reusing one instance across page visits or
// option changes would silently keep the old options. Instances share the browser's persisted state (distinct id,
// opt-out), because that is keyed by the token and not by the instance name.

let started = 0;

/** The one element of the bench that autocapture may record (Projects/Demo.vue). */
export const AUTOCAPTURE_TARGET_SELECTOR = '[data-attr="demo-autocapture-target"]';

/**
 * @param {object} options
 * @param {string} options.token       the project key
 * @param {string} options.apiHost     origin of this server
 * @param {boolean} options.autocapture   clicks on the bench's autocapture target become `$autocapture` events (nothing else does)
 * @param {boolean} options.compression   gzip the request bodies (the default of the SDK); off = plain JSON, and a base64
 *                                        `data=` form for the beacon that is sent when the page is left
 * @param {boolean} options.remoteConfig  fetch `/array/<token>/config.js` (and, if it says so, `/flags`); off = the SDK
 *                                        never asks the server for configuration (`advanced_disable_flags`)
 * @param {(captured: object) => void} options.onCaptured  called for every event the SDK is about to send
 */
export async function startDemoSdk({ token, apiHost, autocapture, compression, remoteConfig, onCaptured }) {
    const { default: posthog } = await import('posthog-js');

    const instance = posthog.init(
        token,
        {
            api_host: apiHost,
            // Only the demo's own target is autocaptured: the page is rendered inside the app (project picker, navigation,
            // links to other projects), and a click on that chrome must never become an event of this project. The
            // SDK captures an element only when it or an ancestor matches `css_selector_allowlist`.
            autocapture: autocapture ? { css_selector_allowlist: [AUTOCAPTURE_TARGET_SELECTOR] } : false,
            rageclick: false,
            // The bench sends pageviews on request. The pageleave stays on: it is the event that travels by
            // sendBeacon when the tab is closed or navigated away, which is what the bench exists to try.
            capture_pageview: false,
            capture_pageleave: true,
            // Nothing the server does not serve: the recorder is `/s/` + a script from `/static/`, the other
            // extensions are scripts from `/static/` too.
            disable_session_recording: true,
            disable_surveys: true,
            disable_conversations: true,
            disable_product_tours: true,
            disable_web_experiments: true,
            // No cookies on this origin; the SDK keeps its state in localStorage.
            persistence: 'localStorage',
            person_profiles: 'identified_only',
            advanced_disable_flags: !remoteConfig,
            disable_compression: !compression,
            // Flush every half second instead of every three, so the log follows the clicks.
            request_queue_config: { flush_interval_ms: 500 },
        },
        `post4_demo_${++started}`,
    );

    const unsubscribe = instance.on('eventCaptured', onCaptured);

    return {
        version: instance.version ?? '',
        distinctId: () => instance.get_distinct_id(),
        hasOptedOut: () => instance.has_opted_out_capturing(),
        capturePageview: () => instance.capture('$pageview'),
        capture: (name, properties) => instance.capture(name, properties),
        identify: (distinctId) => instance.identify(distinctId),
        reset: () => instance.reset(),
        optOut: () => instance.opt_out_capturing(),
        optIn: () => instance.opt_in_capturing(),
        /** Flushes what is queued and releases the instance. The window listeners posthog-js installs at init cannot
         *  be removed, so the pageleave is switched off first: a dead instance must not report a page leave. */
        async stop() {
            unsubscribe();
            instance.set_config({ capture_pageleave: false });
            await instance.shutdown();
        },
    };
}
