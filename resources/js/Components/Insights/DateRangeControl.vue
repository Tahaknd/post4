<script setup>
import { computed, useId } from 'vue';

// The date range: a preset (resolved by the server to whole local days) or a custom range of two calendar days. The
// dates are native <input type="date"> values (YYYY-MM-DD, an empty string while unset). Both ends are INCLUSIVE days in
// the project's time zone, which is why the hint names it.
const props = defineProps({
    presets: { type: Array, required: true },
    timezone: { type: String, required: true },
    error: { type: String, default: undefined },
});

const model = defineModel({ type: Object, required: true });

const CUSTOM = 'custom';
const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;

const selected = computed({
    get: () => (model.value.mode === CUSTOM ? CUSTOM : model.value.preset),
    set: (value) => {
        model.value = value === CUSTOM ? { ...model.value, mode: CUSTOM } : { ...model.value, mode: 'preset', preset: value };
    },
});

function setDate(field, value) {
    model.value = { ...model.value, [field]: value };
}

const describedBy = computed(() => [hintId, props.error && errorId].filter(Boolean).join(' '));
</script>

<template>
    <fieldset class="space-y-1.5">
        <legend class="block text-sm font-medium">Date range</legend>

        <label :for="`${id}-preset`" class="sr-only">Date range preset</label>
        <select
            :id="`${id}-preset`"
            v-model="selected"
            name="date_range"
            :aria-invalid="error && model.mode !== 'custom' ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-fg"
        >
            <option v-for="preset in presets" :key="preset.value" :value="preset.value">{{ preset.label }}</option>
            <option :value="CUSTOM">Custom range</option>
        </select>

        <div v-if="model.mode === CUSTOM" class="grid grid-cols-1 gap-2 min-[420px]:grid-cols-2">
            <div class="space-y-1">
                <label :for="`${id}-from`" class="block text-xs text-muted">From</label>
                <input
                    :id="`${id}-from`"
                    type="date"
                    name="date_from"
                    :value="model.from"
                    :aria-invalid="error ? 'true' : undefined"
                    :aria-describedby="describedBy"
                    class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                    :class="error ? 'border-danger' : 'border-line-strong'"
                    @input="setDate('from', $event.target.value)"
                />
            </div>
            <div class="space-y-1">
                <label :for="`${id}-to`" class="block text-xs text-muted">To</label>
                <input
                    :id="`${id}-to`"
                    type="date"
                    name="date_to"
                    :value="model.to"
                    :aria-invalid="error ? 'true' : undefined"
                    :aria-describedby="describedBy"
                    class="block w-full rounded-md border bg-surface px-3 py-2 text-fg"
                    :class="error ? 'border-danger' : 'border-line-strong'"
                    @input="setDate('to', $event.target.value)"
                />
            </div>
        </div>

        <p :id="hintId" class="text-sm text-muted">Whole days in {{ timezone }}.</p>
        <p v-if="error" :id="errorId" role="alert" class="text-sm text-danger">{{ error }}</p>
    </fieldset>
</template>
