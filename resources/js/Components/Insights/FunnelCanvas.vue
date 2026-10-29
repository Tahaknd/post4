<script setup>
// The ECharts canvas of a funnel: horizontal bars. Loaded lazily (LazyChart imports it), with only the bar chart, the grid,
// tooltip and aria components; the renderer, the colour tokens and the lifecycle are shared with the trends chart (useEChart).
import { BarChart } from 'echarts/charts';
import { AriaComponent, GridComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { computed, ref } from 'vue';

import { useEChart } from '@/composables/useEChart';
import { buildFunnelOption } from '@/lib/chartOption';

echarts.use([BarChart, GridComponent, TooltipComponent, AriaComponent]);

const props = defineProps({
    steps: { type: Array, required: true },
    description: { type: String, required: true },
});

const el = ref(null);

useEChart(el, ({ theme, reducedMotion }) => buildFunnelOption({ steps: props.steps, theme, reducedMotion, description: props.description }));

// One row of 52 px per step, so the bars keep their size however many steps there are.
const height = computed(() => `${props.steps.length * 52 + 24}px`);
</script>

<template>
    <div ref="el" role="img" :aria-label="description" class="w-full" :style="{ height }" data-testid="chart" />
</template>
