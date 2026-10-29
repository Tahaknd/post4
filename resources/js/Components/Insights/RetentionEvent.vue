<script setup>
import { computed } from 'vue';

import EventCombobox from '@/Components/Insights/EventCombobox.vue';
import FilterList from '@/Components/Insights/FilterList.vue';

// One of the two events of a retention table: a heading that finishes the sentence ("People first do…", "…and come back to
// do"), the event (a combobox with suggestions) and its own property filters. `field` is the name the page tracks "was this
// field left" by; `scope` names the event for a screen reader ("cohort event", "return event").
const props = defineProps({
    heading: { type: String, required: true },
    help: { type: String, required: true },
    label: { type: String, required: true },
    scope: { type: String, required: true },
    field: { type: String, required: true },
    names: { type: Object, required: true },
    operators: { type: Array, required: true },
    limits: { type: Object, required: true },
    // { event?, filters?, general? }: the messages to show, already filtered for what may appear
    errors: { type: Object, default: () => ({}) },
});

const condition = defineModel({ type: Object, required: true });

const filterLimits = computed(() => ({ ...props.limits, max_filters: props.limits.max_filters_per_event }));
const id = computed(() => `retention-${props.field}`);

const setEvent = (value) => (condition.value = { ...condition.value, event: value });
const setFilters = (value) => (condition.value = { ...condition.value, filters: value });
</script>

<template>
    <section :aria-labelledby="`${id}-heading`" class="space-y-3 rounded-md border border-line bg-canvas p-3 sm:p-4">
        <div class="space-y-1">
            <h2 :id="`${id}-heading`" class="text-sm font-medium">{{ heading }}</h2>
            <p class="text-sm text-muted">{{ help }}</p>
        </div>

        <EventCombobox
            :model-value="condition.event"
            :names="names"
            :label="label"
            label-class="mb-1.5 block text-sm font-medium"
            :limits="limits"
            :error="errors.event"
            :field="field"
            @update:model-value="setEvent"
        />

        <FilterList
            :model-value="condition.filters"
            :operators="operators"
            :limits="filterLimits"
            :errors="errors.filters ?? {}"
            :scope="scope"
            empty-text="No filters: any event with this name counts."
            @update:model-value="setFilters"
        />
        <p v-if="errors.general" role="alert" class="text-sm text-danger">{{ errors.general }}</p>
    </section>
</template>
