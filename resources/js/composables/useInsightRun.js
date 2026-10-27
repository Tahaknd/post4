import { onBeforeUnmount, shallowRef } from 'vue';

import { IDLE_STATE, InsightRunClient } from '@/lib/insightRunClient';

/**
 * Runs a trends definition against the asynchronous insight API and exposes the state as a ref. All the timing rules
 * (debounce, request sequence numbers, cancelling superseded runs, polling with back-off, Retry-After, the 60 s
 * timeout, the one-POST-per-second guard, XSRF) live in the framework-free {@link InsightRunClient}; this wrapper only
 * ties it to a component: the run is stopped and cancelled when the component unmounts.
 *
 * `state.value` is `{ phase, notice, result, error, fieldErrors }` with `phase` one of `idle`, `loading`, `done`,
 * `failed`, `expired`, `invalid` (the API answered 422: `fieldErrors` holds its `errors`).
 *
 * @param {string} trendsUrl the POST URL, built by the server
 */
export function useInsightRun(trendsUrl) {
    const state = shallowRef(IDLE_STATE);
    const client = new InsightRunClient({ trendsUrl, onChange: (next) => (state.value = next) });

    onBeforeUnmount(() => client.dispose());

    return {
        state,
        /** The definition changed: run it after the debounce, superseding whatever runs now. */
        schedule: (body) => client.schedule(body),
        /** Run the current definition again at once. */
        retry: () => client.retry(),
        /** Nothing to run (no event chosen): cancel everything and go idle. */
        reset: () => client.reset(),
    };
}
