<script setup>
import { computed, useId } from 'vue';

import LazyChart from '@/Components/Insights/LazyChart.vue';
import RetentionTable from '@/Components/Insights/RetentionTable.vue';
import { curveLabels, describeCurve, periodNoun, periodsNote, summaryItems } from '@/lib/retentionFormat';

// One retention result: the headline numbers, the average retention curve (the lazily loaded ECharts canvas) and the cohort table,
// which is the main view and the data of both (it is always shown: a result of a few dozen rows has no reason to hide behind a
// picture). `stale` dims the PLOT only, never the text or the table (dimmed text would drop below readable contrast).
const props = defineProps({
    result: { type: Object, required: true },
    stale: { type: Boolean, default: false },
});

const id = useId();

const items = computed(() => summaryItems(props.result));
const labels = computed(() => curveLabels(props.result));
const description = computed(() => describeCurve(props.result));
const caption = computed(() => `People of each cohort who did the return event, by ${periodNoun(props.result.period)} after the cohort ${periodNoun(props.result.period)}`);
</script>

<template>
    <section :aria-labelledby="`${id}-heading`" class="space-y-4" data-testid="retention-result">
        <div class="space-y-2">
            <h2 :id="`${id}-heading`" class="text-lg font-semibold tracking-tight">Retention result</h2>
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="retention-summary">
                <div v-for="item in items" :key="item.label" class="rounded-md border border-line bg-surface px-3 py-2">
                    <dt class="text-sm text-muted">{{ item.label }}</dt>
                    <dd class="text-lg font-semibold tabular-nums">{{ item.value }}</dd>
                </div>
            </dl>
            <p class="text-sm text-muted" data-testid="retention-note">{{ periodsNote(result) }}</p>
        </div>

        <div :class="stale ? 'opacity-50 transition-opacity' : 'transition-opacity'">
            <LazyChart kind="retention" :labels="labels" :points="result.averages.rates" :description="description" />
        </div>

        <div class="space-y-2">
            <RetentionTable :caption="caption" :result="result" />
            <p class="text-sm text-muted">Every cell shows its own numbers: the count of people and their share of the cohort. A darker cell is a larger share. A hatched cell with a dash is a period that has not started yet. The Average row pools the cohorts: everyone who came back over everyone in them, so a large cohort weighs more.</p>
        </div>
    </section>
</template>
