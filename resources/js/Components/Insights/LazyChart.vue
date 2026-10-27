<script setup>
import { onMounted, shallowRef } from 'vue';

import ChartError from '@/Components/Insights/ChartError.vue';
import ChartSkeleton from '@/Components/Insights/ChartSkeleton.vue';

// Loads the ECharts canvas on first use, so ECharts (about 550 kB) stays out of the entry bundle and is fetched only when
// a chart is shown. Written by hand instead of defineAsyncComponent because its failed load cannot be retried by a
// button (Vue keeps the rejected promise): this one imports again on "Try again". While it loads there is a skeleton,
// when it fails an alert with the retry; both are real components (the build has no runtime template compiler).
defineOptions({ inheritAttrs: false });

const canvas = shallowRef(null);
const failed = shallowRef(false);

async function load() {
    failed.value = false;

    try {
        canvas.value = (await import('@/Components/Insights/ChartCanvas.vue')).default;
    } catch {
        failed.value = true;
    }
}

onMounted(load);
</script>

<template>
    <component :is="canvas" v-if="canvas" v-bind="$attrs" />
    <ChartError v-else-if="failed" @retry="load" />
    <ChartSkeleton v-else />
</template>
