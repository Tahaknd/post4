<script setup>
import { computed, useId } from 'vue';

// A labelled text input for forms. The label is always visible and tied to the input, a validation
// message is announced and referenced with aria-describedby, and aria-invalid mirrors the error.
const props = defineProps({
    label: { type: String, required: true },
    name: { type: String, required: true },
    type: { type: String, default: 'text' },
    // The HTML autocomplete token (username, email, current-password, new-password, name): password
    // managers and browsers rely on it.
    autocomplete: { type: String, default: undefined },
    error: { type: String, default: undefined },
    hint: { type: String, default: undefined },
    required: { type: Boolean, default: false },
    autofocus: { type: Boolean, default: false },
});

const model = defineModel({ type: String, default: '' });

const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;

const describedBy = computed(() => [props.hint && hintId, props.error && errorId].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" class="block text-sm font-medium">
            {{ label }}
            <span v-if="required" class="text-muted" aria-hidden="true">*</span>
        </label>

        <input
            :id="id"
            v-model="model"
            :name="name"
            :type="type"
            :autocomplete="autocomplete"
            :required="required"
            :autofocus="autofocus"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
            :class="error ? 'border-danger' : 'border-line-strong'"
        />

        <p v-if="hint" :id="hintId" class="text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" role="alert" class="text-sm text-danger">{{ error }}</p>
    </div>
</template>
