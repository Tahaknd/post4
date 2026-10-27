<script setup>
// The ECharts canvas. Loaded lazily (TrendsChart imports it with defineAsyncComponent), so ECharts stays out of the
// entry bundle: only `echarts/core` plus the line and bar charts, the grid, tooltip, (scroll) legend and aria
// components and the SVG renderer are imported.
//
// SVG, not canvas: the plot has at most ~10 000 points (the API caps a result at 10 000 cells and the chart draws at
// most 10 series), which SVG handles easily; it stays crisp on every pixel density and zoom level, and its nodes can
// be inspected by tests and tools (the Playwright smoke test asserts on them). The canvas renderer would only win on
// far larger plots.
//
// ECharts cannot read CSS variables, so the colours are resolved from the design tokens here (readChartTheme) and
// resolved AGAIN whenever the colour scheme changes (prefers-color-scheme, or [data-theme] on <html>). Animation is
// off when the user prefers reduced motion (also followed live).
import { BarChart, LineChart } from 'echarts/charts';
import { AriaComponent, GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import { buildChartOption, readChartTheme } from '@/lib/chartOption';

echarts.use([LineChart, BarChart, GridComponent, TooltipComponent, LegendComponent, AriaComponent, SVGRenderer]);

const props = defineProps({
    labels: { type: Array, required: true },
    series: { type: Array, required: true },
    chartType: { type: String, required: true },
    description: { type: String, required: true },
});

const el = ref(null);
const theme = shallowRef(null);
const reducedMotion = ref(false);
let chart = null;
let resizeObserver = null;
let themeObserver = null;
const queries = [];

const option = computed(() =>
    theme.value === null
        ? null
        : buildChartOption({
              labels: props.labels,
              series: props.series,
              chartType: props.chartType,
              theme: theme.value,
              reducedMotion: reducedMotion.value,
              description: props.description,
          }),
);

function resolveTheme() {
    // The new colour scheme is applied to the computed style a frame after the media query changes.
    requestAnimationFrame(() => (theme.value = readChartTheme()));
}

onMounted(() => {
    theme.value = readChartTheme();

    const dark = window.matchMedia('(prefers-color-scheme: dark)');
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    reducedMotion.value = motion.matches;
    dark.addEventListener('change', resolveTheme);
    motion.addEventListener('change', (event) => (reducedMotion.value = event.matches));
    queries.push([dark, resolveTheme]);

    themeObserver = new MutationObserver(resolveTheme);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    chart = echarts.init(el.value, null, { renderer: 'svg' });
    resizeObserver = new ResizeObserver(() => chart?.resize());
    resizeObserver.observe(el.value);
});

watch(
    option,
    (next) => {
        // notMerge: a new definition replaces the old option instead of blending series into it.
        if (chart !== null && next !== null) {
            chart.setOption(next, { notMerge: true });
        }
    },
    { flush: 'post' },
);

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    themeObserver?.disconnect();
    for (const [query, listener] of queries) {
        query.removeEventListener('change', listener);
    }
    chart?.dispose();
    chart = null;
});
</script>

<template>
    <div ref="el" role="img" :aria-label="description" class="h-72 w-full sm:h-96" data-testid="chart" />
</template>
