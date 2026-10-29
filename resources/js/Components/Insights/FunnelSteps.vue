<script setup>
import { computed, nextTick, ref } from 'vue';

import EventCombobox from '@/Components/Insights/EventCombobox.vue';
import FilterList from '@/Components/Insights/FilterList.vue';
import { SecondaryButtonClass } from '@/Components/Insights/classes';
import { addStep, canAddStep, canMoveStep, canRemoveStep, moveStep, patchStep, removeStep } from '@/lib/funnelDefinition';

// The step builder of a funnel: an ordered list of steps, each with an event name (a combobox with suggestions) and its own
// property filters. Steps are added at the end, removed, and reordered with "Move up" / "Move down" buttons (there is no
// drag: everything works by keyboard and by screen reader). A button that cannot act (the first step cannot move up, the
// list is at its minimum or maximum) says so with aria-disabled and stays focusable. Focus follows the action: a moved
// step keeps its button focused, a new step puts the caret in its event field, a removed one hands focus to its neighbour.
// A polite live region says what happened.
const props = defineProps({
    names: { type: Object, required: true },
    operators: { type: Array, required: true },
    limits: { type: Object, required: true },
    // { [stepId]: { event?, filters?, general? } }: the messages to show, already filtered for what may appear
    errors: { type: Object, default: () => ({}) },
});

const steps = defineModel({ type: Array, required: true });

const root = ref(null);
const announcement = ref('');
const filterLimits = computed(() => ({ ...props.limits, max_filters: props.limits.max_filters_per_step }));

function announce(value) {
    // Cleared first so the same sentence twice in a row is announced twice.
    announcement.value = '';
    nextTick(() => (announcement.value = value));
}

const focusIn = async (selector) => {
    await nextTick();
    root.value?.querySelector(selector)?.focus();
};

function add() {
    if (!canAddStep(steps.value, props.limits)) {
        return;
    }

    // The new list is used from here on, not `steps.value`: the model only shows it after the parent has rendered again.
    const next = addStep(steps.value, props.limits);
    steps.value = next;
    announce(`Step ${next.length} added. ${next.length} of ${props.limits.max_steps} steps.`);
    focusIn(`[data-step="${next[next.length - 1].id}"] input[role="combobox"]`);
}

function remove(step, index) {
    if (!canRemoveStep(steps.value, props.limits)) {
        return;
    }

    const next = removeStep(steps.value, step.id, props.limits);
    steps.value = next;
    announce(`Step ${index + 1} removed. ${next.length} of ${props.limits.max_steps} steps.`);
    const neighbour = next[Math.min(index, next.length - 1)];
    focusIn(`[data-step="${neighbour.id}"] input[role="combobox"]`);
}

function move(step, index, delta) {
    if (!canMoveStep(steps.value, step.id, delta)) {
        return;
    }

    const next = moveStep(steps.value, step.id, delta);
    steps.value = next;
    announce(`Step ${index + 1} moved ${delta < 0 ? 'up' : 'down'} to position ${index + 1 + delta} of ${next.length}.`);
    // Moving the DOM node can drop focus: put it back on the same button, or on its twin when the step reached an end.
    const wanted = delta < 0 ? 'up' : 'down';
    const other = delta < 0 ? 'down' : 'up';
    nextTick(() => {
        const row = root.value?.querySelector(`[data-step="${step.id}"]`);
        const button = row?.querySelector(`[data-move="${wanted}"]`);
        (button?.getAttribute('aria-disabled') === 'true' ? row?.querySelector(`[data-move="${other}"]`) : button)?.focus();
    });
}

const setEvent = (step, value) => (steps.value = patchStep(steps.value, step.id, { event: value }));
const setFilters = (step, value) => (steps.value = patchStep(steps.value, step.id, { filters: value }));

const buttonClass = `${SecondaryButtonClass} !px-3 !py-1.5 text-sm aria-disabled:cursor-not-allowed aria-disabled:opacity-60`;
</script>

<template>
    <div ref="root" class="space-y-3">
        <ol aria-label="Funnel steps" class="space-y-3">
            <li v-for="(step, index) in steps" :key="step.id" :data-step="step.id" class="space-y-3 rounded-md border border-line bg-canvas p-3 sm:p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                    <div class="min-w-0 flex-1">
                        <EventCombobox
                            :model-value="step.event"
                            :names="names"
                            :label="`Step ${index + 1} event`"
                            label-class="mb-1.5 block text-sm font-medium"
                            :limits="limits"
                            :error="errors[step.id]?.event"
                            :field="`step:${step.id}`"
                            @update:model-value="setEvent(step, $event)"
                        />
                    </div>

                    <div role="group" :aria-label="`Step ${index + 1} actions`" class="flex flex-wrap gap-2">
                        <button type="button" data-move="up" :class="buttonClass" :aria-disabled="canMoveStep(steps, step.id, -1) ? undefined : 'true'" @click="move(step, index, -1)">
                            Move up<span class="sr-only"> step {{ index + 1 }}</span>
                        </button>
                        <button type="button" data-move="down" :class="buttonClass" :aria-disabled="canMoveStep(steps, step.id, 1) ? undefined : 'true'" @click="move(step, index, 1)">
                            Move down<span class="sr-only"> step {{ index + 1 }}</span>
                        </button>
                        <button type="button" data-remove :class="buttonClass" :aria-disabled="canRemoveStep(steps, limits) ? undefined : 'true'" @click="remove(step, index)">
                            Remove<span class="sr-only"> step {{ index + 1 }}</span>
                        </button>
                    </div>
                </div>

                <FilterList
                    :model-value="step.filters"
                    :operators="operators"
                    :limits="filterLimits"
                    :errors="errors[step.id]?.filters ?? {}"
                    :scope="`step ${index + 1}`"
                    empty-text="No filters: any event with this name counts."
                    @update:model-value="setFilters(step, $event)"
                />
                <p v-if="errors[step.id]?.general" role="alert" class="text-sm text-danger">{{ errors[step.id].general }}</p>
            </li>
        </ol>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" data-add-step :class="buttonClass" :aria-disabled="canAddStep(steps, limits) ? undefined : 'true'" @click="add">Add step</button>
            <p class="text-sm text-muted">{{ steps.length }} of {{ limits.max_steps }} steps<template v-if="!canAddStep(steps, limits)">. At most {{ limits.max_steps }}.</template></p>
        </div>

        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ announcement }}</div>
    </div>
</template>
