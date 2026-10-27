<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, reactive, shallowRef, watch } from 'vue';

import BreakdownControl from '@/Components/Insights/BreakdownControl.vue';
import DateRangeControl from '@/Components/Insights/DateRangeControl.vue';
import EventPicker from '@/Components/Insights/EventPicker.vue';
import FilterList from '@/Components/Insights/FilterList.vue';
import IntervalSelect from '@/Components/Insights/IntervalSelect.vue';
import MetricSelect from '@/Components/Insights/MetricSelect.vue';
import ResultState from '@/Components/Insights/ResultState.vue';
import TrendsChart from '@/Components/Insights/TrendsChart.vue';
import { useInsightRun } from '@/composables/useInsightRun';
import {
    decodeDefinition,
    defaultDefinition,
    encodeDefinition,
    hasEvents,
    mapServerErrors,
    toRequestBody,
    validateDefinition,
} from '@/lib/trendsDefinition';
import { formatNumber, isEmptyResult, total } from '@/lib/trendsFormat';

// The Trends page. Everything it needs to build a definition (allowed values, limits, defaults) and the API URLs come
// from the server as props; there is no API key, the API is called with the session. The definition lives in one
// reactive object that mirrors the API body, is validated on every change (instant feedback, the same limits as the
// server) and is mirrored into the query string, so a link reproduces the chart. The query string is NEVER trusted:
// decodeDefinition() checks every field against the props.
const props = defineProps({
    project: { type: Object, required: true },
    options: { type: Object, required: true },
    defaults: { type: Object, required: true },
    limits: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const definition = reactive(decodeDefinition(window.location.search, props) ?? defaultDefinition(props.defaults));

const run = useInsightRun(props.urls.trends);

const validation = computed(() => validateDefinition(definition, props));
const body = computed(() => toRequestBody(definition, props.options));
const runnable = computed(() => hasEvents(definition) && validation.value.ok);

// What the run that produced the visible result was asked for (labels must match the data, not the live form).
let lastBody = null;
const shown = shallowRef(null);

watch(
    [() => JSON.stringify(body.value), runnable],
    () => {
        if (!runnable.value) {
            lastBody = null;
            run.reset();

            return;
        }

        lastBody = body.value;
        run.schedule(body.value);
    },
    { immediate: true },
);

watch(
    () => run.state.value,
    (state) => {
        if (state.phase === 'done' && lastBody !== null) {
            shown.value = { result: state.result, interval: lastBody.interval, metric: lastBody.metric };
        } else if (state.phase !== 'loading') {
            shown.value = null;
        }
    },
    { immediate: true },
);

// The query string follows the definition. Inertia keeps its page object in history.state: it is passed through
// untouched, only the URL changes.
watch(
    () => encodeDefinition(definition, props.defaults),
    (query) => {
        const url = window.location.pathname + (query === '' ? '' : `?${query}`);

        if (url !== window.location.pathname + window.location.search) {
            window.history.replaceState(window.history.state, '', url);
        }
    },
    { flush: 'post' },
);

// Field errors: the instant client-side ones win; the API's 422 messages fill the rest.
const serverErrors = computed(() => (run.state.value.phase === 'invalid' ? mapServerErrors(run.state.value.fieldErrors, definition) : null));

const errors = computed(() => {
    const client = validation.value.errors;
    const server = serverErrors.value;

    return {
        events: client.events ?? server?.events,
        date: client.date ?? server?.date,
        metric: server?.metric,
        interval: server?.interval,
        breakdown: { ...(server?.breakdown ?? {}), ...client.breakdown },
        filters: { ...(server?.filters ?? {}), ...client.filters },
        general: client.general ?? server?.general ?? (serverErrors.value && !hasFieldErrors(server) ? run.state.value.error?.message : undefined),
    };
});

function hasFieldErrors(mapped) {
    return mapped.events !== undefined || mapped.date !== undefined || mapped.metric !== undefined || mapped.interval !== undefined
        || Object.keys(mapped.breakdown).length > 0 || Object.keys(mapped.filters).length > 0;
}

const state = computed(() => run.state.value);

/** Which of the states of the results area applies. */
const view = computed(() => {
    if (!hasEvents(definition)) {
        return 'idle';
    }
    if (!validation.value.ok) {
        return 'blocked';
    }

    switch (state.value.phase) {
        case 'done':
            return shown.value !== null && isEmptyResult(shown.value.result) ? 'empty' : 'result';
        case 'loading':
        case 'idle':
            return shown.value === null ? 'loading' : 'result';
        case 'invalid':
            return 'blocked';
        default:
            return state.value.phase;
    }
});

const busy = computed(() => state.value.phase === 'loading' && runnable.value);

// One short sentence for the live region and the visible status line.
const status = computed(() => {
    if (busy.value) {
        return state.value.notice ?? 'Running query…';
    }

    switch (view.value) {
        case 'result': {
            const { result } = shown.value;

            return `Chart updated: ${result.series.length} series, ${formatNumber(result.series.reduce((sum, entry) => sum + total(entry.data), 0))} in total.`;
        }
        case 'empty':
            return 'No events in this range.';
        case 'failed':
            return state.value.error?.message ?? '';
        case 'expired':
            return 'This result is no longer available. Run it again.';
        case 'blocked':
            return errors.value.general ?? 'Fix the highlighted fields to run the query.';
        default:
            return '';
    }
});

function reload() {
    window.location.reload();
}
</script>

<template>
    <Head :title="`Trends - ${project.name}`" />

    <div class="space-y-8">
        <header class="space-y-1">
            <p class="text-sm"><Link :href="urls.project" class="text-accent hover:text-accent-hover">{{ project.name }}</Link></p>
            <h1 class="text-2xl font-semibold tracking-tight">Trends</h1>
            <p class="text-sm text-muted">How often events happen over time. Times are in {{ project.timezone }}.</p>
        </header>

        <form aria-label="Trend definition" class="space-y-5 rounded-md border border-line bg-surface p-4 sm:p-5" novalidate @submit.prevent>
            <EventPicker v-model="definition.events" :events-url="urls.events" :limits="limits" :error="errors.events" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <MetricSelect v-model="definition.metric" :options="options.metrics" :error="errors.metric" />
                <DateRangeControl v-model="definition.range" :presets="options.presets" :timezone="project.timezone" :error="errors.date" />
                <IntervalSelect v-model="definition.interval" :options="options.intervals" :error="errors.interval" />
            </div>

            <BreakdownControl v-model="definition.breakdown" :columns="options.breakdown_columns" :limits="limits" :errors="errors.breakdown" />

            <FilterList v-model="definition.filters" :operators="options.operators" :limits="limits" :errors="errors.filters" />

            <p v-if="errors.general" role="alert" class="rounded-md border border-danger px-3 py-2 text-sm text-danger" data-testid="general-error">{{ errors.general }}</p>
        </form>

        <!-- Always in the DOM: a live region only announces text that is inserted into one that already exists. -->
        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-testid="status">{{ status }}</div>

        <section aria-label="Results" :aria-busy="busy ? 'true' : 'false'" class="space-y-3">
            <p v-if="busy" class="text-sm text-muted" aria-hidden="true" data-testid="status-visible">{{ state.notice ?? 'Running query…' }}</p>

            <div v-if="view === 'result'" :class="busy ? 'opacity-60 transition-opacity' : 'transition-opacity'">
                <TrendsChart :result="shown.result" :interval="shown.interval" :metric="shown.metric" />
            </div>

            <ResultState v-else :kind="view" :error="state.error" @retry="run.retry()" @reload="reload" />
        </section>
    </div>
</template>
