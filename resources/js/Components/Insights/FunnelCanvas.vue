<script setup>
// The ECharts canvas of a funnel: horizontal bars. Loaded lazily (LazyChart imports it), with only the bar chart, the grid,
// tooltip and aria components; the renderer, the colour tokens and the lifecycle are shared with the trends chart (useEChart).
import { BarChart } from 'echarts/charts';
import { AriaComponent, GridComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { useEChart } from '@/composables/useEChart';
import { buildFunnelOption } from '@/lib/chartOption';

echarts.use([BarChart, GridComponent, TooltipComponent, AriaComponent]);

const props = defineProps({
    steps: { type: Array, required: true },
    description: { type: String, required: true },
});

const el = ref(null);

// A narrow screen gets shorter labels (the table under the chart has the full names and rates).
const compact = ref(false);
let narrow = null;
const setCompact = (event) => (compact.value = event.matches);

onMounted(() => {
    narrow = window.matchMedia('(max-width: 639px)');
    compact.value = narrow.matches;
    narrow.addEventListener('change', setCompact);
});
onBeforeUnmount(() => narrow?.removeEventListener('change', setCompact));

useEChart(el, ({ theme, reducedMotion }) => buildFunnelOption({ steps: props.steps, theme, reducedMotion, description: props.description, compact: compact.value }));

// One row of 52 px per step, so the bars keep their size however many steps there are.
const height = computed(() => `${props.steps.length * 52 + 24}px`);
</script>

<template>
    <div ref="el" role="img" :aria-label="description" class="w-full" :style="{ height }" data-testid="chart" />
</template>
