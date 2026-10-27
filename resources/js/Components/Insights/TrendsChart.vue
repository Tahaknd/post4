<script setup>
import { computed, defineAsyncComponent, ref, useId } from 'vue';

import ResultsTable from '@/Components/Insights/ResultsTable.vue';
import { bucketLabels, describeResult, formatNumber, metricNoun, selectChartSeries, seriesNames, total } from '@/lib/trendsFormat';

// The chart of one result: a line/bar switch (aria-pressed buttons), the lazily loaded ECharts canvas, a note when
// more series exist than are drawn, and the numbers as a table in a <details> that is closed by default (a native
// disclosure: keyboard accessible, and the table is in the DOM for assistive technology).
// `interval` and `metric` are those of the run that produced `result` (not the live form), so labels always match.
const props = defineProps({
    result: { type: Object, required: true },
    interval: { type: String, required: true },
    metric: { type: String, required: true },
});

// The echarts chunk is fetched on first use.
const ChartCanvas = defineAsyncComponent({
    loader: () => import('@/Components/Insights/ChartCanvas.vue'),
    loadingComponent: { template: '<div class="h-72 w-full animate-pulse rounded-md bg-line sm:h-96" aria-hidden="true"></div>' },
    errorComponent: { template: '<p role="alert" class="rounded-md border border-danger px-3 py-2 text-danger">The chart could not be loaded. The table below has the same numbers.</p>' },
    delay: 0,
});

const id = useId();
const chartType = ref('line');

const labels = computed(() => bucketLabels(props.result.buckets, props.interval, props.result.timezone));
const longLabels = computed(() => bucketLabels(props.result.buckets, props.interval, props.result.timezone, 'long'));
const names = computed(() => seriesNames(props.result.series));
const selection = computed(() => selectChartSeries(props.result.series));
const drawn = computed(() => selection.value.shown.map((index) => ({ name: names.value[index], data: props.result.series[index].data })));
const description = computed(() => describeResult(props.result, { chartType: chartType.value, metric: props.metric, interval: props.interval }));
const grandTotal = computed(() => props.result.series.reduce((sum, entry) => sum + total(entry.data), 0));

const caption = computed(() => `${metricNoun(props.metric)[0].toUpperCase()}${metricNoun(props.metric).slice(1)} per ${props.interval}, times in ${props.result.timezone}`);
const unique = computed(() => props.metric === 'unique_users');

const TYPES = [
    { value: 'line', label: 'Line' },
    { value: 'bar', label: 'Bar' },
];
</script>

<template>
    <section :aria-labelledby="`${id}-heading`" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 :id="`${id}-heading`" class="text-lg font-semibold tracking-tight">{{ caption }}</h2>
                <p class="text-sm text-muted" data-testid="chart-total">
                    {{ unique ? 'Sum of the values' : 'Total' }}: {{ formatNumber(grandTotal) }} in {{ result.series.length }} series
                </p>
            </div>

            <div role="group" aria-label="Chart type" class="inline-flex overflow-hidden rounded-md border border-line-strong">
                <button
                    v-for="type in TYPES"
                    :key="type.value"
                    type="button"
                    :aria-pressed="chartType === type.value ? 'true' : 'false'"
                    class="px-4 py-2 text-sm font-medium"
                    :class="chartType === type.value ? 'bg-accent text-accent-fg' : 'bg-surface text-fg hover:bg-canvas'"
                    @click="chartType = type.value"
                >
                    {{ type.label }}
                </button>
            </div>
        </div>

        <ChartCanvas :labels="labels" :series="drawn" :chart-type="chartType" :description="description" />

        <p v-if="selection.hidden > 0" class="text-sm text-muted" data-testid="chart-hidden-note">
            Showing the {{ selection.shown.length }} largest of {{ result.series.length }} series ({{ selection.hidden }} more). The table lists all of them.
        </p>

        <details class="rounded-md border border-line bg-surface">
            <summary class="cursor-pointer px-4 py-3 font-medium">Show data as a table</summary>
            <div class="px-4 pb-4">
                <ResultsTable
                    :caption="caption"
                    period-header="Period"
                    :labels="longLabels"
                    :names="names"
                    :series="result.series"
                    :note="unique ? 'A user is counted once in every period they were active, so the sums add periods together; they are not distinct users.' : undefined"
                />
            </div>
        </details>
    </section>
</template>
