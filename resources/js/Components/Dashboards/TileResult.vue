<script setup>
import FunnelChart from '@/Components/Insights/FunnelChart.vue';
import RetentionChart from '@/Components/Insights/RetentionChart.vue';
import TrendsChart from '@/Components/Insights/TrendsChart.vue';

// The result of a tile: the SAME result components as the pages, in their compact mode (the plot and the headline numbers;
// the full table is one click away, on the page of the insight). `meta` is the interval and metric of a trends definition.
defineProps({
    type: { type: String, required: true },
    result: { type: Object, required: true },
    meta: { type: Object, default: null },
});
</script>

<template>
    <TrendsChart v-if="type === 'trends'" compact :result="result" :interval="meta?.interval ?? 'day'" :metric="meta?.metric ?? 'total_events'" />
    <FunnelChart v-else-if="type === 'funnel'" compact :result="result" />
    <RetentionChart v-else compact :result="result" />
</template>
