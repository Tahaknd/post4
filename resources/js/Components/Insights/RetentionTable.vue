<script setup>
import { computed } from 'vue';

import { averageCell, cohortCell, cohortLabels, periodHeader, periodNoun } from '@/lib/retentionFormat';
import { formatNumber } from '@/lib/trendsFormat';

// The retention table: the data, and the main view. One row per cohort (the people who did the cohort event in that period),
// the size of the cohort and one column per period, 0 (the cohort period itself) to N, each cell a count and a share. Under
// the head, an Average row: the pooled share per period and how many cohorts it is made of.
//
// The shade of a cell (the `heat-N` classes of app.css) says how big the share is, but never alone: every cell prints its own
// count and share. A period that has not started yet is hatched AND a dash AND has a sentence for a screen reader; a cohort of
// nobody has counts of 0 and no share. Spoken text: the visible numbers are hidden from assistive technology and one sentence
// per cell stands in for them ("2 people of 4, 50%"), so a cell is not read as "2 50%".
//
// The region scrolls on its own, sideways and down, and is focusable so a keyboard can reach every cell; the cohort label (and the
// size from the `sm` width up) and the head stay in view while it scrolls. `relative` keeps the screen reader only sentences
// inside the region (an absolutely positioned child of a scroller that is not positioned would widen the page).
const props = defineProps({
    caption: { type: String, required: true },
    result: { type: Object, required: true },
});

const noun = computed(() => periodNoun(props.result.period));
const labels = computed(() => cohortLabels(props.result.cohorts, props.result.period, props.result.timezone));
const columns = computed(() => Array.from({ length: props.result.periods + 1 }, (_, k) => k));
const averages = computed(() => columns.value.map((k) => averageCell(props.result.averages, k)));

const rows = computed(() =>
    props.result.cohorts.map((cohort, index) => ({
        key: cohort.start,
        label: labels.value[index],
        size: formatNumber(cohort.size),
        cells: columns.value.map((k) => cohortCell(cohort, k, noun.value)),
    })),
);

const heat = (step) => (step === null || step === 0 ? '' : `heat-${step}`);
const cellClass = (cell) => (cell.kind === 'future' ? 'heat-future' : heat(cell.step));

const CELL = 'min-w-[4.75rem] border-b border-l border-line p-0 text-center align-middle';
</script>

<template>
    <div role="group" :aria-label="`${caption}, table`" tabindex="0" class="relative max-h-[36rem] overflow-auto rounded-md border border-line bg-surface">
        <table class="w-max min-w-full border-collapse text-sm" data-testid="retention-table">
            <caption class="px-3 py-2 text-left text-muted">{{ caption }}</caption>
            <thead class="sticky top-0 z-20 bg-surface">
                <tr class="border-b border-line-strong">
                    <th scope="col" class="sticky left-0 z-30 bg-surface p-0 text-left font-medium"><div class="w-40 px-3 py-2">Cohort</div></th>
                    <th scope="col" class="bg-surface p-0 text-right font-medium sm:sticky sm:left-40 sm:z-30 sm:shadow-[inset_-1px_0_0_var(--line-strong)]"><div class="w-20 px-3 py-2">People</div></th>
                    <th v-for="k in columns" :key="k" scope="col" class="whitespace-nowrap bg-surface px-3 py-2 text-center font-medium">{{ periodHeader(result.period, k) }}</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b-2 border-line-strong" data-testid="retention-average">
                    <th scope="row" class="sticky left-0 z-10 bg-surface p-0 text-left font-medium"><div class="w-40 px-3 py-1.5">Average</div></th>
                    <td class="bg-surface p-0 text-right text-muted sm:sticky sm:left-40 sm:z-10 sm:shadow-[inset_-1px_0_0_var(--line-strong)]">
                        <div class="w-20 px-3 py-1.5"><span aria-hidden="true">—</span><span class="sr-only">Not applicable</span></div>
                    </td>
                    <td v-for="(cell, k) in averages" :key="k" :class="[CELL, heat(cell.step)]" data-cell>
                        <div class="px-2 py-1.5 leading-tight">
                            <span class="block font-medium tabular-nums" aria-hidden="true" data-rate>{{ cell.rate }}</span>
                            <span v-if="cell.cohorts" class="block text-xs tabular-nums" aria-hidden="true" data-cohorts>{{ cell.cohorts }}</span>
                            <span class="sr-only">{{ cell.sentence }}</span>
                        </div>
                    </td>
                </tr>
                <tr v-for="row in rows" :key="row.key" data-testid="retention-row">
                    <th scope="row" class="sticky left-0 z-10 border-b border-line bg-surface p-0 text-left font-normal"><div class="w-40 px-3 py-1.5" data-label>{{ row.label }}</div></th>
                    <td class="border-b border-line bg-surface p-0 text-right tabular-nums sm:sticky sm:left-40 sm:z-10 sm:shadow-[inset_-1px_0_0_var(--line-strong)]">
                        <div class="w-20 px-3 py-1.5" data-size>{{ row.size }}</div>
                    </td>
                    <td v-for="(cell, k) in row.cells" :key="k" :class="[CELL, cellClass(cell)]" :data-kind="cell.kind" data-cell>
                        <div class="px-2 py-1.5 leading-tight">
                            <span class="block font-medium tabular-nums" aria-hidden="true" data-count>{{ cell.count }}</span>
                            <span v-if="cell.rate" class="block text-xs tabular-nums" aria-hidden="true" data-rate>{{ cell.rate }}</span>
                            <span class="sr-only">{{ cell.sentence }}</span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
