<script setup>
import { computed, useId } from 'vue';

// The conversion window: how long a person has, counted from their first step, to finish the others. A whole number and a unit
// (the units come from the server). The number is a text field with a numeric keyboard rather than type="number": it holds
// exactly what was typed, so "1.5" or "" can be named in the message instead of silently turning into something else.
const props = defineProps({
    units: { type: Array, required: true },
    hint: { type: String, required: true },
    error: { type: String, default: undefined },
});

const model = defineModel({ type: Object, required: true });

const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;
const describedBy = computed(() => [hintId, props.error && errorId].filter(Boolean).join(' '));

const patch = (changes) => (model.value = { ...model.value, ...changes });
</script>

<template>
    <fieldset class="space-y-1.5">
        <legend class="block text-sm font-medium">Conversion window</legend>

        <div class="grid grid-cols-[6rem_minmax(0,1fr)] gap-2">
            <div>
                <label :for="`${id}-value`" class="sr-only">Window length</label>
                <input
                    :id="`${id}-value`"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    name="window_value"
                    data-field="window"
                    :value="model.value"
                    :aria-invalid="error ? 'true' : undefined"
                    :aria-describedby="describedBy"
                    class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                    :class="error ? 'border-danger' : 'border-line-strong'"
                    @input="patch({ value: $event.target.value })"
                />
            </div>
            <div>
                <label :for="`${id}-unit`" class="sr-only">Window unit</label>
                <select
                    :id="`${id}-unit`"
                    name="window_unit"
                    data-field="window"
                    :value="model.unit"
                    :aria-invalid="error ? 'true' : undefined"
                    :aria-describedby="describedBy"
                    class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                    :class="error ? 'border-danger' : 'border-line-strong'"
                    @change="patch({ unit: $event.target.value })"
                >
                    <option v-for="unit in units" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                </select>
            </div>
        </div>

        <p :id="hintId" class="text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" role="alert" class="text-sm text-danger">{{ error }}</p>
    </fieldset>
</template>
