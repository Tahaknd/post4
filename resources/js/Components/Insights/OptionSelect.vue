<script setup>
import { computed, useId } from 'vue';

// A labelled native <select> over `{ value, label }` options (the lists come from the server's page props). Wired like
// SelectField: visible label, hint and error referenced with aria-describedby, aria-invalid mirroring the error.
// `labelClass` lets a control hide its label visually (sr-only) when a row of controls carries the meaning.
const props = defineProps({
    label: { type: String, required: true },
    options: { type: Array, required: true },
    error: { type: String, default: undefined },
    hint: { type: String, default: undefined },
    labelClass: { type: String, default: 'block text-sm font-medium' },
    placeholder: { type: String, default: undefined },
    name: { type: String, default: undefined },
});

const model = defineModel({ type: String, default: '' });

const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;

const describedBy = computed(() => [props.hint && hintId, props.error && errorId].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" :class="labelClass">{{ label }}</label>

        <select
            :id="id"
            v-model="model"
            :name="name"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
            :class="error ? 'border-danger' : 'border-line-strong'"
        >
            <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <p v-if="hint" :id="hintId" class="text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" role="alert" class="text-sm text-danger">{{ error }}</p>
    </div>
</template>
