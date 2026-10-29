<script setup>
import { computed, useId } from 'vue';

// How many periods after the cohort period the table follows each cohort: a whole number from the server's minimum to its
// maximum. A text field with a numeric keyboard rather than type="number" (as the conversion window is): it holds exactly what
// was typed, so "1.5" or "" can be named in the message instead of silently turning into something else.
const props = defineProps({
    hint: { type: String, required: true },
    error: { type: String, default: undefined },
});

const model = defineModel({ type: String, required: true });

const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;
const describedBy = computed(() => [hintId, props.error && errorId].filter(Boolean).join(' '));
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" class="block text-sm font-medium">Number of periods</label>
        <input
            :id="id"
            v-model="model"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            name="periods"
            data-field="periods"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
            :class="error ? 'border-danger' : 'border-line-strong'"
        />
        <p :id="hintId" class="text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" role="alert" class="text-sm text-danger">{{ error }}</p>
    </div>
</template>
