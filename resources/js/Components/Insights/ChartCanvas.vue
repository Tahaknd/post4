<script setup>
// The ECharts canvas of a trends chart. Loaded lazily (LazyChart imports it), so ECharts stays out of the entry bundle:
// only `echarts/core` plus the line and bar charts, the grid, tooltip, (scroll) legend and aria components are imported
// here; the renderer, the colour tokens and the lifecycle are shared with the other charts (useEChart).
import { BarChart, LineChart } from 'echarts/charts';
import { AriaComponent, GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { ref } from 'vue';

import { useEChart } from '@/composables/useEChart';
import { buildChartOption } from '@/lib/chartOption';

echarts.use([LineChart, BarChart, GridComponent, TooltipComponent, LegendComponent, AriaComponent]);

const props = defineProps({
    labels: { type: Array, required: true },
    series: { type: Array, required: true },
    chartType: { type: String, required: true },
    description: { type: String, required: true },
    // A dashboard tile: a shorter plot (the tile reserves the room).
    dense: { type: Boolean, default: false },
});

const el = ref(null);

useEChart(el, ({ theme, reducedMotion }) =>
    buildChartOption({
        labels: props.labels,
        series: props.series,
        chartType: props.chartType,
        theme,
        reducedMotion,
        description: props.description,
    }),
);
</script>

<template>
    <div ref="el" role="img" :aria-label="description" :class="dense ? 'h-44 w-full' : 'h-72 w-full sm:h-96'" data-testid="chart" />
</template>
