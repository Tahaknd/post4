<script setup>
import { computed, useId } from 'vue';

// Break the chart down by a column of the event or by a property key typed as free text (property keys are not
// suggested: they are whatever your events carry). `top_n` is how many values are listed; the rest become "Other".
const props = defineProps({
    columns: { type: Array, required: true },
    limits: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});

// { mode: 'none' | 'column' | 'property', column, property, topN }
const model = defineModel({ type: Object, required: true });

const id = useId();
const MODES = [
    { value: 'none', label: 'No breakdown' },
    { value: 'column', label: 'Event column' },
    { value: 'property', label: 'Property key' },
];

function patch(changes) {
    model.value = { ...model.value, ...changes };
}

function setMode(mode) {
    patch(mode === 'column' && model.value.column === '' ? { mode, column: props.columns[0]?.value ?? '' } : { mode });
}

function setTopN(event) {
    const value = event.target.value;
    patch({ topN: value === '' ? Number.NaN : Number(value) });
}

const topNValue = computed(() => (Number.isNaN(model.value.topN) ? '' : model.value.topN));
const keyError = computed(() => props.errors.property);
</script>

<template>
    <fieldset class="space-y-1.5">
        <legend class="block text-sm font-medium">Break down by</legend>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_6rem]">
            <div>
                <label :for="`${id}-mode`" class="sr-only">Breakdown type</label>
                <select
                    :id="`${id}-mode`"
                    name="breakdown_mode"
                    :value="model.mode"
                    class="block w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-fg"
                    @change="setMode($event.target.value)"
                >
                    <option v-for="mode in MODES" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                </select>
            </div>

            <div v-if="model.mode === 'column'">
                <label :for="`${id}-column`" class="sr-only">Column</label>
                <select
                    :id="`${id}-column`"
                    name="breakdown_column"
                    :value="model.column"
                    :aria-invalid="errors.column ? 'true' : undefined"
                    :aria-describedby="errors.column ? `${id}-column-error` : undefined"
                    class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                    :class="errors.column ? 'border-danger' : 'border-line-strong'"
                    @change="patch({ column: $event.target.value })"
                >
                    <option v-for="column in columns" :key="column.value" :value="column.value">{{ column.label }}</option>
                </select>
            </div>

            <div v-else-if="model.mode === 'property'">
                <label :for="`${id}-property`" class="sr-only">Property key</label>
                <input
                    :id="`${id}-property`"
                    type="text"
                    name="breakdown_property"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="e.g. plan or $browser"
                    :value="model.property"
                    :maxlength="limits.max_breakdown_key_length"
                    :aria-invalid="keyError ? 'true' : undefined"
                    :aria-describedby="keyError ? `${id}-property-error` : undefined"
                    class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                    :class="keyError ? 'border-danger' : 'border-line-strong'"
                    @input="patch({ property: $event.target.value })"
                />
            </div>

            <div v-if="model.mode !== 'none'" class="sm:col-start-3">
                <label :for="`${id}-top`" class="sr-only">Number of values to show (1 to {{ limits.max_top_n }})</label>
                <input
                    :id="`${id}-top`"
                    type="number"
                    name="breakdown_top_n"
                    inputmode="numeric"
                    min="1"
                    :max="limits.max_top_n"
                    step="1"
                    :value="topNValue"
                    :aria-invalid="errors.top_n ? 'true' : undefined"
                    :aria-describedby="errors.top_n ? `${id}-top-error` : `${id}-top-hint`"
                    class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                    :class="errors.top_n ? 'border-danger' : 'border-line-strong'"
                    @input="setTopN"
                />
            </div>
        </div>

        <p v-if="model.mode !== 'none'" :id="`${id}-top-hint`" class="text-sm text-muted">
            Shows the {{ Number.isNaN(model.topN) ? 'top' : model.topN }} most frequent values (1 to {{ limits.max_top_n }}); the rest are grouped as Other.
        </p>
        <p v-if="errors.column" :id="`${id}-column-error`" role="alert" class="text-sm text-danger">{{ errors.column }}</p>
        <p v-if="errors.property" :id="`${id}-property-error`" role="alert" class="text-sm text-danger">{{ errors.property }}</p>
        <p v-if="errors.top_n" :id="`${id}-top-error`" role="alert" class="text-sm text-danger">{{ errors.top_n }}</p>
    </fieldset>
</template>
