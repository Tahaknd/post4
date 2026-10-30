<script setup>
// The ECharts canvas of the average retention curve. Loaded lazily (LazyChart imports it), with only the line chart, the grid,
// tooltip and aria components; the renderer, the colour tokens and the lifecycle are shared with the other charts (useEChart).
import { LineChart } from 'echarts/charts';
import { AriaComponent, GridComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { ref } from 'vue';

import { useEChart } from '@/composables/useEChart';
import { buildRetentionOption } from '@/lib/chartOption';

echarts.use([LineChart, GridComponent, TooltipComponent, AriaComponent]);

const props = defineProps({
    labels: { type: Array, required: true },
    points: { type: Array, required: true },
    description: { type: String, required: true },
    // A dashboard tile: a shorter plot (the tile reserves the room).
    dense: { type: Boolean, default: false },
});

const el = ref(null);

useEChart(el, ({ theme, reducedMotion }) => buildRetentionOption({ labels: props.labels, points: props.points, name: 'Average retention', theme, reducedMotion, description: props.description }));
</script>

<template>
    <div ref="el" role="img" :aria-label="description" :class="dense ? 'h-44 w-full' : 'h-64 w-full sm:h-80'" data-testid="chart" />
</template>
