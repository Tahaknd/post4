<script setup>
import { computed, useId } from 'vue';

import FunnelTable from '@/Components/Insights/FunnelTable.vue';
import LazyChart from '@/Components/Insights/LazyChart.vue';
import { describeFunnel, orderLabel, summaryItems } from '@/lib/funnelFormat';

// One funnel result: the headline numbers, the horizontal bars (the lazily loaded ECharts canvas) and the same numbers as a
// table that is always shown (a funnel has at most 8 rows: there is no reason to hide the data behind the picture).
// `stale` dims the PLOT only, never the text (dimmed text would drop below readable contrast).
const props = defineProps({
    result: { type: Object, required: true },
    stale: { type: Boolean, default: false },
});

const id = useId();

const items = computed(() => summaryItems(props.result));
const description = computed(() => describeFunnel(props.result));
const caption = computed(() => `People who reached each step, ${orderLabel(props.result.order).toLowerCase()} funnel`);
</script>

<template>
    <section :aria-labelledby="`${id}-heading`" class="space-y-4" data-testid="funnel-result">
        <div class="space-y-2">
            <h2 :id="`${id}-heading`" class="text-lg font-semibold tracking-tight">Funnel result</h2>
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="funnel-summary">
                <div v-for="item in items" :key="item.label" class="rounded-md border border-line bg-surface px-3 py-2">
                    <dt class="text-sm text-muted">{{ item.label }}</dt>
                    <dd class="text-lg font-semibold tabular-nums">{{ item.value }}</dd>
                </div>
            </dl>
            <p class="text-sm text-muted" data-testid="funnel-order">Order: {{ orderLabel(result.order) }}</p>
        </div>

        <div :class="stale ? 'opacity-50 transition-opacity' : 'transition-opacity'">
            <LazyChart kind="funnel" :steps="result.steps" :description="description" />
        </div>

        <FunnelTable :caption="caption" :steps="result.steps" />
    </section>
</template>
