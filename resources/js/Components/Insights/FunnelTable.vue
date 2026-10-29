<script setup>
import { formatNumber } from '@/lib/trendsFormat';
import { NO_RATE, formatRate } from '@/lib/funnelFormat';

// The numbers of the chart as a real table (the chart is a picture, this is the data): a caption, a row header per step and
// the count, the people lost since the step before, and the conversion from the previous step and from the first. A rate that
// does not exist (nobody reached the step before, or step 1 has no previous step) is an em dash for the eye and a sentence
// for a screen reader. The region scrolls sideways when it must and is focusable so a keyboard can reach every column.
defineProps({
    caption: { type: String, required: true },
    steps: { type: Array, required: true },
});

const noRate = (step) => (step.index === 1 ? 'Not applicable' : 'No rate: nobody reached the previous step');
</script>

<template>
    <div role="group" :aria-label="`${caption}, table`" tabindex="0" class="relative overflow-auto rounded-md border border-line">
        <table class="w-full min-w-[34rem] border-collapse text-sm" data-testid="funnel-table">
            <caption class="px-3 py-2 text-left text-muted">{{ caption }}</caption>
            <thead class="bg-surface">
                <tr class="border-b border-line-strong">
                    <th scope="col" class="px-3 py-2 text-left font-medium">Step</th>
                    <th scope="col" class="px-3 py-2 text-right font-medium">People</th>
                    <th scope="col" class="px-3 py-2 text-right font-medium">Dropped off</th>
                    <th scope="col" class="px-3 py-2 text-right font-medium">From previous step</th>
                    <th scope="col" class="px-3 py-2 text-right font-medium">From first step</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="step in steps" :key="step.index" class="border-b border-line">
                    <th scope="row" class="px-3 py-1.5 text-left font-normal">
                        <span class="tabular-nums text-muted">{{ step.index }}.</span>
                        <span class="ml-1 break-words">{{ step.event }}</span>
                    </th>
                    <td class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(step.count) }}</td>
                    <td class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(step.dropped_off) }}</td>
                    <td class="px-3 py-1.5 text-right tabular-nums">
                        <template v-if="step.conversion_from_previous === null"><span aria-hidden="true">{{ NO_RATE }}</span><span class="sr-only">{{ noRate(step) }}</span></template>
                        <template v-else>{{ formatRate(step.conversion_from_previous) }}</template>
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums">
                        <template v-if="step.conversion_from_first === null"><span aria-hidden="true">{{ NO_RATE }}</span><span class="sr-only">No rate: nobody started</span></template>
                        <template v-else>{{ formatRate(step.conversion_from_first) }}</template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
