<script setup>
import { ref } from 'vue';

import { SecondaryButtonClass } from '@/Components/Insights/classes';

// The button row of an insight that runs on a button: Run, and while it runs Cancel and what the run is doing. Run is
// aria-disabled and never natively disabled (it keeps focus while a run is going on, and a refused press reveals what is
// wrong with the form). `hint` is the sentence under a refused Run, referenced by the button.
defineProps({
    label: { type: String, required: true },
    busy: { type: Boolean, required: true },
    valid: { type: Boolean, required: true },
    attempted: { type: Boolean, required: true },
    stageText: { type: String, required: true },
    hint: { type: String, required: true },
});

const emit = defineEmits(['cancel']);

const button = ref(null);

defineExpose({ focus: () => button.value?.focus() });
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <button
            ref="button"
            type="submit"
            class="inline-flex items-center justify-center rounded-md bg-accent px-4 py-2 font-medium text-accent-fg hover:bg-accent-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
            :aria-disabled="busy || !valid ? 'true' : undefined"
            :aria-describedby="attempted && !valid ? 'run-hint' : undefined"
        >
            {{ label }}
        </button>
        <button v-if="busy" type="button" :class="SecondaryButtonClass" @click="emit('cancel')">Cancel run</button>
        <p v-if="busy" class="text-sm text-muted" aria-hidden="true" data-testid="status-visible">{{ stageText }}</p>
        <p v-else-if="attempted && !valid" id="run-hint" class="text-sm text-danger" data-testid="run-hint">{{ hint }}</p>
    </div>
</template>
