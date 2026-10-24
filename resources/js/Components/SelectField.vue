<script setup>
import { computed, useId } from 'vue';

// A labelled native <select>, wired like TextField: visible label, a validation message that is announced and
// referenced with aria-describedby, aria-invalid mirroring the error. `groups` is a list of
// { label, options: string[] }; options are plain strings (value and text are the same).
const props = defineProps({
    label: { type: String, required: true },
    name: { type: String, required: true },
    groups: { type: Array, required: true },
    error: { type: String, default: undefined },
    hint: { type: String, default: undefined },
    required: { type: Boolean, default: false },
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

        <select
            :id="id"
            v-model="model"
            :name="name"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
            :class="error ? 'border-danger' : 'border-line-strong'"
        >
            <optgroup v-for="group in groups" :key="group.label" :label="group.label">
                <option v-for="option in group.options" :key="option" :value="option">{{ option }}</option>
            </optgroup>
        </select>

        <p v-if="hint" :id="hintId" class="text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" role="alert" class="text-sm text-danger">{{ error }}</p>
    </div>
</template>
