<script setup>
import { computed, useId } from 'vue';

// A labelled multi-line text input, wired like TextField: visible label, a validation message that is announced and
// referenced with aria-describedby, aria-invalid mirroring the error.
const props = defineProps({
    label: { type: String, required: true },
    name: { type: String, required: true },
    error: { type: String, default: undefined },
    hint: { type: String, default: undefined },
    rows: { type: Number, default: 3 },
});

const model = defineModel({ type: String, default: '' });

const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;

const describedBy = computed(() => [props.hint && hintId, props.error && errorId].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" class="block text-sm font-medium">{{ label }}</label>

        <textarea
            :id="id"
            v-model="model"
            :name="name"
            :rows="rows"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
            :class="error ? 'border-danger' : 'border-line-strong'"
        ></textarea>

        <p v-if="hint" :id="hintId" class="text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" role="alert" class="text-sm text-danger">{{ error }}</p>
    </div>
</template>
