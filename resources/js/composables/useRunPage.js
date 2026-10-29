import { computed, nextTick, ref, shallowRef, watch } from 'vue';

import { useInsightRun } from '@/composables/useInsightRun';

const UNREADABLE = { kind: 'unexpected', message: 'The server sent an answer this page cannot read. Reload the page and try again.', retryable: true, retry_after_seconds: null };

/**
 * The run side of an insight page that runs on a button (Funnel, Retention): one Run, one Cancel, the result that is on
 * screen and the definition it answers, which view the results area shows, whether the form changed since, and the one
 * sentence of the live region. What belongs to the insight stays with the page: the definition, its validation, what a
 * refused Run reveals, the words.
 *
 * @param {object} options
 * @param {string} options.postUrl the POST URL of the insight type, built by the server
 * @param {string} options.runsUrl the base URL of the runs, built by the server
 * @param {import('vue').ComputedRef<object>} options.body the API body of the form as it is now
 * @param {import('vue').ComputedRef<boolean>} options.valid whether the form passes the instant validation
 * @param {(result: object) => boolean} options.isResult whether a result has the shape the page draws
 * @param {(result: object) => boolean} options.isEmpty whether a result has nothing to draw
 * @param {() => void} options.refuse called when Run is pressed on an invalid form: reveal the messages and focus the first
 * @param {(error: object|null) => string|null} options.failureHelp what to try after a failure, shown under the server's sentence
 * @param {{ running: string, refused: string, empty: string, describe: (result: object) => string }} options.messages
 */
export function useRunPage({ postUrl, runsUrl, body, valid, isResult, isEmpty, refuse, failureHelp, messages }) {
    const run = useInsightRun(postUrl, runsUrl);
    const state = computed(() => run.state.value);
    const busy = computed(() => state.value.phase === 'loading');
    const bodyKey = computed(() => JSON.stringify(body.value));

    // A Run that was refused because of the form (the hint and the live region say so until the form is valid).
    const attempted = ref(false);
    // The <RunControls> of the page: cancelling puts focus back on its Run button.
    const runButton = ref(null);

    // The result that is on screen and the definition it answers (labels must match the data, not the live form).
    const shown = shallowRef(null);
    let askedFor = null;

    function start() {
        if (busy.value) {
            return;
        }
        if (!valid.value) {
            attempted.value = true;
            refuse();

            return;
        }

        attempted.value = false;
        askedFor = bodyKey.value;
        shown.value = null;
        run.run(body.value);
    }

    function cancel() {
        run.cancel();
        nextTick(() => runButton.value?.focus());
    }

    watch(
        () => state.value,
        (next) => {
            if (next.phase === 'done' && askedFor !== null && isResult(next.result)) {
                shown.value = { result: next.result, key: askedFor };
            } else if (next.phase !== 'loading') {
                shown.value = null;
            }
        },
    );

    const view = computed(() => {
        switch (state.value.phase) {
            case 'idle':
                return 'idle';
            case 'loading':
                return 'loading';
            case 'done':
                return shown.value === null ? 'failed' : isEmpty(shown.value.result) ? 'empty' : 'result';
            case 'invalid':
                return 'blocked';
            default:
                // failed, expired, cancelled
                return state.value.phase;
        }
    });

    const error = computed(() => (state.value.phase === 'done' ? UNREADABLE : state.value.error));
    const help = computed(() => (view.value === 'failed' ? failureHelp(error.value) : null));
    // The form changed since the result on screen was asked for.
    const stale = computed(() => view.value === 'result' && shown.value.key !== bodyKey.value);

    const stageText = computed(() => (state.value.stage === 'queued' ? 'Waiting for a free slot…' : (state.value.notice ?? messages.running)));

    // One short sentence for the live region, once per finished action. Failures and form errors are announced by their own
    // alerts and are NOT repeated here.
    const status = computed(() => {
        if (busy.value) {
            return stageText.value;
        }
        if (attempted.value && !valid.value) {
            return messages.refused;
        }

        switch (view.value) {
            case 'result':
                return messages.describe(shown.value.result);
            case 'empty':
                return messages.empty;
            case 'cancelled':
                return 'The run was cancelled.';
            case 'expired':
                return 'This result is no longer available. Run it again.';
            default:
                return '';
        }
    });

    return { state, busy, attempted, runButton, shown, view, error, help, stale, stageText, status, start, cancel };
}
