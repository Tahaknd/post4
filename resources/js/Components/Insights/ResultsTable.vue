<script setup>
import { computed } from 'vue';

import { formatNumber, total, truncate } from '@/lib/trendsFormat';

// The same numbers as the chart, as a real table: a caption, column headers for the series (all of them, however
// many), a row header per period and a sum row. Wide results scroll sideways inside a focusable region so keyboard
// users can reach every column.
const props = defineProps({
    caption: { type: String, required: true },
    periodHeader: { type: String, required: true },
    labels: { type: Array, required: true },
    names: { type: Array, required: true },
    series: { type: Array, required: true },
    note: { type: String, default: undefined },
});

const sums = computed(() => props.series.map((entry) => total(entry.data)));
</script>

<template>
    <div class="space-y-2">
        <p v-if="note" class="text-sm text-muted">{{ note }}</p>
        <div role="group" :aria-label="`${caption}, table`" tabindex="0" class="max-h-[32rem] overflow-auto rounded-md border border-line">
            <table class="w-full border-collapse text-sm">
                <caption class="px-3 py-2 text-left text-muted">{{ caption }}</caption>
                <thead class="sticky top-0 z-10 bg-surface">
                    <tr class="border-b border-line-strong">
                        <th scope="col" class="sticky left-0 z-10 bg-surface px-3 py-2 text-left font-medium">{{ periodHeader }}</th>
                        <th v-for="(name, index) in names" :key="index" scope="col" class="whitespace-nowrap px-3 py-2 text-right font-medium" :title="name">
                            <span aria-hidden="true">{{ truncate(name) }}</span><span class="sr-only">{{ name }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(label, row) in labels" :key="row" class="border-b border-line">
                        <th scope="row" class="sticky left-0 whitespace-nowrap bg-surface px-3 py-1.5 text-left font-normal">{{ label }}</th>
                        <td v-for="(entry, column) in series" :key="column" class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(entry.data[row]) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-line-strong">
                        <th scope="row" class="sticky left-0 bg-surface px-3 py-2 text-left font-medium">Sum</th>
                        <td v-for="(sum, column) in sums" :key="column" class="px-3 py-2 text-right font-medium tabular-nums">{{ formatNumber(sum) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
