<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, reactive, ref, shallowRef, watch } from 'vue';

import DateRangeControl from '@/Components/Insights/DateRangeControl.vue';
import FunnelChart from '@/Components/Insights/FunnelChart.vue';
import FunnelSteps from '@/Components/Insights/FunnelSteps.vue';
import OptionSelect from '@/Components/Insights/OptionSelect.vue';
import ResultState from '@/Components/Insights/ResultState.vue';
import WindowControl from '@/Components/Insights/WindowControl.vue';
import { useEventNames } from '@/composables/useEventNames';
import { useInsightRun } from '@/composables/useInsightRun';
import { useTouchedFields } from '@/composables/useTouchedFields';
import { useUrlState } from '@/composables/useUrlState';
import { decodeDefinition, defaultDefinition, encodeDefinition, formatDuration, mapServerErrors, stepEvent, toRequestBody, validateDefinition } from '@/lib/funnelDefinition';
import { describeSummary, failureHelp, isEmptyFunnel, isFunnelResult } from '@/lib/funnelFormat';

// The Funnel page. Everything it needs to build a definition (allowed values, limits, defaults) and the API URLs come from
// the server as props; there is no API key, the API is called with the session. The definition lives in one reactive object
// that mirrors the API body, is validated on every change (instant feedback, the same limits as the server) and is mirrored
// into the query string, so a link reproduces the funnel. The query string is NEVER trusted: decodeDefinition() checks every
// field against the props.
//
// Unlike Trends, a funnel runs on a button, not on every change: a funnel follows every person through every step and is the
// heavier query, and the steps are built one at a time. A link that carries a complete funnel runs once when it is opened.
// While it runs there is a Cancel button; the result stays on screen, marked as out of date, when the form changes after it.
//
// An unfinished field is not an error until the user has left it (or tried to run, which counts as having left every field
// there is): its message then appears, and the Run button, which is aria-disabled and never natively disabled, moves focus to
// the first problem instead of doing nothing.
const props = defineProps({
    project: { type: Object, required: true },
    options: { type: Object, required: true },
    defaults: { type: Object, required: true },
    limits: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const fromLink = decodeDefinition(window.location.search, props);
const definition = reactive(fromLink ?? defaultDefinition(props));

// One list of suggestions for all the steps: one cache and one request budget.
const names = useEventNames(props.urls.events, props.limits.max_search_length);
const run = useInsightRun(props.urls.funnel, props.urls.runs);

const validation = computed(() => validateDefinition(definition, props));
const body = computed(() => toRequestBody(definition, props));
const bodyKey = computed(() => JSON.stringify(body.value));

useUrlState(() => encodeDefinition(definition, props));

const { touched, onFocusOut } = useTouchedFields();
// The steps a refused Run has reported on. A step that is still EMPTY is unfinished, not wrong: leaving it says nothing (a
// message appearing under a field the user just left would also shift the button they are about to click), only a refused
// Run names it.
const refused = reactive(new Set());
// A Run that was refused because of the form (the hint and the live region say so until the form is valid).
const attempted = ref(false);

const form = ref(null);
const runButton = ref(null);

// ---------------------------------------------------------------- running
const state = computed(() => run.state.value);
const busy = computed(() => state.value.phase === 'loading');

// The result that is on screen and the definition it answers (labels must match the data, not the live form).
const shown = shallowRef(null);
let askedFor = null;

function start() {
    if (busy.value) {
        return;
    }
    if (!validation.value.ok) {
        attempted.value = true;
        // Every field there is now has been "left": its message may appear. A step added afterwards starts clean.
        for (const step of definition.steps) {
            touched.add(`step:${step.id}`);
            refused.add(`step:${step.id}`);
            step.filters.forEach((row) => touched.add(`filter:${row.id}`));
        }
        ['window', 'date.from', 'date.to'].forEach((field) => touched.add(field));
        nextTick(() => form.value?.querySelector('[aria-invalid="true"]')?.focus());

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
        if (next.phase === 'done' && askedFor !== null && isFunnelResult(next.result)) {
            shown.value = { result: next.result, key: askedFor };
        } else if (next.phase !== 'loading') {
            shown.value = null;
        }
    },
);

onMounted(() => {
    if (fromLink !== null && validation.value.ok) {
        start();
    }
});

// ---------------------------------------------------------------- what the results area shows
const UNREADABLE = { kind: 'unexpected', message: 'The server sent an answer this page cannot read. Reload the page and try again.', retryable: true, retry_after_seconds: null };

const view = computed(() => {
    switch (state.value.phase) {
        case 'idle':
            return 'idle';
        case 'loading':
            return 'loading';
        case 'done':
            return shown.value === null ? 'failed' : isEmptyFunnel(shown.value.result) ? 'empty' : 'result';
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

const IDLE_COPY = { title: 'Build a funnel to see where people drop off', text: 'Choose an event for each step above, then run the funnel. The bars and the table appear here.' };
const emptyCopy = computed(() => {
    const first = shown.value?.result.steps[0].event ?? 'the first step';

    return { title: 'Nobody started this funnel in this range', text: `No one did ${first}${first === 'the first step' ? '' : ' (with the filters of that step)'} in these dates. Try a longer date range or check the first step.` };
});

const stageText = computed(() => (state.value.stage === 'queued' ? 'Waiting for a free slot…' : (state.value.notice ?? 'Running the funnel…')));

// One short sentence for the live region, once per finished action. Failures and form errors are announced by their own
// alerts and are NOT repeated here.
const status = computed(() => {
    if (busy.value) {
        return stageText.value;
    }
    if (attempted.value && !validation.value.ok) {
        return 'Fix the highlighted fields to run the funnel.';
    }

    switch (view.value) {
        case 'result':
            return describeSummary(shown.value.result);
        case 'empty':
            return 'Nobody started this funnel in this range.';
        case 'cancelled':
            return 'The run was cancelled.';
        case 'expired':
            return 'This result is no longer available. Run it again.';
        default:
            return '';
    }
});

// ---------------------------------------------------------------- when a field's message may appear
// The instant client-side messages win but show only for fields the user has been through (or after a refused Run); the
// API's 422 messages fill the rest and always show (the user has asked for exactly that definition).
const serverErrors = computed(() => (state.value.phase === 'invalid' ? mapServerErrors(state.value.fieldErrors, definition) : null));

function hasFieldErrors(mapped) {
    return Object.keys(mapped.steps).length > 0 || mapped.window !== undefined || mapped.date !== undefined || mapped.order !== undefined;
}

const errors = computed(() => {
    const client = validation.value.errors;
    const server = serverErrors.value;
    const seen = (field) => touched.has(field);

    const steps = {};
    for (const step of definition.steps) {
        const mine = client.steps[step.id];
        const theirs = server?.steps[step.id];
        const entry = {};

        const showEvent = stepEvent(step) === '' ? refused.has(`step:${step.id}`) : seen(`step:${step.id}`);
        const event = (mine?.event !== undefined && showEvent ? mine.event : undefined) ?? theirs?.event;
        if (event !== undefined) {
            entry.event = event;
        }

        const filters = { ...(theirs?.filters ?? {}) };
        for (const [id, row] of Object.entries(mine?.filters ?? {})) {
            if (seen(`filter:${id}`)) {
                filters[id] = row;
            }
        }
        if (Object.keys(filters).length > 0) {
            entry.filters = filters;
        }

        // too many filters is a consequence of the last click, not an unfinished field
        if (mine?.general !== undefined) {
            entry.general = mine.general;
        }
        if (Object.keys(entry).length > 0) {
            steps[step.id] = entry;
        }
    }

    return {
        steps,
        window: (client.window !== undefined && seen('window') ? client.window : undefined) ?? server?.window,
        date: (client.date !== undefined && touched.has('date.from') && touched.has('date.to') ? client.date : undefined) ?? server?.date,
        order: server?.order,
        general: client.general ?? server?.general ?? (server !== null && !hasFieldErrors(server) ? state.value.error?.message : undefined),
    };
});

const ORDER_HINTS = {
    ordered: 'Other events may happen between two steps.',
    strict_order: 'No other event may happen between two steps, including the events the SDK records by itself, such as $pageview and $identify: the first one ends that person’s progress. Expect lower numbers; it suits steps that must follow each other directly.',
};

const orderHint = computed(() => ORDER_HINTS[definition.order] ?? ORDER_HINTS.ordered);
const windowHint = computed(() => `Time allowed from a person’s first step to the last one: ${formatDuration(props.limits.min_window_seconds)} to ${formatDuration(props.limits.max_window_seconds)}.`);

function reload() {
    window.location.reload();
}
</script>

<template>
    <Head :title="`Funnel - ${project.name}`" />

    <div class="space-y-8">
        <header class="space-y-1">
            <p class="text-sm"><Link :href="urls.project" class="text-accent hover:text-accent-hover">{{ project.name }}</Link></p>
            <h1 class="text-2xl font-semibold tracking-tight">Funnel</h1>
            <p class="text-sm text-muted">Where people drop off between steps. Dates and times are in {{ project.timezone }}.</p>
        </header>

        <form ref="form" aria-label="Funnel definition" class="space-y-6 rounded-md border border-line bg-surface p-4 sm:p-5" novalidate @submit.prevent="start" @focusout="onFocusOut">
            <section aria-labelledby="steps-heading" class="space-y-3">
                <div class="space-y-1">
                    <h2 id="steps-heading" class="text-sm font-medium">Steps</h2>
                    <p class="text-sm text-muted">
                        People are counted when they do the first step inside the date range. They then have the conversion window to do the other steps in
                        order. Use {{ limits.min_steps }} to {{ limits.max_steps }} steps.
                    </p>
                </div>
                <FunnelSteps v-model="definition.steps" :names="names" :operators="options.operators" :limits="limits" :errors="errors.steps" />
            </section>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <WindowControl v-model="definition.window" :units="options.window_units" :hint="windowHint" :error="errors.window" />
                <OptionSelect v-model="definition.order" label="Step order" name="order" :options="options.orders" :hint="orderHint" :error="errors.order" />
                <DateRangeControl v-model="definition.range" :presets="options.presets" :timezone="project.timezone" :error="errors.date" />
            </div>

            <p v-if="errors.general" role="alert" class="rounded-md border border-danger px-3 py-2 text-sm text-danger" data-testid="general-error">{{ errors.general }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    ref="runButton"
                    type="submit"
                    class="inline-flex items-center justify-center rounded-md bg-accent px-4 py-2 font-medium text-accent-fg hover:bg-accent-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
                    :aria-disabled="busy || !validation.ok ? 'true' : undefined"
                    :aria-describedby="attempted && !validation.ok ? 'run-hint' : undefined"
                >
                    Run funnel
                </button>
                <button
                    v-if="busy"
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-line-strong bg-surface px-4 py-2 font-medium text-fg hover:bg-canvas"
                    @click="cancel"
                >
                    Cancel run
                </button>
                <p v-if="busy" class="text-sm text-muted" aria-hidden="true" data-testid="status-visible">{{ stageText }}</p>
                <p v-else-if="attempted && !validation.ok" id="run-hint" class="text-sm text-danger" data-testid="run-hint">Fix the highlighted fields to run the funnel.</p>
            </div>
        </form>

        <!-- Always in the DOM: a live region only announces text that is inserted into one that already exists. -->
        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-testid="status">{{ status }}</div>

        <section aria-label="Results" :aria-busy="busy ? 'true' : 'false'" class="space-y-3">
            <p v-if="stale" class="text-sm text-muted" data-testid="status-stale">The form has changed since this result. Run the funnel again to update it.</p>

            <FunnelChart v-if="view === 'result'" :result="shown.result" :stale="stale" />

            <ResultState v-else :kind="view" :error="error" :help="help" :idle-copy="IDLE_COPY" :empty-copy="emptyCopy" noun="funnel" @retry="start" @reload="reload" />
        </section>
    </div>
</template>
