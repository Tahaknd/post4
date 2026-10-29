import { onBeforeUnmount, shallowRef } from 'vue';

import { IDLE_STATE, InsightRunClient } from '@/lib/insightRunClient';

/**
 * Runs an insight definition against the asynchronous insight API and exposes the state as a ref. All the timing rules
 * (debounce, request sequence numbers, cancelling superseded runs, polling with back-off, Retry-After, time limits, the
 * one-POST-per-1.5-seconds guard, XSRF) live in the framework-free {@link InsightRunClient}; this wrapper only ties it to
 * a component: the run is stopped and cancelled when the component unmounts.
 *
 * `state.value` is `{ phase, stage, notice, result, error, fieldErrors }` with `phase` one of `idle`, `loading` (`stage`
 * is then null until the server answers, then `queued` or `running`), `done`, `failed`, `expired`, `cancelled` (the user
 * stopped it), `held` (incomplete definition: the last result stays), `invalid` (the API answered 422: `fieldErrors`
 * holds its `errors`). A page that runs on every change uses `schedule`, one with a Run button `run` and `cancel`.
 *
 * @param {string} postUrl the POST URL of the insight type, built by the server
 * @param {string} runsUrl the base URL of the runs (ends with `/runs/`), built by the server
 */
export function useInsightRun(postUrl, runsUrl) {
    const state = shallowRef(IDLE_STATE);
    const client = new InsightRunClient({ postUrl, runsUrl, onChange: (next) => (state.value = next) });

    onBeforeUnmount(() => client.dispose());

    return {
        state,
        /** The definition changed: run it after the debounce, superseding whatever runs now. */
        schedule: (body) => client.schedule(body),
        /** Run this definition at once (a Run button), superseding whatever runs now. */
        run: (body) => client.run(body),
        /** Stop what runs and cancel its run on the server. */
        cancel: () => client.cancel(),
        /** Run the current definition again at once. */
        retry: () => client.retry(),
        /** The definition is incomplete: cancel what runs, keep the last result. */
        hold: () => client.hold(),
        /** Nothing to run (no event chosen): cancel everything and go idle. */
        reset: () => client.reset(),
    };
}
